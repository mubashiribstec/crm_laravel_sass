<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Sale;
use Horsefly\SaleNote;
use Horsefly\CrmRejectedCv;
use Horsefly\CVNote;
use Horsefly\CrmNote;
use Horsefly\History;
use Horsefly\QualityNotes;
use Horsefly\Applicant;
use Horsefly\EmailTemplate;
use Horsefly\Notification;
use Horsefly\Setting;
use Horsefly\Message;
use Horsefly\User;
use Horsefly\Interview;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\SentEmail;
use Horsefly\RevertStage;
use Horsefly\ModuleNote;
use Horsefly\ClearedRevertCv;

use App\Support\DialLink;

use App\Observers\ActionObserver;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Exceptions\ActionRefusedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

use Exception;
use Carbon\Carbon;
use Horsefly\SmsTemplate;
use Illuminate\Support\Str;
use App\Traits\SendEmails;
use App\Traits\SendSMS;
use App\Traits\HidesPrivateContacts;
use Illuminate\Support\Facades\Gate;
// use Illuminate\Http\JsonResponse;

class CrmController extends Controller
{
    /**
     * Permission checks cached for this request only (P-04): the CRM tables checked the same few
     * abilities for every row. They are Spatie permissions, which depend only on the user. Kept on
     * the request, not the controller: the router reuses controller instances between requests.
     */
    private function can(string $ability): bool
    {
        $cache = request()->attributes->get('crm.abilities', []);
        if (!array_key_exists($ability, $cache)) {
            $cache[$ability] = Gate::allows($ability);
            request()->attributes->set('crm.abilities', $cache);
        }

        return $cache[$ability];
    }

    /**
     * Every CRM screen and action needs CRM access (crm-index). Before, the controller had no
     * permission checks at all, so any logged-in account could move candidates through the
     * pipeline, including invoice -> paid. Money-path actions also need the permission their
     * button already checks. Notes history is also reachable from other screens, so it
     * accepts the notes permissions too.
     */
    public function __construct()
    {
        $history = ['crmNotesHistoryIndex', 'getApplicantCrmNotesHistoryAjaxRequest', 'getApplicantCrmNotes'];
        $this->middleware('permission:crm-index')->except($history);
        $this->middleware('permission:crm-index,crm-view-notes-history,crm-view-note')->only($history);
        $this->middleware('permission:crm-accept-invoice')
            ->only(['crmSendInvoiceToInvoiceSent', 'crmInvoiceToDispute', 'crmInvoiceFinalSave']);
        $this->middleware('permission:crm-accept-invoice-sent')->only(['crmInvoiceSentToPaid', 'crmInvoiceSentToDispute']);
        $this->middleware('permission:crm-paid-toggle-status')->only(['crmChangePaidStatus', 'crmOpenToPaidApplicants']);
        $this->middleware('permission:crm-paid-revert')->only(['crmPaidRevertToInvoiceSent']);
    }

    use SendEmails, SendSMS, HidesPrivateContacts;

    public function index()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('crm.list', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function crmNotesHistoryIndex($applicant_id, $sale_id)
    {
        // Fetch the applicant or fail with a 404 if not found
        $applicant = Applicant::findOrFail($applicant_id);

        // Get the latest CV Note for this applicant and sale
        $cv_notes = CVNote::where('applicant_id', $applicant_id)
            ->where('sale_id', $sale_id)
            ->latest()
            ->first();

        // Get the latest Quality Note for this applicant and sale
        $quality_notes = QualityNotes::where('applicant_id', $applicant_id)
            ->where('sale_id', $sale_id)
            ->latest()
            ->first();

        // Return the view with all required data
        return view('crm.notes-history', compact('applicant_id', 'sale_id', 'applicant', 'cv_notes', 'quality_notes'));
    }
    public function getCrmApplicantsAjaxRequest(Request $request)
    {
        $typeFilter = $request->input('type_filter', '');
        $categoryFilter = $request->input('category_filter', []);
        $titleFilter = $request->input('title_filter', []);
        $sourceFilter = $request->input('source_filter', []);
        $tabFilter = $request->input('tab_filter', '');
        $date_range_filter = $request->input('date_range_filter', '');

        // DataTables / broken clients can send a scalar instead of an array.
        // whereIn() requires an array — a string triggers count() TypeError.
        $categoryFilter = array_values(array_filter((array) $categoryFilter, fn($v) => $v !== '' && $v !== null));
        $titleFilter = array_values(array_filter((array) $titleFilter, fn($v) => $v !== '' && $v !== null));
        $sourceFilter = array_values(array_filter((array) $sourceFilter, fn($v) => $v !== '' && $v !== null));

        // Base query with minimal selected columns and eager loading
        $model = Applicant::query()
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.applicant_email',
                'applicants.applicant_email_secondary',
                'applicants.job_type',
                'applicants.updated_at',
                'applicants.applicant_postcode',
                'applicants.paid_status',
                'applicants.applicant_phone',
                'applicants.applicant_phone_secondary',
                'applicants.applicant_landline',
                'applicants.paid_timestamp',
                'applicants.lat',
                'applicants.lng',
                'job_titles.name as jobTitle',
                'job_categories.name as jobCategory',
                'job_sources.name as jobSource',
            ])
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->leftJoin('job_titles', fn($join) => $join->on('applicants.job_title_id', '=', 'job_titles.id'))
            ->leftJoin('job_categories', fn($join) => $join->on('applicants.job_category_id', '=', 'job_categories.id'))
            ->leftJoin('job_sources', fn($join) => $join->on('applicants.job_source_id', '=', 'job_sources.id'))
            // Joined once so free-text search can hit owner name without a missing-column error.
            ->leftJoin('users as applicant_owner_users', fn($join) => $join->on('applicants.user_id', '=', 'applicant_owner_users.id'));

        // Apply tab filter logic (optimized with DB::raw)
        switch ($tabFilter) {
            case 'open cvs':
                $model->leftJoinSub(
                    DB::table('cv_notes')
                        ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('cv_notes')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'cv_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id');
                    }
                )
                    ->joinSub(
                        DB::table('revert_stages')
                            ->select('applicant_id', 'sale_id', 'user_id', 'notes', 'stage', 'updated_at')
                            ->whereIn('stage', ['quality_note', 'cv_hold', 'no_job_quality_cvs'])
                            ->whereIn(
                                'id',
                                fn($subQuery) =>
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('revert_stages')
                                    ->whereIn('stage', ['quality_note', 'cv_hold', 'no_job_quality_cvs'])
                                    ->groupBy('applicant_id', 'sale_id')
                            ),
                        'revert_stages',
                        fn($join) => $join->on('revert_stages.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('revert_stages.sale_id', '=', 'cv_notes.sale_id')
                    )
                    ->join(
                        'sales',
                        fn($join) =>
                        $join->on('cv_notes.sale_id', '=', 'sales.id')
                            ->where('sales.status', 1)
                            ->whereNull('sales.deleted_at')
                    )
                    ->join(
                        'offices',
                        fn($join) =>
                        $join->on('sales.office_id', '=', 'offices.id')
                            ->where('offices.status', 1)
                    )
                    ->join(
                        'units',
                        fn($join) =>
                        $join->on('sales.unit_id', '=', 'units.id')
                            ->where('units.status', 1)
                    )
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'cv_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'cv_notes.sale_id')
                            ->whereIn('history.sub_stage', ['quality_cvs_hold'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('cv_notes.applicant_id', '=', 'interviews.applicant_id');
                        $join->on('cv_notes.sale_id', '=', 'interviews.sale_id');
                        $join->where('interviews.status', 1);
                    })
                    ->leftJoin('users', 'users.id', '=', 'revert_stages.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        //office
                        'offices.office_name as office_name',
                        // show created date
                        'revert_stages.updated_at as show_created_at',
                        //sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        //units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        //revert stages
                        'revert_stages.user_id as revert_user_id',
                        'revert_stages.notes as notes_detail',
                        'revert_stages.stage as revert_stage',
                        'revert_stages.updated_at as notes_created_at',
                        //user
                        'users.name as user_name',
                        //interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                    ]);
                break;

            case 'sent cvs (no job)':
                // Subquery: latest CRM note per applicant-sale (for details)
                // $latestCrmNotes = DB::table('crm_notes as cn_latest')
                //     ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                //     ->where('cn_latest.status', 1)
                //     ->whereIn("cn_latest.moved_tab_to", ["cv_sent_no_job"])
                //     ->whereIn('cn_latest.id', function ($q) {
                //         $q->selectRaw('MAX(id)')
                //             ->from('crm_notes')
                //             ->where('status', 1)
                //             ->whereIn("moved_tab_to", ["cv_sent_no_job"])
                //             ->groupBy('applicant_id', 'sale_id');
                //     });

                $model->joinSub(
                    DB::table('quality_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to', ['cleared', 'cleared_no_job'])
                        ->where('status', 1)
                        ->whereIn('id', function ($query) {
                            $query->select(DB::raw('MAX(id)'))
                                ->from('quality_notes')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'quality_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'quality_notes.applicant_id');
                    }
                )
                    ->join('sales', function ($join) {
                        $join->on('quality_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'quality_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'quality_notes.sale_id')
                            ->whereIn('history.sub_stage', ['quality_cleared_no_job'])
                            ->where('history.status', 1);
                    })
                    ->joinSub(
                        DB::table('crm_notes')
                            ->select('applicant_id', 'sale_id', 'details', 'created_at')
                            // ->where('status', 1)
                            ->whereIn('moved_tab_to', ['cv_sent_no_job'])
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('crm_notes')
                                    ->where('status', 1)
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'crm_notes',
                        function ($join) {
                            $join->on('quality_notes.applicant_id', '=', 'crm_notes.applicant_id')
                                ->on('quality_notes.sale_id', '=', 'crm_notes.sale_id');
                        }
                    )
                    ->leftJoin('interviews', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'interviews.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->leftJoin('users', 'cv_notes.user_id', '=', 'users.id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Quality Notes
                        'crm_notes.details as notes_detail',
                        'crm_notes.created_at as notes_created_at',
                        // show created date
                        'quality_notes.created_at as show_created_at',
                        // Offices
                        'offices.office_name as office_name',
                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // User
                        'users.name as user_name',
                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                    ]);
                break;

            case 'rejected cvs':
                // Subquery to get latest CRM notes (rejected)
                $crmNotesSubQuery = DB::table('crm_notes as cn1')
                    ->select('cn1.*')
                    ->whereIn('cn1.moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
                    ->whereIn('cn1.id', function ($q) {
                        $q->select(DB::raw('MIN(id)'))
                            ->from('crm_notes')
                            ->whereIn('moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    // ->where('cn_latest.status', 1)
                    ->whereIn('cn_latest.moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            // ->where('status', 1)
                            ->whereIn('moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery to get latest CV notes
                $cvNotesSubQuery = DB::table('cv_notes as cv1')
                    ->select('cv1.*')
                    ->whereIn('cv1.id', function ($q) {
                        $q->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Main query
                $model->joinSub($crmNotesSubQuery, 'crm_last_notes', function ($join) {
                    $join->on('applicants.id', '=', 'crm_last_notes.applicant_id');
                })
                    ->join('sales', function ($join) {
                        $join->on('crm_last_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_last_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_last_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_reject', 'crm_no_job_reject'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub($cvNotesSubQuery, 'cv_last_notes', function ($join) {
                        $join->on('crm_last_notes.applicant_id', '=', 'cv_last_notes.applicant_id')
                            ->on('crm_last_notes.sale_id', '=', 'cv_last_notes.sale_id');
                    })
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_last_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Applicants
                        'applicants.id as applicant_id',

                        // CRM Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',

                        // show created date
                        'crm_last_notes.created_at as show_created_at',

                        // Offices
                        'offices.office_name',

                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        // Users
                        'users.name as user_name',
                    ]);

                break;
            case 'request':
                // Subquery to get the latest crm_notes per applicant_id and sale_id
                $crmNotesSubQuery = DB::table('crm_notes')
                    ->select('applicant_id', 'sale_id', 'details', 'created_at')
                    ->whereIn('moved_tab_to', ["cv_sent_request"])
                    ->where('status', 1)
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MIN(id)'))
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn('moved_tab_to', ["cv_sent_request"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery for latest cv_notes per applicant_id and sale_id
                $cvNotesSubQuery = DB::table('cv_notes')
                    ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["cv_sent_request", "request_save"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn("moved_tab_to", ["cv_sent_request", "request_save"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Build the main query
                $model->joinSub($crmNotesSubQuery, 'crm_notes', function ($join) {
                    $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                })
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_request', 'crm_request_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub($cvNotesSubQuery, 'cv_notes', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                    })
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Applicants
                        'applicants.id as applicant_id',

                        // CRM Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',

                        // FIRST CRM NOTE DATE
                        'crm_notes.created_at as show_created_at',

                        // Offices
                        'offices.office_name',
                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // Users
                        'users.name as user_name',
                    ]);

                break;
            case 'request (no response)':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn('cn_latest.moved_tab_to', ['request_no_response'])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn('moved_tab_to', ['request_no_response'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to', ['request_no_response'])
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MIN(id)'))
                                ->from('crm_notes')
                                ->whereIn('moved_tab_to', ['request_no_response'])
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'crm_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    }
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_request_no_response'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',

                        // show created date
                        'crm_notes.created_at as show_created_at',

                        // interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        // offices
                        'offices.office_name as office_name',

                        // sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        // users
                        'users.name as user_name',
                    ]);
                break;
            case 'request (no job)':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn('cn_latest.moved_tab_to', ['cv_sent_no_job_request', 'request_no_job_save'])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn('moved_tab_to', ['cv_sent_no_job_request', 'request_no_job_save'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to', ['cv_sent_no_job_request'])
                        ->where('status', 1)
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MIN(id)'))
                                ->from('crm_notes')
                                ->where('status', 1)
                                ->whereIn('moved_tab_to', ['cv_sent_no_job_request'])
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'crm_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    }
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_no_job_request', 'crm_request_no_job_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',

                        // show created date
                        'crm_notes.created_at as show_created_at',

                        // interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        // offices
                        'offices.office_name as office_name',

                        // sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        // users
                        'users.name as user_name',
                    ]);
                break;

            case 'rejected by request':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    // ->where('cn_latest.status', 1)
                    ->whereIn('cn_latest.moved_tab_to', ['request_reject', 'request_no_job_reject'])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            // ->where('status', 1)
                            ->whereIn('moved_tab_to', ['request_reject', 'request_no_job_reject'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery for latest crm_notes
                $crmNotesSubQuery = DB::table('crm_notes')
                    ->select('applicant_id', 'sale_id', 'details', 'created_at')
                    ->whereIn('moved_tab_to', ['request_reject', 'request_no_job_reject'])
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('crm_notes')
                            ->whereIn('moved_tab_to', ['request_reject', 'request_no_job_reject'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery for latest cv_notes
                $cvNotesSubQuery = DB::table('cv_notes')
                    ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    $crmNotesSubQuery,
                    'latest_crm_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'latest_crm_notes.applicant_id');
                    }
                )
                    ->join('sales', function ($join) {
                        $join->on('latest_crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'latest_crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'latest_crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_request_reject', 'crm_request_no_job_reject'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        $cvNotesSubQuery,
                        'latest_cv_notes',
                        function ($join) {
                            $join->on('latest_crm_notes.applicant_id', '=', 'latest_cv_notes.applicant_id')
                                ->on('latest_crm_notes.sale_id', '=', 'latest_cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'latest_cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Applicants
                        'applicants.id as applicant_id',
                        // CRM Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',

                        // show created date
                        'latest_crm_notes.created_at as show_created_at',
                        // Offices
                        'offices.office_name',
                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // Users
                        'users.name as user_name',
                    ]);

                break;
            case 'confirmation':
                // Define reusable subqueries with MAX(id) grouped — using joinSub with indexes
                $crmNotesSubQuery = DB::table('crm_notes as cn1')
                    ->join(
                        DB::raw('(
                            SELECT MIN(id) as id
                            FROM crm_notes
                            WHERE status = 1 AND moved_tab_to IN ("request_confirm", "request_no_job_confirm")
                            GROUP BY applicant_id, sale_id
                        ) as first_cn'),
                        'cn1.id',
                        '=',
                        'first_cn.id'
                    )
                    ->select(
                        'cn1.id',
                        'cn1.applicant_id',
                        'cn1.sale_id',
                        'cn1.details',
                        'cn1.created_at',
                        'cn1.moved_tab_to'
                    )
                    ->where('cn1.status', 1)
                    ->whereIn('cn1.moved_tab_to', [
                        'request_confirm',
                        'request_no_job_confirm'
                    ]);

                $latestCvNotes = DB::table('cv_notes as cv1')
                    ->select('cv1.applicant_id', 'cv1.sale_id', 'cv1.user_id', 'cv1.status', 'cv1.created_at')
                    ->join(
                        DB::raw('(SELECT MAX(id) as id FROM cv_notes GROUP BY applicant_id, sale_id) as latest_cv'),
                        'cv1.id',
                        '=',
                        'latest_cv.id'
                    );

                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["request_confirm", "interview_save", "request_no_job_confirm"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->whereIn("moved_tab_to", ["request_confirm", "interview_save", "request_no_job_confirm"])
                            ->where('status', 1)
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model
                    ->joinSub($crmNotesSubQuery, 'crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    })
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', [
                                'crm_request_confirm',
                                'crm_interview_save',
                                'crm_request_no_job_confirm'
                            ])
                            ->where('history.status', 1);
                    })
                    ->join('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub($latestCvNotes, 'cv_notes', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                    })
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',

                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // Offices
                        'offices.office_name as office_name',

                        // Sale details
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // Unit details
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        // Interview details
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        // CV note user
                        'users.name as user_name',
                    ]);

                if ($date_range_filter) {
                    // Parse the date range filter (format: "YYYY-MM-DD|YYYY-MM-DD")
                    [$start_date, $end_date] = explode('|', $date_range_filter);
                    $start_date = trim($start_date);
                    $end_date = trim($end_date);

                    $model->where(function ($query) use ($start_date, $end_date) {
                        $query->whereBetween('interviews.schedule_date', [$start_date, $end_date]);
                    });
                }
                break;
            case 'rebook':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["rebook", "rebook_save"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn("crm_notes.moved_tab_to", ["rebook", "rebook_save"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to',  ["rebook"])
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->whereIn('moved_tab_to',  ["rebook"])
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_rebook', 'crm_rebook_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id');
                        $join->on('sales.id', '=', 'interviews.sale_id');
                        $join->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // Sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // users
                        'users.name as user_name'
                    ]);

                break;

            case 'attended to pre-start date':
                // Subquery to get the latest crm_notes per applicant_id and sale_id
                $crmNotesSubQuery = DB::table('crm_notes')
                    ->select('applicant_id', 'sale_id', 'details', 'created_at')
                    ->whereIn('moved_tab_to', ["interview_attended"])
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('crm_notes')
                            ->whereIn('moved_tab_to', ["interview_attended"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["interview_attended", "prestart_save"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn("moved_tab_to", ["interview_attended", "prestart_save"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery for latest cv_notes per applicant_id and sale_id
                $cvNotesSubQuery = DB::table('cv_notes')
                    ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Build the main query
                $model->joinSub($crmNotesSubQuery, 'crm_notes', function ($join) {
                    $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                })
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_interview_attended', 'crm_prestart_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub($cvNotesSubQuery, 'cv_notes', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                    })
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Applicants
                        'applicants.id as applicant_id',
                        // CRM Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // Offices
                        'offices.office_name',
                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // Users
                        'users.name as user_name',
                    ]);

                break;
            case 'declined':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->whereIn("cn_latest.moved_tab_to", ["declined"])
                    // ->where('cn_latest.status', 1)
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            // ->where('status', 1)
                            ->whereIn("moved_tab_to", ["declined"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->where('moved_tab_to', 'declined')
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->where('moved_tab_to', 'declined')
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->where('history.sub_stage', 'crm_declined')
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        'users.name as user_name',
                    ]);

                break;
            case 'not attended':
                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to', ["interview_not_attended"])
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->whereIn('moved_tab_to', ["interview_not_attended"])
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_interview_not_attended'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id');
                        $join->on('sales.id', '=', 'interviews.sale_id');
                        $join->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'crm_notes.details as notes_detail',
                        'crm_notes.created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        'users.name as user_name'
                    ]);
                break;
            case 'start date':
                // Subquery to get the latest crm_notes per applicant_id and sale_id
                $crmNotesSubQuery = DB::table('crm_notes')
                    ->select('applicant_id', 'sale_id', 'details', 'created_at')
                    ->whereIn("moved_tab_to", ["start_date"])
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('crm_notes')
                            ->whereIn("moved_tab_to", ["start_date"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["start_date", "start_date_save", "start_date_back"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->whereIn("moved_tab_to", ["start_date", "start_date_save", "start_date_back"])
                            ->where('status', 1)
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Subquery for latest cv_notes per applicant_id and sale_id
                $cvNotesSubQuery = DB::table('cv_notes')
                    ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Build the main query
                $model->joinSub($crmNotesSubQuery, 'crm_notes', function ($join) {
                    $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                })
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_start_date', 'crm_start_date_save', 'crm_start_date_back'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub($cvNotesSubQuery, 'cv_notes', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Applicants
                        'applicants.id as applicant_id',
                        // CRM Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // Offices
                        'offices.office_name',
                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // Users
                        'users.name as user_name',
                    ]);

                break;
            case 'start date hold':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    // ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["start_date_hold", "start_date_hold_save"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            // ->where('status', 1)
                            ->whereIn("moved_tab_to", ["start_date_hold", "start_date_hold_save"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn("moved_tab_to", ["start_date_hold"])
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->whereIn("moved_tab_to", ["start_date_hold"])
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_start_date_hold', 'crm_start_date_hold_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id');
                        $join->on('sales.id', '=', 'interviews.sale_id');
                        $join->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        'users.name as user_name'
                    ]);

                break;
            case 'invoice':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["invoice", "final_save"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn("moved_tab_to", ["invoice", "final_save"])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn("moved_tab_to", ["invoice"])
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->whereIn("moved_tab_to", ["invoice"])
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_invoice', 'crm_final_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id');
                        $join->on('sales.id', '=', 'interviews.sale_id');
                        $join->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        'users.name as user_name'
                    ]);

                break;
            case 'invoice sent':
                // Subquery: latest CRM note per applicant-sale (for details)
                $latestCrmNotes = DB::table('crm_notes as cn_latest')
                    ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
                    ->where('cn_latest.status', 1)
                    ->whereIn("cn_latest.moved_tab_to", ["invoice_sent", "final_save"])
                    ->whereIn('cn_latest.id', function ($q) {
                        $q->selectRaw('MAX(id)')
                            ->from('crm_notes')
                            ->whereIn("moved_tab_to", ["invoice_sent", "final_save"])
                            ->where('status', 1)
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to', ["invoice_sent"])
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->whereIn('moved_tab_to', ["invoice_sent"])
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_invoice_sent', 'crm_final_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id');
                        $join->on('sales.id', '=', 'interviews.sale_id');
                        $join->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'applicants.id')
                            ->on('latest_crm.sale_id', '=', 'sales.id');
                    })
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // Crm Notes
                        'latest_crm.latest_details as notes_detail',
                        'latest_crm.latest_created_at as notes_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',

                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',

                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',

                        'users.name as user_name'
                    ]);

                break;
            case 'dispute':
                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->whereIn('moved_tab_to', ['dispute'])
                        ->whereIn(
                            'id',
                            fn($subQuery) =>
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->whereIn('moved_tab_to', ['dispute'])
                                ->groupBy('applicant_id', 'sale_id')
                        ),
                    'crm_notes',
                    fn($join) => $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->whereIn('history.sub_stage', ['crm_dispute'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // Crm Notes
                        'crm_notes.details as notes_detail',
                        'crm_notes.created_at as notes_created_at',
                        // offices
                        'offices.office_name as office_name',
                        // sale
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // interview
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // user
                        'users.name as user_name'
                    ]);
                break;
            case 'paid':
                $model->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->where('moved_tab_to', 'paid')
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->where('moved_tab_to', 'paid')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'crm_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    }
                )
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                            ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                            ->where('history.sub_stage', 'crm_paid')
                            ->where('history.status', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->leftJoinSub(
                        DB::table('cv_notes')
                            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                            ->whereIn('id', function ($subQuery) {
                                $subQuery->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'cv_notes',
                        function ($join) {
                            $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                                ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                        }
                    )
                    ->leftJoin('users', 'users.id', '=', 'cv_notes.user_id')
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // show created date
                        'crm_notes.created_at as show_created_at',
                        // Crm Notes
                        'crm_notes.details as notes_detail',
                        'crm_notes.created_at as notes_created_at',
                        // Offices
                        'offices.office_name as office_name',
                        // Sale fields
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Unit fields
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // Interview fields
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // User field
                        'users.name as user_name'
                    ]);

                break;
            default:
            case 'sent cvs':
                // Derived table for latest quality_notes (uncorrelated, runs once)
                $latestQuality = DB::table('quality_notes')
                    ->select('applicant_id', 'sale_id', 'details', 'created_at', 'id')
                    ->where('status', 1)
                    ->whereIn('moved_tab_to', ['cleared'])
                    ->whereIn('id', function ($sub) {
                        $sub->select(DB::raw('MAX(id)'))
                            ->from('quality_notes')
                            ->where('status', 1)
                            ->whereIn('moved_tab_to', ['cleared'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Derived table for latest crm_notes
                $latestCrm = DB::table('crm_notes')
                    ->select('applicant_id', 'sale_id', 'details', 'created_at', 'id')
                    ->where('status', 1)
                    ->whereIn('moved_tab_to', ['cv_sent', 'cv_sent_saved'])
                    ->whereIn('id', function ($sub) {
                        $sub->select(DB::raw('MAX(id)'))
                            ->from('crm_notes')
                            ->where('status', 1)
                            ->whereIn('moved_tab_to', ['cv_sent', 'cv_sent_saved'])
                            ->groupBy('applicant_id', 'sale_id');
                    });

                // Derived table for latest cv_notes (if needed for user_name)
                $latestCv = DB::table('cv_notes')
                    ->select('applicant_id', 'sale_id', 'user_id', 'id', 'updated_at')
                    ->whereIn('id', function ($sub) {
                        $sub->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                $model->joinSub($latestQuality, 'quality_notes', function ($join) {
                    $join->on('applicants.id', '=', 'quality_notes.applicant_id');
                })
                    ->joinSub($latestCrm, 'crm_notes', function ($join) {
                        $join->on('quality_notes.applicant_id', '=', 'crm_notes.applicant_id')
                            ->on('quality_notes.sale_id', '=', 'crm_notes.sale_id');
                    })
                    ->join('sales', function ($join) {
                        $join->on('crm_notes.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->join('offices', function ($join) {
                        $join->on('sales.office_id', '=', 'offices.id');
                        // ->where('offices.status', 1);
                    })
                    ->join('units', function ($join) {
                        $join->on('sales.unit_id', '=', 'units.id');
                        // ->where('units.status', 1);
                    })
                    ->leftJoinSub($latestCv, 'cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->on('sales.id', '=', 'cv_notes.sale_id');
                    })
                    ->leftJoin('users', function ($join) {
                        $join->on('cv_notes.user_id', '=', 'users.id');
                        // ->where('users.is_active', 1);
                    })
                    ->leftJoin('interviews', function ($join) {
                        $join->on('applicants.id', '=', 'interviews.applicant_id')
                            ->on('sales.id', '=', 'interviews.sale_id')
                            ->where('interviews.status', 1);
                    })
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('history')
                            ->whereColumn('history.applicant_id', 'applicants.id')
                            ->whereColumn('history.sale_id', 'sales.id')
                            ->whereIn('history.sub_stage', ['quality_cleared', 'crm_save'])
                            ->where('history.status', 1);
                    })
                    ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
                    ->addSelect([
                        // CRM Notes
                        'crm_notes.details as notes_detail',
                        'crm_notes.created_at as notes_created_at',
                        // Quality Notes
                        'quality_notes.created_at as show_created_at',
                        // Offices
                        'offices.office_name',
                        // Sales
                        'sales.id as sale_id',
                        'sales.job_category_id as sale_category_id',
                        'sales.job_title_id as sale_title_id',
                        'sales.job_source_id as sale_source_id',
                        'sale_job_sources.name as sale_source_name',
                        'sales.sale_postcode',
                        'sales.job_type as sale_job_type',
                        'sales.timing',
                        'sales.salary',
                        'sales.experience as sale_experience',
                        'sales.qualification as sale_qualification',
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',
                        'sales.created_at as sale_posted_date',
                        // Units
                        'units.unit_name',
                        'units.unit_postcode',
                        'units.unit_website',
                        // Interviews
                        'interviews.schedule_time',
                        'interviews.schedule_date',
                        'interviews.status as interview_status',
                        // Users
                        'users.name as user_name',
                    ]);
                break;
        }

        // Every tab selects its notes as "<column> as notes_detail", so filter on that column.
        $this->excludePrivateSelectedNotes($model, 'notes_detail');
        
        // Every tab joins offices and units; only joined tables are filtered, so an unknown tab can't break the query.
        $this->excludePrivateOfficeUnitNotes($model);

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // Apply other filters (typeFilter, categoryFilter, titleFilter)
        if ($typeFilter) {
            $model->where('applicants.job_type', $typeFilter);
        }
        if ($categoryFilter !== []) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }
        if ($titleFilter !== []) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }
        // Job source filter (sale source OR applicant source)
        if ($sourceFilter !== []) {
            $model->where(function ($q) use ($sourceFilter) {
                $q->whereIn('sales.job_source_id', $sourceFilter)
                    ->orWhereIn('applicants.job_source_id', $sourceFilter);
            });
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');
            if (!empty($searchTerm)) {
                $lowerSearchTerm = strtolower($searchTerm); // Convert search term to lowercase 
                $model->where(function ($query) use ($lowerSearchTerm) { // Direct column searches with LOWER 
                    $query->whereRaw('LOWER(applicants.applicant_name) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_email) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_email_secondary) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_postcode) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_phone) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_phone_secondary) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_experience) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicants.applicant_landline) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(sales.sale_postcode) LIKE ?', ["%{$lowerSearchTerm}%"]);
                    // Only search columns that are always joined (base + sales/offices from every tab).
                    // Do NOT reference sale_job_sources / users here — those joins are tab-specific.
                    $query->orWhereRaw('LOWER(job_titles.name) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(job_categories.name) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(job_sources.name) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(offices.office_name) LIKE ?', ["%{$lowerSearchTerm}%"])
                        ->orWhereRaw('LOWER(applicant_owner_users.name) LIKE ?', ["%{$lowerSearchTerm}%"]);
                });
            }
        }

        // Sorting logic — deferred to DataTables' ->order() callback below so the
        // ORDER BY clause is not baked into the filtered-count query (it only needs
        // to apply to the final, paginated result set).
        $applySorting = function ($query) use ($request) {
            if ($request->has('order')) {
                $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
                $orderDirection = $request->input('order.0.dir', 'asc');
                if ($orderColumn == 'job_source') {
                    $query->orderBy('applicants.job_source_id', $orderDirection);
                } elseif ($orderColumn == 'job_category') {
                    $query->orderBy('applicants.job_category_id', $orderDirection);
                } elseif ($orderColumn == 'job_title') {
                    $query->orderBy('applicants.job_title_id', $orderDirection);
                } elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                    $query->orderBy($orderColumn, $orderDirection);
                } else {
                    $query->orderBy('show_created_at', 'desc');
                }
            } else {
                $query->orderBy('show_created_at', 'desc');
            }
        };

        if ($request->ajax()) {
            // Small reference/lookup tables and request-wide constants used inside the
            // per-row column closures below. Fetching them once here (instead of once
            // per row inside addColumn) avoids running the same handful of queries
            // dozens of times per page load.
            $jobTitleNamesById = JobTitle::pluck('name', 'id');
            $jobCategoryNamesById = JobCategory::pluck('name', 'id');

            $smsTemplateCrmSendRequest = SmsTemplate::where('slug', 'crm_send_request')
                ->where('status', 1)
                ->first();
            $smsNotificationSetting = Setting::where('key', 'sms_notifications')->first();
            $emailNotificationSetting = Setting::where('key', 'email_notifications')->first();
            $emailTemplateRequestConfiguration = EmailTemplate::where('slug', 'request_configuration_email')->where('is_active', 1)->first();
            $emailTemplateRequestRejected = EmailTemplate::where('slug', 'request_rejected')->where('is_active', 1)->first();

            return DataTables::eloquent($model)
                ->skipTotalRecords()
                // The filtered-count query wraps the whole query in a derived table. Counting
                // only needs the rows, not the ~45 selected columns (several TEXT), so select
                // `1` there instead. Safe because the outer query has no DISTINCT / GROUP BY.
                ->ignoreSelectsInCountQuery()
                ->order($applySorting)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn("user_name", function ($applicant) {
                    return $applicant->user_name ? ucwords($applicant->user_name) : '-';
                })
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle) : '-';
                })
                ->editColumn('applicant_email', function ($applicant) {
                    $email = '';
                    if ($applicant->applicant_email_secondary) {
                        $email = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary;
                    } else {
                        $email = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $applicant->applicant_email;
                    }

                    return $email; // Using accessor
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;

                    $stype = $type === 'specialist'
                        ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                        : '';

                    if (!$sale->jobCategory) {
                        return '-';
                    }

                    return e($sale->jobCategory) . $stype;
                })
                ->addColumn('job_source', function ($applicant) {
                    return $applicant->jobSource ? ucwords($applicant->jobSource) : '-';
                })
                ->addColumn('sale_source_name', function ($sale) {
                    if (!$sale->sale_source_name)
                        return '-';
                    return '<span class="badge bg-light text-dark">' . e($sale->sale_source_name) . '</span>';
                })
                ->addColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->addColumn('applicant_postcode', function ($applicant) {
                    if ($applicant->lat != null && $applicant->lng != null) {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $button = '<a href="' . $url . '" style="color:blue;" target="_blank">' . $applicant->formatted_postcode . '</a>'; // Using accessor
                    } else {
                        $button = $applicant->formatted_postcode;
                    }
                    return $button;
                })
                ->addColumn('applicantPhone', function ($applicant) {

                    if ($applicant->is_blocked && !$this->can('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $applicant->is_blocked
                        && $this->can('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($applicant->applicant_phone)) {
                        $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($applicant->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($applicant->applicant_landline)) {
                        $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                // In your DataTable or controller
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->addColumn('notes_detail', function ($applicant) {
                    $notes_detail = strip_tags($applicant->notes_detail);
                    $notes_created_at = Carbon::parse($applicant->notes_created_at)->format('d M Y, h:i A');
                    $notes = "<strong>Date: {$notes_created_at}</strong><br>{$notes_detail}";

                    $short = Str::limit($notes, 150);
                    $modalId = 'crm-' . $applicant->id . '-' . $applicant->sale_id;

                    $name = e($applicant->applicant_name);
                    $postcode = e($applicant->applicant_postcode);
                    $notesEscaped = nl2br(e($notes_detail));
                    $copyId = "copy-notes-" . $applicant->id . '-' . $applicant->sale_id;

                    return '
                        <div>
                            <a href="javascript:void(0);" class="text-primary" 
                            data-bs-toggle="modal" 
                            data-bs-target="#' . $modalId . '">
                                ' . $short . '
                            </a>

                            <!-- Hidden full notes for copy -->
                            <div id="' . $copyId . '" class="d-none">' . $notesEscaped . '</div><br>

                            <!-- Copy button under short note -->
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2 copy-btn" data-copy-target="#' . $copyId . '">
                                Copy Notes
                            </button>
                        </div>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $modalId . '-label">Applicant\'s CRM Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <p><strong>Name:</strong> ' . $name . '</p>
                                        <p><strong>Postcode:</strong> ' . $postcode . '</p>
                                        <p><strong>Date:</strong> ' . $notes_created_at . '</p>
                                        <p class="notes-content">
                                            <strong>Notes Detail:</strong><br>' . $notesEscaped . '
                                        </p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('show_created_at', function ($applicant) {
                    return Carbon::parse($applicant->show_created_at)->format('d M Y, h:i A'); // Using accessor
                })
                ->addColumn('schedule_date', function ($applicant) {
                    // return $applicant->schedule_date ? Carbon::parse($applicant->schedule_date.' '.$applicant->schedule_time)->format('d M Y, h:i A') : '-'; 
                    return $applicant->schedule_date ? Carbon::parse($applicant->schedule_date)->format('d M Y') . ' ' . $applicant->schedule_time : '-'; // Using 
                })
                ->addColumn('paid_status', function ($applicant) {
                    return $applicant->paid_status ?? '-';
                })
                ->addColumn('job_details', function ($applicant) use ($jobTitleNamesById, $jobCategoryNamesById) {
                    $position_type = strtoupper(str_replace('-', ' ', $applicant->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text
                    $status = '';
                    if ($applicant->sale_status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->sale_status == 0 && $applicant->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($applicant->sale_status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($applicant->sale_status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $sale_source_name = '';
                    if ($applicant->sale_source_name) {
                        $sale_source_name = '<span class="badge bg-light text-dark">' . e($applicant->sale_source_name) . '</span>';
                    }

                    $postcode = strtoupper($applicant->sale_postcode);
                    $posted_date = Carbon::parse($applicant->sale_posted_date)->format('d M Y, h:i A');
                    $office_name = ucwords($applicant->office_name) ?? '-';
                    $unit_name = ucwords($applicant->unit_name) ?? '-';
                    $jobTitleNameValue = $jobTitleNamesById->get($applicant->sale_title_id);
                    $jobTitle = $jobTitleNameValue ? strtoupper($jobTitleNameValue) : '-';
                    $stype  = $applicant->sale_job_type && $applicant->sale_job_type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategoryNameValue = $jobCategoryNamesById->get($applicant->sale_category_id);
                    $jobCategory = $jobCategoryNameValue ? (ucwords($jobCategoryNameValue) . $stype) : '-';

                    $jobData = [
                        'sale_id'       => (int)$applicant->sale_id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $jobCategory,
                        'job_title'     => $jobTitle,
                        'sale_source_name' => $sale_source_name,
                        'status'        => $status,       // RAW HTML
                        'timing'        => $applicant->timing,
                        'experience'    => $applicant->sale_experience,
                        'salary'        => $applicant->salary,
                        'position'      => $position,     // RAW HTML
                        'qualification' => $applicant->sale_qualification,
                        'benefits'      => $applicant->benefits,
                    ];

                    return '<a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                        $jobData,
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                    ) . '\'>
                            <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>';
                })
                ->addColumn('action', function ($applicant) use (
                    $tabFilter,
                    $smsTemplateCrmSendRequest,
                    $smsNotificationSetting,
                    $emailNotificationSetting,
                    $emailTemplateRequestConfiguration,
                    $emailTemplateRequestRejected
                ) {
                    $formattedMessage = '';
                    // SMS template + settings fetched once above (they are the same for every row).
                    $sms_template = $smsTemplateCrmSendRequest;
                    $smsNotification = $smsNotificationSetting;

                    if ($smsNotification && $sms_template && $smsNotification->value == '1' && !empty($sms_template->template)) {
                        $sms_template = $sms_template->template;

                        $replace = [$applicant->applicant_name, $applicant->unit_name];
                        $prev_val = ['(applicant_name)', '(unit_name)'];

                        $newPhrase = str_replace($prev_val, $replace, $sms_template);
                        $formattedMessage = nl2br($newPhrase);
                    }

                    $html = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">';

                    $actionButtons = '';
                    // Filter by status if it's not empty
                    switch ($tabFilter) {
                        case 'open cvs':
                            if ($this->can('crm-add-note')) {
                                $actionButtons = '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#updateCrmNotesModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        data-applicant-phone="' . $applicant->applicant_phone . '" 
                                        data-applicant-name="' . $applicant->applicant_name . '" 
                                        data-applicant-unit="' . $applicant->unit_name . '"
                                        onclick="updateCrmNotesModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'open_cv\')">
                                        Add CRM Notes
                                    </a></li>';
                            }
                            if ($this->can('crm-send-request')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmSendRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        data-applicant-phone="' . $applicant->applicant_phone . '" 
                                        data-applicant-name="' . $applicant->applicant_name . '" 
                                        data-applicant-unit="' . $applicant->unit_name . '"
                                        onclick="crmSendRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'open_cv\', \'' . htmlspecialchars($formattedMessage, ENT_QUOTES) . '\')">
                                        Send Request
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertInQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertInQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'open_cv\')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>
                            ';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-email="' . $applicant->applicant_email . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                ';
                            break;

                        case 'sent cvs (no job)':
                            if ($this->can('crm-add-note')) {
                                $actionButtons .= '
                                        <li><a class="dropdown-item" 
                                            href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#updateCrmNoJobNotesModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="updateCrmNoJobNotesModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Add CRM Notes
                                        </a></li>';
                            }
                            if ($this->can('crm-send-request')) {
                                $actionButtons .= '
                                        <li><a class="dropdown-item" 
                                            href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmSendNoJobRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmSendNoJobRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Send Request
                                        </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                            href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmSentCvNoJobRevertInQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmSentCvNoJobRevertInQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Revert In Quality
                                        </a></li>';
                            }
                            $actionButtons .= '
                                <li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>
                            ';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                        data-email="' . $applicant->applicant_email . '"
                                        data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                    ';
                            break;

                        case 'rejected cvs':
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '
                                        <li><a class="dropdown-item" 
                                            href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmRevertRejectedCvToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmRevertRejectedCvToSentCvModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Revert In Sent CV
                                        </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRejectedCvToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRejectedCvToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '
                                <li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>
                            ';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;

                        case 'request':
                            $emailText = '<span class="badge bg-warning"><i class="ri-inbox-line text-white" title="Sent"></i> Email Not Sent</span>';
                            $sentEmail = SentEmail::where('applicant_id', $applicant->id)
                                ->where('sale_id', $applicant->sale_id)
                                ->latest()->first();

                            if ($sentEmail && $sentEmail->status == '1') {
                                $emailText = '<span class="badge bg-success"><i class="ri-inbox-line text-white" title="Sent"></i> Email Sent</span>';
                            } elseif ($sentEmail && $sentEmail->status == '2') {
                                $emailText = '<span class="badge bg-danger"><i class="ri-inbox-line text-white" title="Sent"></i> Email Failed</span>';
                            } elseif ($sentEmail && $sentEmail->status == '0') {
                                $emailText = '<span class="badge bg-info"><i class="ri-inbox-line text-white" title="Sent"></i> Email Pending</span>';
                            }

                            $applicant_msgs = Message::whereIn('phone_number', [
                                $applicant->applicant_phone,
                                $applicant->applicant_phone_secondary
                            ])
                                ->where('status', 'incoming')
                                ->where('module_type', 'Horsefly\\Applicant')
                                ->orderBy('created_at', 'desc')
                                ->first();

                            $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0)" >' . $emailText . '</a></li>';
                            if ($applicant->schedule_time && $applicant->schedule_date && $applicant->interview_status == 1) {
                                $actionButtons .= '<li><a href="javascript:void(0);" class="dropdown-item disabled text-danger">
                                                        <i class="ri-lock-line"></i> Already Scheduled</a></li>';
                            } else {
                                if ($this->can('crm-schedule-interview')) {
                                    if ($sentEmail) {
                                        // If the email is successfully sent (status = '1'), skip the email modal
                                        $actionButtons .= '<li>
                                            <a href="javascript:void(0);" class="dropdown-item" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#crmScheduleInterviewModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" 
                                                data-applicant-id="' . (int)$applicant->id . '"
                                                data-sale-id="' . (int)$applicant->sale_id . '"
                                                onclick="crmScheduleInterviewModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                                Schedule Interview
                                            </a>
                                        </li>';
                                    } else {
                                        // If no email has been sent yet, open the email modal first
                                        $actionButtons .= '<li>
                                            <a href="javascript:void(0);" class="dropdown-item" 
                                                data-bs-toggle="modal"  
                                                data-bs-target="#crmSendApplicantEmailRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                                data-applicant-id="' . (int)$applicant->id . '"
                                                data-sale-id="' . (int)$applicant->sale_id . '"
                                                onclick="crmSendApplicantEmailRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                                Schedule Interview
                                            </a>
                                        </li>';
                                    }
                                }
                            }
                            if ($this->can('crm-schedule-interview')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmMoveToconfirmationModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmMoveToconfirmationModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Move to Confirmation
                                    </a></li>';
                            }
                            $actionButtons .= '
                                <li><a class="dropdown-item" href="javascript:void(0);" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#crmMoveRequestToNoResponseModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                    data-applicant-id="' . (int)$applicant->id . '"
                                    data-sale-id="' . (int)$applicant->sale_id . '"
                                    onclick="crmMoveRequestToNoResponseModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                    Mark No Response
                                </a></li>';
                            if ($this->can('crm-revert-request')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRequestedCvToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRequestedCvToSentCvModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Sent CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-request')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRequestedCvToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRequestedCvToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '
                                <li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';

                            break;

                        case 'request (no job)':
                            // $emailIcon = '<i class="fas fa-envelope text-secondary" title="Not Sent"></i>';
                            $emailText = 'Not Sent';
                            $sentEmail = SentEmail::where('applicant_id', $applicant->id)
                                ->where('sale_id', $applicant->sale_id)
                                ->latest()->first();

                            if ($sentEmail && $sentEmail->status == '1') {
                                $emailText = '<span class="badge bg-success"><i class="ri-inbox-line text-white" title="Sent"></i> Email Sent</span>';
                            } elseif ($sentEmail && $sentEmail->status == '2') {
                                $emailText = '<span class="badge bg-danger"><i class="ri-inbox-line text-white" title="Sent"></i> Email Failed</span>';
                            } elseif ($sentEmail && $sentEmail->status == '0') {
                                $emailText = '<span class="badge bg-info"><i class="ri-inbox-line text-white" title="Sent"></i> Email Pending</span>';
                            }

                            $applicant_msgs = Message::whereIn('phone_number', [
                                $applicant->applicant_phone,
                                $applicant->applicant_phone_secondary
                            ])
                                ->where('status', 'incoming')
                                ->where('module_type', 'Horsefly\\Applicant')
                                ->orderBy('created_at', 'desc')
                                ->first();


                            $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0)" >Email ' . $emailText . '</a></li>';
                            if ($applicant->schedule_time && $applicant->schedule_date && $applicant->interview_status == 1) {
                                $actionButtons .= '<li><a href="javascript:void(0);" class="dropdown-item disabled text-danger">Already Scheduled</a></li>';
                            } else {
                                if ($this->can('crm-schedule-interview')) {
                                    if ($sentEmail) {
                                        // If the email is successfully sent (status = '1'), skip the email modal
                                        $actionButtons .= '<li>
                                            <a href="javascript:void(0);" class="dropdown-item" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#crmScheduleInterviewModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" 
                                                data-applicant-id="' . (int)$applicant->id . '"
                                                data-sale-id="' . (int)$applicant->sale_id . '"
                                                onclick="crmScheduleInterviewModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                                Schedule Interview
                                            </a>
                                        </li>';
                                    } else {
                                        // If no email has been sent yet, open the email modal first
                                        $actionButtons .= '<li>
                                            <a href="javascript:void(0);" class="dropdown-item" 
                                                data-bs-toggle="modal"  
                                                data-bs-target="#crmSendApplicantEmailRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                                data-applicant-id="' . (int)$applicant->id . '"
                                                data-sale-id="' . (int)$applicant->sale_id . '"
                                                onclick="crmSendApplicantEmailRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                                Schedule Interview
                                            </a>
                                        </li>';
                                    }
                                }
                            }
                            if ($this->can('crm-send-confirmation')) {
                                $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmMoveToconfirmationModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmMoveToconfirmationModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Move to Confirmation
                                        </a></li>';
                            }
                            if ($this->can('crm-revert-request')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRequestedCvToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRequestedCvToSentCvModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Sent CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-request')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRequestedCvToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRequestedCvToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                            data-email="' . $applicant->applicant_email . '"
                                            data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                        ';
                            break;
                        case 'request (no response)':
                            $applicant_msgs = Message::whereIn('phone_number', [
                                $applicant->applicant_phone,
                                $applicant->applicant_phone_secondary
                            ])
                                ->where('status', 'incoming')
                                ->where('module_type', 'Horsefly\\Applicant')
                                ->orderBy('created_at', 'desc')
                                ->first();


                            $actionButtons .= '<li><a class="dropdown-item" 
                                    href="javascript:void(0);" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#crmMarkRequestConfirmOrRejectModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                    data-applicant-id="' . (int)$applicant->id . '"
                                    data-sale-id="' . (int)$applicant->sale_id . '"
                                    onclick="crmMarkRequestConfirmOrRejectModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                    Mark Confirm / Reject CV
                                </a></li>';

                            if ($this->can('crm-revert-request')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRequestedCvToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRequestedCvToSentCvModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Sent CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-request')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRequestedCvToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRequestedCvToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                            data-email="' . $applicant->applicant_email . '"
                                            data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                        ';
                            break;
                        case 'rejected by request':
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRejectRequestRevertToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRejectRequestRevertToSentCvModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Sent Cv
                                    </a></li>
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRejectRequestRevertToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRejectRequestRevertToRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Request
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '   <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRejectRequestRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRejectRequestRevertToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>
                            ';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                            data-email="' . $applicant->applicant_email . '"
                                            data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                        ';
                            break;
                        case 'confirmation':
                            if ($this->can('crm-accept-confirmation')) {
                                $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmConfirmationAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmConfirmationAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Accept CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertConfirmationToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertConfirmationToRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Request
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmConfirmationRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmConfirmationRevertToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>
                            ';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-email="' . $applicant->applicant_email . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                ';
                            break;
                        case 'rebook':
                            if ($this->can('crm-accept-rebook')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRebookAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRebookAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Accept CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertRebookToConfirmationModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertRebookToConfirmationModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Confirmation
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRebookRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRebookRevertToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                                </li>';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                            data-email="' . $applicant->applicant_email . '"
                                            data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                        ';
                            break;
                        case 'attended to pre-start date':
                            if ($this->can('crm-accept-attended')) {
                                $actionButtons .= '
                                        <li><a class="dropdown-item" href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmAttendedPreStartDateAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmAttendedPreStartDateAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Accept CV
                                        </a></li>';
                            }
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                            href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmRevertAttendToRebookModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmRevertAttendToRebookModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Revert In Rebook
                                        </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                            href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmAttendedRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmAttendedRevertToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Revert In Quality
                                        </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                            data-email="' . $applicant->applicant_email . '"
                                            data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                        ';
                            break;
                        case 'declined':
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertDeclinedToAttendedModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertDeclinedToAttendedModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Attended
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                    href="javascript:void(0);" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#crmDeclinedRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                    data-applicant-id="' . (int)$applicant->id . '"
                                    data-sale-id="' . (int)$applicant->sale_id . '"
                                    onclick="crmDeclinedRevertToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                    Revert In Quality
                                </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        case 'not attended':
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertNotAttendedToAttendedModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertNotAttendedToAttendedModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Attended
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmNotAttendedToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmNotAttendedToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                            data-email="' . $applicant->applicant_email . '"
                                            data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                        ';
                            break;
                        case 'start date':
                            if ($this->can('crm-accept-start-date')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmStartDateAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmStartDateAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Accept CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertStartDateToAttendedModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertStartDateToAttendedModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Attended
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmStartDateToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmStartDateToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        case 'start date hold':
                            if ($this->can('crm-add-note')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmStartDateHoldAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmStartDateHoldAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Add Note
                                    </a></li>';
                            }
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertStartDateHoldToStartDateModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertStartDateHoldToStartDateModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Start Date
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmStartDateHoldToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmStartDateHoldToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }

                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        case 'invoice':
                            if ($this->can('crm-accept-invoice')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmInvoiceAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmInvoiceAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Accept CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertInvoiceToStartDateModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertInvoiceToStartDateModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Start Date
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmInvoiceToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmInvoiceToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        case 'invoice sent':
                            if ($this->can('crm-accept-invoice-sent')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmInvoiceSentAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmInvoiceSentAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Accept CV
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmInvoiceSentToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmInvoiceSentToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        case 'dispute':
                            if ($this->can('crm-revert')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertDisputeToInvoiceModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertDisputeToInvoiceModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Invoice
                                    </a></li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmDisputeToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmDisputeToQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Quality
                                    </a></li>';
                            }

                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        case 'paid':
                            $paid_status_button = ($applicant->paid_status == 'close') ? 'Open' : 'Close';

                            if ($this->can('crm-paid-toggle-status')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmChangePaidStatusModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmChangePaidStatusModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Mark As ' . $paid_status_button . '
                                    </a></li>';
                            }
                            if ($this->can('crm-paid-revert')) {
                                $actionButtons .= '
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        onclick="crmRevertPaidApp(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Revert In Invoice Sent
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-phone="' . $applicant->applicant_phone . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a>
                            </li>';

                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                data-email="' . $applicant->applicant_email . '"
                                data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                            ';
                            break;
                        default:
                        case 'sent cv':
                            if ($this->can('crm-add-note')) {
                                $actionButtons .= '
                                        <li><a class="dropdown-item" 
                                                href="javascript:void(0);" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#updateCrmNotesModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                                data-applicant-id="' . (int)$applicant->id . '"
                                                data-sale-id="' . (int)$applicant->sale_id . '"
                                                data-applicant-phone="' . $applicant->applicant_phone . '" 
                                                data-applicant-name="' . $applicant->applicant_name . '" 
                                                data-applicant-unit="' . $applicant->unit_name . '"
                                                onclick="updateCrmNotesModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'sent_cv\')">
                                                Add CRM Notes
                                            </a></li>';
                            }
                            if ($this->can('crm-send-request')) {
                                // $actionButtons .= '<li><a class="dropdown-item" 
                                //     href="javascript:void(0);" 
                                //     data-bs-toggle="modal" 
                                //     data-bs-target="#crmSentCvToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                //     data-applicant-id="' . (int)$applicant->id . '"
                                //     data-sale-id="' . (int)$applicant->sale_id . '"
                                //     data-applicant-phone="' . $applicant->applicant_phone . '" 
                                //     data-applicant-name="' . $applicant->applicant_name . '" 
                                //     data-applicant-unit="' . $applicant->unit_name . '"
                                //     onclick="crmSentCvToRequestModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'sent_cv\', \'' . htmlspecialchars($formattedMessage, ENT_QUOTES) . '\')">
                                //     Send Request
                                // </a></li>';
                                $actionButtons .= '<li>
                                        <a class="dropdown-item"
                                        href="javascript:void(0);"
                                        data-bs-toggle="modal"
                                        data-bs-target="#crmSentCvToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        data-applicant-phone="' . e($applicant->applicant_phone) . '"
                                        data-applicant-name="' . e($applicant->applicant_name) . '"
                                        data-applicant-unit="' . e($applicant->unit_name) . '"
                                        data-sms-message="' . e($formattedMessage) . '"
                                        onclick="crmSentCvToRequestModal(this, ' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'sent_cv\')">
                                        Send Request
                                        </a>
                                    </li>';
                            }
                            if ($this->can('crm-revert-in-quality')) {
                                $actionButtons .= '<li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmRevertInQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmRevertInQualityModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'sent_cv\')">
                                        Revert In Quality
                                    </a></li>';
                            }
                            $actionButtons .= '<li><a class="dropdown-item chat-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                    data-phone="' . $applicant->applicant_phone . '"
                                    data-name="' . ucwords($applicant->applicant_name) . '">Send SMS</a></li>
                                ';
                            if (!empty($applicant_msgs)) {
                                if ($applicant_msgs['is_read'] == 0) {
                                    $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" >Reply SMS</a></li>';
                                }
                            }
                            $actionButtons .= '<li><a class="dropdown-item email-btn" href="javascript:void(0);" data-applicant-id="' . (int)$applicant->id . '" 
                                        data-email="' . $applicant->applicant_email . '"
                                        data-name="' . ucwords($applicant->applicant_name) . '">Send Email</a></li>
                                    ';
                            break;
                    }

                    $html .= $actionButtons;  // Using the action buttons defined earlier
                    $url = route('crmNotesHistoryIndex', ['applicant_id' => (int)$applicant->id, 'sale_id' => (int)$applicant->sale_id]);
                    $html .= '<li><hr class="dropdown-divider"></li>';
                    // Common actions
                    $html .= '<li><a class="dropdown-item" target="_blank" href="' . $url . '">Notes History</a></li>';
                    $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . (int)$applicant->sale_unit_id . ')">Manager Details</a></li>';
                    $html .= '</ul></div>';

                    /*** Update CRM Notes Modal */
                    $html .= '<div id="updateCrmNotesModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="updateCrmNotesModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="updateCrmNotesModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">Add CRM Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="updateCrmNotesForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="mb-3">
                                                <label for="reasonDropdown' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Reason</label>
                                                <select class="form-select crm_select_reason" name="reason" id="reasonDropdown' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" required>
                                                    <option value="" disabled selected>Select Reason</option>
                                                    <option value="position_filled">Position Filled</option>
                                                    <option value="position_filled">Do Not Use In Agency</option>
                                                    <option value="agency">Sent By Another Agency</option>
                                                    <option value="manager">Rejected By Manager</option>
                                                    <option value="no_response">No Response</option>
                                                    <option value="update_notes" selected>Update Notes</option>
                                                </select>
                                                <div class="invalid-feedback">Please select a reason.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                <button type="button" class="btn btn-danger crmSentCVRejectButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '" style="display:none">Reject</button>
                                                <button type="button" class="btn btn-success saveUpdateCrmNotesButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '" >Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';

                    /** CRM Send Request Modal */
                    $html .= '<div id="crmSentCvToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmSentCvToRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmSentCvToRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Send Request</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmSendRequest') . '" method="POST" id="crmSendRequestForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="sendRequestDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmSendRequestButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';

                    /** CRM Revert In Quality Modal */
                    $html .= '<div id="crmRevertInQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertInQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertInQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert CV In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertInQuality') . '" method="POST" id="crmRevertInQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="revertInQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertInQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Requested CV to Sent CV Modal */
                    $html .= '<div id="crmRevertRequestedCvToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertRequestedCvToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertRequestedCvToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Sent CV</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRequestedCvToSentCv') . '" method="POST" id="crmRevertRequestedCvToSentCvForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="RevertRevertRequestedCvToSentCvDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRequestedCvToSentCvButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Requested CV to Quality Modal */
                    $html .= '<div id="crmRevertRequestedCvToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertRequestedCvToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertRequestedCvToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRequestedCvToQuality') . '" method="POST" id="crmRevertRequestedCvToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="RevertRevertRequestedCvToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRequestedCvToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Rejected CV to Sent CV Modal */
                    $html .= '<div id="crmRevertRejectedCvToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertRejectedCvToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertRejectedCvToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Sent CV</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRejectedCvToSentCv') . '" method="POST" id="crmRevertRejectedCvToSentCvForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="RevertRevertRejectedCvToSentCvDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRejectedCvToSentCvButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Rejected CV to Quality Modal */
                    $html .= '<div id="crmRevertRejectedCvToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertRejectedCvToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertRejectedCvToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRejectedCvToQuality') . '" method="POST" id="crmRevertRejectedCvToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="RevertRevertRejectedCvToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRejectedCvToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /*** Schedule Interview Modal */
                    $from_email = '';
                    $newPhrase = '';
                    $newSubject = '';
                    $applicant_email = '';
                    // Fetched once above; cloned here since the loop below mutates attributes.
                    $request_configuration_email = $emailTemplateRequestConfiguration ? clone $emailTemplateRequestConfiguration : null;

                    $emailNotification = $emailNotificationSetting;

                    if ($emailNotification && $emailNotification->value == '1' && $request_configuration_email && !empty($request_configuration_email->template)) {
                        // Loop through each attribute of the model
                        foreach ($request_configuration_email->getAttributes() as $key => $value) {
                            if (is_string($value)) {
                                // Convert to UTF-8 if not properly encoded
                                $request_configuration_email->{$key} = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                            }
                        }

                        $applicant_name = $applicant->applicant_name;
                        $salary = $applicant->salary;
                        $job_postcode = $applicant->sale_postcode;
                        $unit_name = $applicant->unit_name;
                        $distance = $job_postcode;
                        $location = $job_postcode;

                        $job_title = $applicant->jobTitle ?? '-';

                        //replace variables into body
                        $data = $request_configuration_email->template;
                        $replace = [$applicant_name, strtoupper($job_title), $unit_name, $job_postcode, $salary, $distance, $location];
                        $prev_val = ['(applicant_name)', '(job_title)', '(unit_name)', '(postcode)', '(salary)', '(distance)', '(location)'];
                        $newPhrase = str_replace($prev_val, $replace, $data);

                        //replace variables into subjects
                        $subject = $request_configuration_email->subject;
                        $from_email = $request_configuration_email->from_email;
                        $subjectReplace = [$job_title];
                        $prev_sub = ['(job_title)'];
                        $newSubject = str_replace($prev_sub, $subjectReplace, $subject);

                        // $applicant_email = htmlspecialchars($applicant->applicant_email, ENT_QUOTES, 'UTF-8');

                        $applicant_email = $applicant->applicant_email
                            ? htmlspecialchars($applicant->applicant_email, ENT_QUOTES, 'UTF-8')
                            : '';
                        $newSubject = htmlspecialchars($newSubject, ENT_QUOTES, 'UTF-8');
                        $newPhrase = htmlspecialchars($newPhrase, ENT_QUOTES, 'UTF-8');
                    }
                    // If the email is successfully sent (status = '1'), skip the email modal
                    $html .= '<div id="crmScheduleInterviewModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmScheduleInterviewModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-md modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmScheduleInterviewModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">Schedule Interview for <em>' . $applicant->applicant_name . '</em></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"></div>
                                        <form action="' . route('crmScheduleInterview') . '" method="POST" id="crmScheduleInterviewForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <!-- Date Picker Field -->
                                            <div class="mb-4">
                                                <label for="schedule_date' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Interview Date</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="ri-calendar-2-fill"></i></span>
                                                    <input type="date" class="form-control" 
                                                        name="schedule_date" 
                                                        id="schedule_date' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" 
                                                        placeholder="Select date"
                                                        data-date-format="Y-m-d"
                                                        data-min-date="today">
                                                    <div class="invalid-feedback">Please provide schedule date.</div>
                                                </div>
                                            </div>
                                            
                                            <!-- Time Picker Field -->
                                            <div class="mb-4">
                                                <label for="schedule_time' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Interview Time</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="ri-alarm-fill"></i></span>
                                                    <input type="time" class="form-control" 
                                                        name="schedule_time"
                                                        id="schedule_time' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" 
                                                        placeholder="Select time"
                                                        data-enable-time="true"
                                                        data-no-calendar="true"
                                                        data-time-format="H:i"
                                                        data-minute-increment="15">
                                                        <div class="invalid-feedback">Please provide time.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                <button type="button" class="btn btn-success saveCrmScheduleInterviewButton" 
                                                        data-applicant-id="' . (int)$applicant->id . '" 
                                                        data-sale-id="' . (int)$applicant->sale_id . '">Schedule</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    // If the email is successfully sent (status = '1'), skip the email modal
                    $html .= '<div id="crmReScheduleInterviewModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmReScheduleInterviewModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-md modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmReScheduleInterviewModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">Schedule Interview for <em>' . $applicant->applicant_name . '</em></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"></div>
                                        <form action="' . route('crmScheduleInterview') . '" method="POST" id="crmReScheduleInterviewForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <!-- Date Picker Field -->
                                            <div class="mb-4">
                                                <label for="schedule_date' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Interview Date</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="ri-calendar-2-fill"></i></span>
                                                    <input type="date" class="form-control" 
                                                        name="reschedule_date" 
                                                        id="reschedule_date' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" 
                                                        placeholder="Select date"
                                                        data-date-format="Y-m-d"
                                                        data-min-date="today">
                                                    <div class="invalid-feedback">Please provide schedule date.</div>
                                                </div>
                                            </div>
                                            
                                            <!-- Time Picker Field -->
                                            <div class="mb-4">
                                                <label for="schedule_time' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Interview Time</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="ri-alarm-fill"></i></span>
                                                    <input type="time" class="form-control" 
                                                        name="reschedule_time"
                                                        id="reschedule_time' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" 
                                                        placeholder="Select time"
                                                        data-enable-time="true"
                                                        data-no-calendar="true"
                                                        data-time-format="H:i"
                                                        data-minute-increment="15">
                                                        <div class="invalid-feedback">Please provide time.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                <button type="button" class="btn btn-success saveCrmReScheduleInterviewButton" 
                                                        data-applicant-id="' . (int)$applicant->id . '" 
                                                        data-sale-id="' . (int)$applicant->sale_id . '">Schedule</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';

                    // If no email has been sent yet, open the email modal first
                    $html .= '<div id="crmSendApplicantEmailRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="crmSendApplicantEmailRequestModalLabel" aria-hidden="true" style="z-index:99999">
                                <div class="modal-dialog modal-lg modal-dialog-top" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmSendApplicantEmailRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">Send Email To Applicant</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRequestedInterviewEmailToApplicant') . '" method="POST" id="crmSendApplicantEmailRequestForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <div class="form-group mb-2">
                                                    <label for="">To</label>
                                                    <input type="hidden" name="email_from" id="email_from_requested_' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-control" value="' . $from_email . '">
                                                    <input type="email" name="email_to" id="email_to_requested_' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-control" placeholder="Enter Email Address" value="' . $applicant_email . '" required>
                                                    <div class="invalid-feedback">Please provide email address.</div>
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label for="">Subject</label>
                                                    <input type="text" name="email_subject" id="email_subject_requested_' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-control" placeholder="Email Subject" value="' . $newSubject . '" required>
                                                    <div class="invalid-feedback">Please provide email subject.</div>
                                                </div>
                                                <div class="form-group">
                                                    <label for="">Content</label>
                                                    <textarea name="email_body" id="email_body_requested_' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="10" class="form-control" placeholder="Email Body" required>' . $newPhrase . '</textarea>
                                                    <div class="invalid-feedback">Please provide email body.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmSendApplicantEmailRequestButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Submit Email</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';

                    /** CRM Move to Confirmation Modal */
                    // Fetched once above (same for every row).
                    $request_reject_email_template = $emailTemplateRequestRejected;
                    $request_reject_template = '';
                    $request_reject_subject = '';
                    $request_reject_slug = '';

                    $emailNotification = $emailNotificationSetting;

                    if ($emailNotification && $emailNotification->value == '1' && $request_reject_email_template && !empty($request_reject_email_template->template)) {
                        $request_reject_subject = $request_reject_email_template->subject;
                        $request_reject_slug = $request_reject_email_template->slug;
                        $data = $request_reject_email_template->template;
                        $replace = [$applicant->unit_name, $applicant->applicant_name];
                        $prev_val = ['(service_name)', '(xyz)'];

                        $request_reject_template = str_replace($prev_val, $replace, $data);
                    }
                    $html .= '<div id="crmMoveToconfirmationModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmMoveToconfirmationModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmMoveToconfirmationModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Move To Confirmation Notes</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="" method="" id="crmMoveToconfirmationForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <input type="hidden" name="rejection_data" id="rejectionData' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" value="">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmMoveToconfirmationDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-danger savecrmMoveToconfirmationRejectButton" 
                                                        data-applicant-id ="' . (int)$applicant->id . '" 
                                                        data-request-reject-template="' . e($request_reject_template) . '" 
                                                        data-request-reject-subject="' . e($request_reject_subject) . '" 
                                                        data-request-reject-slug="' . e($request_reject_slug) . '" 
                                                        data-sale-id ="' . (int)$applicant->sale_id . '">
                                                        Reject
                                                    </button>';
                    if ($applicant->schedule_time && $applicant->schedule_date && $applicant->interview_status == 1) {
                        $html .= '<button type="button" class="btn btn-primary savecrmMoveToconfirmationRequestButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Request Confirm</button>';
                    } else {
                        $html .= '<button type="button" class="btn btn-primary savecrmMoveToconfirmationRequestButton disabled" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '" title="Please schedule an interview first.">Request Confirm</button>';
                    }
                    $html .= '<button type="button" class="btn btn-success savecrmConfirmationSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Mark Confirm Or Reject Modal */
                    $html .= '<div id="crmMarkRequestConfirmOrRejectModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmMarkRequestConfirmOrRejectModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmMarkRequestConfirmOrRejectModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Mark Request Confirm Or Reject</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="" method="" id="crmMarkRequestConfirmOrRejectForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmMarkRequestConfirmOrRejectDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-primary savecrmMarkRequestButtonConfirm" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Confirm</button>
                                                    <button type="button" class="btn btn-primary savecrmMarkRequestButtonReject" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Reject</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Move Request To No Response Modal */
                    $html .= '<div id="crmMoveRequestToNoResponseModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmMoveRequestToNoResponseModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmMoveRequestToNoResponseModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Move To No Response Notes</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="" method="" id="crmMoveRequestToNoResponseForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmMoveRequestToNoResponseDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">';
                    $html .= '
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success savecrmRequestToNoResponseSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';

                    // Rejection Email Modal
                    $html .= '<div id="crmSendApplicantEmailOnRequestRejectModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmSendApplicantEmailOnRequestRejectModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmSendApplicantEmailOnRequestRejectModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">Send Rejection Email</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlertReject' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form id="rejectEmailForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal" action="" method="POST">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <input type="hidden" name="details" id="rejectionNotesHidden' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">
                                            <input type="hidden" name="slug" id="request_reject_slug' . $applicant->id . '-' . $applicant->sale_id . '" class="form-control">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">To Email</label>
                                                <input type="email" name="to" id="request_reject_from_email' . $applicant->id . '-' . $applicant->sale_id . '" class="form-control" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Subject</label>
                                                <input type="text" name="subject" class="form-control" id="request_reject_subject' . $applicant->id . '-' . $applicant->sale_id . '" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Message</label>
                                                <textarea name="body" class="form-control summernote" rows="4" id="request_reject_template' . $applicant->id . '-' . $applicant->sale_id . '" required></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" class="btn btn-success saveCrmSendApplicantEmailRequestRejectButton">Send Email</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Confirmation Accept CV Modal */
                    $html .= '<div id="crmConfirmationAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmConfirmationAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmConfirmationAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Interview Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmConfirmationAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmConfirmationAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-primary crmConfirmationNotAttendButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Not Attend</button>
                                                <button type="button" class="btn btn-primary crmConfirmationAttendButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Attend</button>
                                                <button type="button" class="btn btn-primary crmConfirmationRebookButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Rebook</button>
                                                <button type="button" class="btn btn-success crmConfirmationSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';

                    /*** Update CRM Notes Modal */
                    $html .= '<div id="updateCrmNoJobNotesModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="updateCrmNotesModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="updateCrmNoJobNotesModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">Add CRM Notes</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('updateCrmNoJobNotes') . '" method="POST" id="updateCrmNoJobNotesForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="noJobdetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="noJobdetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="reasonDropdownNoJob' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Reason</label>
                                                    <select class="form-select" name="reason" id="reasonDropdownNoJob' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" required>
                                                        <option value="" disabled selected>Select Reason</option>
                                                        <option value="position_filled">Position Filled</option>
                                                        <option value="position_filled">Do Not Use In Agency</option>
                                                        <option value="agency">Sent By Another Agency</option>
                                                        <option value="manager">Rejected By Manager</option>
                                                        <option value="no_response">No Response</option>
                                                    </select>
                                                    <div class="invalid-feedback">Please select a reason.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveupdateCrmNoJobNotesButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '" >Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';


                    /** CRM Send Request Modal */
                    $html .= '<div id="crmSendNoJobRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmSendRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmSendNoJobRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Send Request</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmSendNoJobRequest') . '" method="POST" id="crmSendNoJobRequestForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="sendNoJobRequestDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="sendNoJobRequestDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmSendNoJobRequestButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';

                    /** CRM Revert In Quality Modal */
                    $html .= '<div id="crmSentCvNoJobRevertInQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmSentCvNoJobRevertInQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmSentCvNoJobRevertInQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert No Job CV In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmSentCvNoJobRevertInQuality') . '" method="POST" id="crmNoJobRevertInQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="revertNoJobInQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmNoJobRevertInQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Request Reject to Sent CV Modal */
                    $html .= '<div id="crmRejectRequestRevertToSentCvModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRejectRequestRevertToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRejectRequestRevertToSentCvModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Sent CV</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRequestRejectToSentCv') . '" method="POST" id="crmRevertToSentCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertToSentCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertToSentCVButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Request Reject to Request Modal */
                    $html .= '<div id="crmRejectRequestRevertToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRejectRequestRevertToRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRejectRequestRevertToRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Request</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRequestRejectToRequest') . '" method="POST" id="crmRevertToRequestForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertToRequestDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertToRequestButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Request Reject to Quality Modal */
                    $html .= '<div id="crmRejectRequestRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRejectRequestRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRejectRequestRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRequestRejectToQuality') . '" method="POST" id="crmRevertRequestRejectedToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertRequestRejectedToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRequestRejectedToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Confirmation to Request Modal */
                    $html .= '<div id="crmRevertConfirmationToRequestModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertConfirmationToRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertConfirmationToRequestModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Request</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertConfirmToRequest') . '" method="POST" id="crmRevertConfirmationToRequestForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertConfirmationToRequestDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertConfirmationToRequestButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Confirmation to Quality Modal */
                    $html .= '<div id="crmConfirmationRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmConfirmationRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmConfirmationRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertConfirmToQuality') . '" method="POST" id="crmConfirmationRevertToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmConfirmationRevertToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertConfirmationToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Rebook to Quality Modal */
                    $html .= '<div id="crmRebookRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRebookRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRebookRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRebookToQuality') . '" method="POST" id="crmRebookRevertToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRebookRevertToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRebookToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Attended to Quality Modal */
                    $html .= '<div id="crmAttendedRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmAttendedRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmAttendedRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertAttendedToQuality') . '" method="POST" id="crmAttendedRevertToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmAttendedRevertToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertAttendedToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Declined to Quality Modal */
                    $html .= '<div id="crmDeclinedRevertToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmDeclinedRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmDeclinedRevertToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertDeclinedToQuality') . '" method="POST" id="crmDeclinedRevertToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmDeclinedRevertToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertDeclinedToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Not Attended to Attended Modal */
                    $html .= '<div id="crmRevertNotAttendedToAttendedModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmNotAttendedToAttendedModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmNotAttendedToAttendedModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Attended</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmNotAttendedToAttended') . '" method="POST" id="crmNotAttendedToAttendedForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmNotAttendedToAttendedDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmNotAttendedToAttendedButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Not Attended to Quality Modal */
                    $html .= '<div id="crmNotAttendedToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmNotAttendedToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmNotAttendedToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmNotAttendedToQuality') . '" method="POST" id="crmNotAttendedToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmNotAttendedToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmNotAttendedToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Start Date to Quality Modal */
                    $html .= '<div id="crmStartDateToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmStartDateToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmStartDateToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmStartDateToQuality') . '" method="POST" id="crmStartDateToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmStartDateToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmStartDateToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Start Date Hold to Quality Modal */
                    $html .= '<div id="crmStartDateHoldToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmStartDateHoldToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmStartDateHoldToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmStartDateHoldToQuality') . '" method="POST" id="crmStartDateHoldToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmStartDateHoldToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmStartDateHoldToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Invoice to Quality Modal */
                    $html .= '<div id="crmInvoiceToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmInvoiceToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmInvoiceToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmInvoiceToQuality') . '" method="POST" id="crmInvoiceToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmInvoiceToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmInvoiceToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Invoice Sent to Quality Modal */
                    $html .= '<div id="crmInvoiceSentToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmInvoiceSentToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmInvoiceSentToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmInvoiceSentToQuality') . '" method="POST" id="crmInvoiceSentToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmInvoiceSentToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmInvoiceSentToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Dispute to Quality Modal */
                    $html .= '<div id="crmDisputeToQualityModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmDisputeToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmDisputeToQualityModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Quality</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmDisputeToQuality') . '" method="POST" id="crmDisputeToQualityForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmDisputeToQualityDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmDisputeToQualityButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Attended to pre-start date Accept CV Modal */
                    $html .= '<div id="crmAttendedPreStartDateAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmAttendedPreStartDateAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmAttendedPreStartDateAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Attended To Pre-Start Date Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmAttendedPreStartDateAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmAttendedPreStartDateAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-primary crmAttendedToDeclineButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Decline</button>
                                                <button type="button" class="btn btn-primary crmAttendedToStartDateButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Start Date</button>
                                                <button type="button" class="btn btn-success crmAttendedSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Rebook Accept CV Modal */
                    $html .= '<div id="crmRebookAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRebookAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmRebookAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Rebook Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmRebookAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmRebookAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-primary crmRebookToNotAttendButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Not Attend</button>
                                                <button type="button" class="btn btn-primary crmRebookToAttendButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Attend</button>
                                                <button type="button" class="btn btn-success crmRebookSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Revert Rebook to Confirmation Modal */
                    $html .= '<div id="crmRevertRebookToConfirmationModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertRebookToConfirmationModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertRebookToConfirmationModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Confirmation</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertRebookToConfirmation') . '" method="POST" id="crmRevertRebookToConfirmationForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertRebookToConfirmationDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertRebookToConfirmationButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Rebook to Confirmation Modal */
                    $html .= '<div id="crmRevertAttendToRebookModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertAttendToRebookModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertAttendToRebookModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Rebook</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertAttendedToRebook') . '" method="POST" id="crmRevertAttendToRebookForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertAttendToRebookDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertAttendToRebookButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Revert Declined to Attended Modal */
                    $html .= '<div id="crmRevertDeclinedToAttendedModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertDeclinedToAttendedModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertDeclinedToAttendedModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Attended</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertDeclinedToAttended') . '" method="POST" id="crmRevertDeclinedToAttendedForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertDeclinedToAttendedDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertDeclinedToAttendedButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Start Date Accept CV Modal */
                    $html .= '<div id="crmStartDateAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmStartDateAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmStartDateAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Start Date Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmStartDateAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmStartDateAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-primary crmStartDateToInvoiceButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Invoice</button>
                                                <button type="button" class="btn btn-primary crmStartDateToHoldButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Start Date Hold</button>
                                                <button type="button" class="btn btn-success crmStartDateSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Revert Start Date to Attended Modal */
                    $html .= '<div id="crmRevertStartDateToAttendedModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertStartDateToAttendedModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertStartDateToAttendedModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Attended</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertStartDateToAttended') . '" method="POST" id="crmRevertStartDateToAttendedForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertStartDateToAttendedDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertStartDateToAttendedButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Start Date Hold Accept CV Modal */
                    $html .= '<div id="crmStartDateHoldAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmStartDateHoldAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmStartDateHoldAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Start Date Hold Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmStartDateHoldAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmStartDateHoldAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                <button type="button" class="btn btn-success crmStartDateHoldSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Revert Start Date Hold to Start Date Modal */
                    $html .= '<div id="crmRevertStartDateHoldToStartDateModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertStartDateHoldToStartDateModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertStartDateHoldToStartDateModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Start Date</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertStartDateHoldToStartDate') . '" method="POST" id="crmRevertStartDateHoldToStartDateForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertStartDateHoldToStartDateDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertStartDateHoldToStartDateButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Start Date Hold Accept CV Modal */
                    $html .= '<div id="crmInvoiceAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmInvoiceAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmInvoiceAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Invoice Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmInvoiceAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmInvoiceAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-primary crmInvoiceSendInvoiceButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Send Invoice</button>
                                                <button type="button" class="btn btn-primary crmInvoiceDisputeButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Dispute</button>
                                                <button type="button" class="btn btn-success crmInvoiceSaveButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Revert Invoice To Start Date Modal */
                    $html .= '<div id="crmRevertInvoiceToStartDateModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertInvoiceToStartDateModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertInvoiceToStartDateModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Start Date</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertInvoiceToStartDate') . '" method="POST" id="crmRevertInvoiceToStartDateForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertInvoiceToStartDateDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertInvoiceToStartDateButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Invoice Sent Accept CV Modal */
                    $html .= '<div id="crmInvoiceSentAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmInvoiceSentAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="crmInvoiceSentAcceptCVModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Invoice Sent Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                        <form action="" method="" id="crmInvoiceSentAcceptCVForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                            <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                            <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                            <div class="mb-3">
                                                <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                <textarea class="form-control" name="details" id="crmInvoiceSentAcceptCVDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                <div class="invalid-feedback">Please provide details.</div>
                                            </div>
                                            <div class="modal-footer">
                                            <button type="button" class="btn btn-primary crmInvoiceSentPaidButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Paid</button>
                                            <button type="button" class="btn btn-success crmInvoiceSentDisputeButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Dispute</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>';
                    /** CRM Revert Dispute To Invoice Modal */
                    $html .= '<div id="crmRevertDisputeToInvoiceModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmRevertDisputeToInvoiceModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmRevertDisputeToInvoiceModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM Revert In Invoice</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmRevertDisputeToInvoice') . '" method="POST" id="crmRevertDisputeToInvoiceForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <div class="mb-3">
                                                    <label for="details' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-label">Notes</label>
                                                    <textarea class="form-control" name="details" id="crmRevertDisputeToInvoiceDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" rows="4" required></textarea>
                                                    <div class="invalid-feedback">Please provide details.</div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmRevertDisputeToInvoiceButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Change Paid Status Modal */
                    $paid_status_title = ($applicant->paid_status == 'close') ? 'Open' : 'Close';
                    $paid_status_timestamp = Carbon::parse($applicant->paid_timestamp);
                    $content_details = 'Applicant CV has been ' . ucwords($applicant->paid_status) . ' since '
                        . $paid_status_timestamp->format('d M Y') . ' ('
                        . $paid_status_timestamp->diff(Carbon::now())->format('%y years, %m months and %d days')
                        . '). Are you sure you want to ' . $paid_status_title . ' it?';

                    $html .= '<div id="crmChangePaidStatusModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmChangePaidStatusModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-top">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="crmChangePaidStatusModalLabel' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">CRM ' . $paid_status_title . ' To ' . ucwords($applicant->applicant_name) . '\'s CV</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body modal-body-text-left">
                                            <div class="notificationAlert' . (int)$applicant->id . '-' . (int)$applicant->sale_id . ' notification-alert"></div>
                                            <form action="' . route('crmChangePaidStatus') . '" method="POST" id="crmChangePaidStatusForm' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" class="form-horizontal">
                                                <input type="hidden" name="applicant_id" value="' . (int)$applicant->id . '">
                                                <input type="hidden" name="sale_id" value="' . (int)$applicant->sale_id . '">
                                                <input type="hidden" id="paid_status-' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '" name="paid_status" value="' . strtolower($paid_status_title) . '">
                                                <div class="mb-3">
                                                    <span id="crmChangePaidStatusDetails' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '">' . $content_details . '</span>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="button" class="btn btn-success saveCrmChangePaidStatusButton" data-applicant-id="' . (int)$applicant->id . '" data-sale-id="' . (int)$applicant->sale_id . '">Yes!</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    return $html;
                })
                ->rawColumns(['notes_detail', 'applicant_email', 'sale_source_name', 'show_created_at', 'user_name', 'applicantPhone', 'schedule_date', 'paid_status', 'job_details', 'applicant_postcode', 'job_title', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }

    private function commonSelect(): array
    {
        return [
            'offices.office_name as office_name',
            'sales.id as sale_id',
            'sales.job_category_id as sale_category_id',
            'sales.job_title_id as sale_title_id',
            'sales.sale_postcode',
            'sales.job_type as sale_job_type',
            'sales.timing',
            'sales.salary',
            'sales.experience as sale_experience',
            'sales.qualification as sale_qualification',
            'sales.benefits',
            'sales.office_id as sale_office_id',
            'sales.unit_id as sale_unit_id',
            'sales.position_type',
            'sales.status as sale_status',
            'sales.created_at as sale_posted_date',
            'units.unit_name',
            'units.unit_postcode',
            'units.unit_website',
            'interviews.schedule_time',
            'interviews.schedule_date',
            'interviews.status as interview_status',
            'users.name as user_name',
        ];
    }

    /** Latest row per (applicant_id, sale_id) for a notes table, filtered by moved_tab_to. */
    private function latestNoteSub(
        string $table,
        array $movedTabTo,
        bool $useMin = false,
        bool $requireStatus = true,
        array $extraCols = []
    ) {
        $agg = $useMin ? 'MIN(id)' : 'MAX(id)';
        return DB::table('crm_notes as cn_latest')
            ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
            ->whereIn("cn_latest.moved_tab_to", ["declined"])
            // ->where('cn_latest.status', 1)
            ->whereIn('cn_latest.id', function ($q) {
                $q->selectRaw('MAX(id)')
                    ->from('crm_notes')
                    // ->where('status', 1)
                    ->whereIn("moved_tab_to", ["declined"])
                    ->groupBy('applicant_id', 'sale_id');
            });
    }

    private function latestCvNoteSub()
    {
        return DB::table('cv_notes')
            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
            ->whereIn('id', function ($sub) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('cv_notes')
                    ->groupBy('applicant_id', 'sale_id');
            });
    }

    /** sales -> offices -> units, with the existence check against `history`. */
    private function applyCoreJoins($model, string $notesAlias, array $subStages)
    {
        return $model
            ->join('sales', fn($j) => $j->on("{$notesAlias}.sale_id", '=', 'sales.id')->whereNull('sales.deleted_at'))
            ->join('offices', fn($j) => $j->on('sales.office_id', '=', 'offices.id'))
            ->join('units', fn($j) => $j->on('sales.unit_id', '=', 'units.id'))
            ->whereExists(function ($q) use ($notesAlias, $subStages) {
                $q->select(DB::raw(1))->from('history')
                    ->whereColumn('history.applicant_id', "{$notesAlias}.applicant_id")
                    ->whereColumn('history.sale_id', "{$notesAlias}.sale_id")
                    ->whereIn('history.sub_stage', $subStages)
                    ->where('history.status', 1);
            });
    }

    /** CRM Sent CV */
    public function updateCrmNotes(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
                'reason' => 'required'
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            if ($request->input('tab') == 'sent_cv') {
                // Private function might throw exceptions
                $this->crmSentSaveAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $request->input('sale_id'),
                    $details,
                    $request->input('reason')
                );
            } elseif ($request->input('tab') == 'open_cv') {
                // Private function might throw exceptions
                $this->crmOpenCvAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $request->input('sale_id'),
                    $details,
                    $request->input('reason')
                );
            }

            return response()->json(['success' => true, 'message' => 'CRM Notes Upated Successfully!']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmSendRequest(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Requested By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmSentRequestAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            if ($request->input('tab') == 'open_cv') {
                // Private function might throw exceptions
                $this->crmOpenCvSentRequestAction(
                    $request->input('applicant_id'),
                    $request->input('sale_id')
                );
            }

            return response()->json(['success' => true, 'message' => 'CRM Request Sent Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmSendRejectedCv(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
                'reason' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Rejected By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmSentCVToRejectCvAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details,
                $request->input('reason')
            );

            if ($request->input('tab') == 'open_cv') {
                // Private function might throw exceptions
                $this->crmOpenCvSentRequestAction(
                    $request->input('applicant_id'),
                    $request->input('sale_id')
                );
            }

            return response()->json(['success' => true, 'message' => 'CRM Sent Rejected Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertInQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Rejected By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where(['sale_id' => $sale_id, 'status' => 1])->count();
                // if ($sent_cv_count < $sale->send_cv_limit) {
                // Private function might throw exceptions
                $this->crmRevertCVInQualityAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $request->input('sale_id'),
                    $details
                );

                return response()->json(['success' => true, 'message' => 'CRM CV Reverted In Quality Successfully']);
                // }else{
                //     return response()->json(['success' => false, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                // }
            } else {
                return response()->json(['success' => false, 'message' => 'Oops! Sale record not found.']);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Sent No Job  */
    public function updateCrmNoJobNotes(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
                'reason' => 'required'
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmSentCvNoJobSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details,
                $request->input('reason')
            );

            return response()->json(['success' => true, 'message' => 'CRM No Job Notes Upated Successfully!']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmSendNoJobRequest(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
                'reason' => 'required'
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Requested By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmNoJobSentRequestAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details,
                $request->input('reason'),
            );

            return response()->json(['success' => true, 'message' => 'CRM No Job Request Sent Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmSendNoJobToRejectedCv(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
                'reason' => 'required'
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Rejected By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmNoJobSentRejectCvAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details,
                $request->input('reason'),
            );

            return response()->json(['success' => true, 'message' => 'CRM No Job Rejected Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmSentCvNoJobRevertInQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmSentCvNoJobRevertCVInQualityAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Reverted In Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Rejected Cv */
    public function crmRevertRejectedCvToSentCv(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;

            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where(['sale_id' => $sale_id, 'status' => 1])->count();

                // if ($sent_cv_count < $sale->send_cv_limit) {
                // Private function might throw exceptions
                $this->crmRevertRejectedCvToSentCvAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $request->input('sale_id'),
                    $details
                );

                return response()->json(['success' => true, 'message' => 'CRM Reverted In Sent CV Successfully']);
                // }else{
                //     return response()->json(['success' => false, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                // }
            } else {
                return response()->json(['success' => false, 'message' => 'Oops! Sale record not found.']);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRejectedCvToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $revertedInSentCv = $this->crmRevertRejectedCvToSentCvAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            if ($revertedInSentCv) {
                // Private function might throw exceptions
                $this->crmRevertCVInQualityAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $request->input('sale_id'),
                    $details
                );
            }

            return response()->json(['success' => true, 'message' => 'CRM Reverted In Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRequestConfirm(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Confirmed By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRequestConfirmAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Request Confirmed Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRequestSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Requested By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRequestSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Request Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmScheduleInterview(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer|exists:applicants,id',
                'sale_id' => 'required|integer|exists:sales,id',
                'schedule_date' => 'required|date',
                'schedule_time' => 'required'
            ]);

            $user = Auth::user();

            DB::beginTransaction();

            Interview::where('applicant_id', $request->input('applicant_id'))
                ->where('sale_id', $request->input('sale_id'))
                ->update([
                    'status' => 0
                ]);

            $interview = new Interview();
            $interview->user_id = $user->id;
            $interview->sale_id = $request->input('sale_id');
            $interview->applicant_id = $request->input('applicant_id');
            $interview->schedule_date = date('Y-m-d', strtotime($request->input('schedule_date')));
            $interview->schedule_time = date('H:i', strtotime($request->input('schedule_time')));

            $interview->save();

            $interview->interview_uid = md5((string) $interview->id);
            $interview->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'CRM Interview Scheduled Successfully'
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Request Reject */
    public function crmRequestReject(Request $request)
    {
        try {
            $validated = $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required|string',
                'to' => 'required|email',
                'subject' => 'required|string',
                'body' => 'required|string',
                '_token' => 'required'
            ]);

            DB::beginTransaction();

            $user = Auth::user();
            $details = $request->input('details') . ' --- Request Rejected By: ' . $user->name;

            // Transition: move applicant to request_reject
            $result = $this->crmRequestRejectAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            $sale_id = $request->input('sale_id');
            $applicant_id = $request->input('applicant_id');
            $sale = Sale::where('id', $sale_id)
                ->select('unit_id', 'id')
                ->first();

            $applicantRecord = Applicant::where("id", $applicant_id)
                ->select('applicant_name')
                ->first();

            if ($result && $sale && $applicantRecord) {
                $email_body = $request->input('body');
                $email_subject = $request->input('subject');
                $email_to = $request->input('to');


                $applicant_name = $applicantRecord ? ucwords(strtolower($applicantRecord->applicant_name)) : '';
                if (isset($request->slug)) {
                    $email_template = EmailTemplate::where('slug', $request->slug)->where('is_active', 1)->first();

                    $email_from = $email_template->from_email;
                    $email_title = $email_template->title;
                } else {
                    $email_from = 'customerservice@kingsburypersonnel.com';
                    $email_title = $applicant_name . ' - Request Rejected';
                }

                $emailNotification = Setting::where('key', 'email_notifications')->first();

                // Attempt to save email in DB
                if ($emailNotification && $emailNotification->value == '1') {
                    try {
                        $is_save = $this->saveEmailDB($email_to, $email_from, $email_subject, $email_body, $email_title, $applicant_id, $sale->id);
                        if (!$is_save) {
                            Log::warning('Email saved to DB failed for sale ID: ' . $sale->id);
                            throw new Exception('Email is not stored in DB');
                        }
                    } catch (Exception $e) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to save email: ' . $e->getMessage()
                        ], 500);
                    }
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to save email because email notifications are disabled. Contact to your admin.'
                    ], 500);
                }
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'CRM Request Rejected Successfully']);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    /*** CRM Mark Request Rejected */
    public function crmRequestNoResponseToReject(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required'
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Mark Request No Response To Rejected By: ' . $user->name;

            // Private function might throw exceptions
            $result = $this->crmSentCVToRejectCvAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details,
                ''
            );

            if ($result) {
                Notification::where('applicant_id', $request->input('applicant_id'))
                    ->where('sale_id', $request->input('sale_id'))
                    ->where('user_id', $user->id)
                    ->where('type', 'request_no_response')
                    ->delete();
            }

            return response()->json(['success' => true, 'message' => 'CRM Sent Rejected Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /*** CRM Mark Request Confirmed */
    public function crmRequestNoResponseToConfirmedRequest(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Mark No Responsed Request To Confirm Request By: ' . $user->name;

            // Private function might throw exceptions
            $result = $this->crmSentRequestAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            if ($result) {
                Notification::where('applicant_id', $request->input('applicant_id'))
                    ->where('sale_id', $request->input('sale_id'))
                    ->where('user_id', $user->id)
                    ->where('type', 'request_no_response')
                    ->delete();
            }

            return response()->json(['success' => true, 'message' => 'CRM Request Confirmed Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Request NO Response */
    public function crmRequestNoResponse(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required|string',
            ]);

            DB::beginTransaction();

            $user = Auth::user();
            $details = $request->input('details') . ' --- Request No Responsed By: ' . $user->name;

            // Transition: move applicant to request_reject
            $result = $this->crmRequestNoResponseAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            if ($result) {
                $cvNote = DB::table('cv_notes')
                    ->select('user_id')
                    ->whereIn('id', function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    })->where('sale_id', $request->input('sale_id'))
                    ->where('applicant_id', $request->input('applicant_id'));

                $notification = new Notification();
                $notification->user_id = $cvNote->exists() ? $cvNote->first()->user_id : null;
                $notification->notify_by = $user->id;
                $notification->sale_id = $request->input('sale_id');
                $notification->applicant_id = $request->input('applicant_id');
                $notification->type = 'request_no_response';
                $notification->message = 'Applicant has not responded to the request.';
                $notification->save();
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'CRM Request No Response Successfully']);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRequestRejectToSentCv(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $applicant_id = $request->input('applicant_id');
            $sale_id = $request->input('sale_id');

            $applicant = Applicant::find($applicant_id);
            $is_no_job = $applicant && $applicant->is_no_job == 1 ? true : false;

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($is_no_job) {
                    // Private function might throw exceptions
                    $this->crmNoJobRequestRejectedRevertToSentCvAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );
                } else {
                    // if ($sent_cv_count < $sale->cv_limit) {
                    // Private function might throw exceptions
                    $this->crmRequestRejectedRevertToSentCvAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );
                    // }else{
                    //     return response()->json(['error' => true, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                    // }
                }

                return response()->json(['success' => true, 'message' => 'CRM Reverted To Sent CV Successfully']);
            } else {
                return response()->json(['error' => true, 'message' => 'Sale record not found!']);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRequestRejectToRequest(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');
            $applicant_id = $request->input('applicant_id');

            $applicant = Applicant::find($applicant_id);
            $is_no_job = $applicant && $applicant->is_no_job == 1 ? true : false;

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($is_no_job) {
                    // Private function might throw exceptions
                    $this->crmNoJobRequestRejectedRevertToRequestAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );
                } else {
                    if ($sent_cv_count < $sale->cv_limit) {
                        // Private function might throw exceptions
                        $this->crmRequestRejectedRevertToRequestAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $sale_id,
                            $details
                        );
                    } else {
                        return response()->json(['success' => false, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                    }
                }

                return response()->json(['success' => true, 'message' => 'CV Reverted To Request Successfully']);
            }

            return response()->json(['success' => false, 'message' => 'Sale Not Found']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ActionRefusedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRequestRejectToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $this->crmRequestRejectedRevertToSentCvAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    /** Second revert in Quality */
                    $this->crmRevertCVInQualityAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $request->input('sale_id'),
                        $details
                    );
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRequestedCvToSentCv(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRevertRequestToSentCvAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Reverted In Sent CV Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRequestedCvToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            if ($revertedInSentCv) {
                // Private function might throw exceptions
                $this->crmRevertCVInQualityAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $request->input('sale_id'),
                    $details
                );
            }

            return response()->json(['success' => true, 'message' => 'CRM Reverted In Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Confirmation tab*/
    public function crmConfirmInterviewToNotAttend(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmInterviewNotAttendedAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Not Attend Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRequestedInterviewEmailToApplicant(Request $request)
    {
        // Validate request data
        $validatedData = $request->validate([
            'email_to' => 'required|email',
            // 'email_from' => 'required|email',
            'email_subject' => 'required|max:255',
            'email_body' => 'required',
            'applicant_id' => 'required|integer',
            'sale_id' => 'required|integer',
        ]);

        $emailNotification = Setting::where('key', 'email_notifications')->first();

        // Attempt to save email in DB
        if ($emailNotification && $emailNotification->value == '1') {
            try {
                // Attempt to send email
                $is_save = $this->saveEmailDB(
                    $validatedData['email_to'],
                    // $validatedData['email_from'], 
                    'crm@kingsburypersonnel.com',
                    $validatedData['email_subject'],
                    $validatedData['email_body'],
                    $validatedData['email_subject'],
                    $validatedData['applicant_id'],
                    $validatedData['sale_id']
                );

                if (!$is_save) {
                    // Optional: throw or log
                    Log::warning('Email saved to DB failed for applicant ID: ' . $validatedData['applicant_id']);
                    throw new Exception('Email is not stored in DB');
                }

                return response()->json(['success' => true, 'message' => 'Email sent successfully']);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send email: ' . $e->getMessage()
                ], 500);
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save email because email notifications are disabled. Contact to your admin.'
            ], 500);
        }
    }
    public function crmRevertConfirmToRequest(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Request Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmConfirmationRevertToRequestAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Request Revert Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertConfirmToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    // Private function might throw exceptions
                    $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $request->input('sale_id'),
                        $details
                    );

                    if ($revertedInRequest) {
                        // Private function might throw exceptions
                        $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $request->input('sale_id'),
                            $details
                        );

                        if ($revertedInSentCv) {
                            // Private function might throw exceptions
                            $this->crmRevertCVInQualityAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmConfirmInterviewToAttend(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Confirmed By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmInterviewAttendedAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Attend Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmConfirmSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Confirmed By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmInterviewSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmConfirmInterviewToRebook(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Rebooked By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmInterviewRebookAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Rebook Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Rebook */
    public function crmRebookSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRebookSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Rebook Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRebookToNotAttended(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRebookToNotAttendedAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Not Attended Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRebookToAttended(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRebookToAttendedAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Interview Attended Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRebookToConfirmation(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Rebook Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRevertRebookToConfirmationAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Rebook Revert Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertRebookToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    // Private function might throw exceptions
                    $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $request->input('sale_id'),
                        $details
                    );

                    if ($revertRebookToConfirm) {
                        // Private function might throw exceptions
                        $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $request->input('sale_id'),
                            $details
                        );

                        if ($revertedInRequest) {
                            // Private function might throw exceptions
                            $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($revertedInSentCv) {
                                // Private function might throw exceptions
                                $this->crmRevertCVInQualityAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Attended */
    public function crmRevertAttendedToRebook(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Rebook Reverted By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmRevertAttendedToRebookAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Rebook Revert Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmAttendedToStartDate(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Started Date By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmAttendedToStartDateAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Start Date Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmAttendedToDecline(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Declined By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmAttendedToDeclineAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Decline Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmAttendedSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;

            // Private function might throw exceptions
            $this->crmPreStartDateAction(
                $request->input('applicant_id'),
                $user->id,
                $request->input('sale_id'),
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Started Date Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertAttendedToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    // Private function might throw exceptions
                    $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $request->input('sale_id'),
                        $details
                    );

                    if ($crmRevertinRebook) {
                        // Private function might throw exceptions
                        $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $request->input('sale_id'),
                            $details
                        );

                        if ($revertRebookToConfirm) {
                            // Private function might throw exceptions
                            $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($revertedInRequest) {
                                // Private function might throw exceptions
                                $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($revertedInSentCv) {
                                    // Private function might throw exceptions
                                    $this->crmRevertCVInQualityAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Not Attended */
    public function crmNotAttendedToAttended(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                // if ($sent_cv_count < $sale->cv_limit) {
                /** First revert in Sent CV */
                $this->crmInterviewNotAttendedToAttendedAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $sale_id,
                    $details
                );

                return response()->json(['success' => true, 'message' => 'CV Reverted To Attended Successfully']);
                // }else{
                //     return response()->json(['error' => true, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                // }
            } else {
                return response()->json(['error' => true, 'message' => 'Sale record not found!']);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmNotAttendedToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $crmNotAttendedToAttend = $this->crmInterviewNotAttendedToAttendedAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($crmNotAttendedToAttend) {
                        // Private function might throw exceptions
                        $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $request->input('sale_id'),
                            $details
                        );

                        if ($crmRevertinRebook) {
                            // Private function might throw exceptions
                            $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($revertRebookToConfirm) {
                                // Private function might throw exceptions
                                $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($revertedInRequest) {
                                    // Private function might throw exceptions
                                    $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertedInSentCv) {
                                        // Private function might throw exceptions
                                        $this->crmRevertCVInQualityAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Decline */
    public function crmRevertDeclinedToAttended(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                // if ($sent_cv_count < $sale->cv_limit) {
                /** First revert in Sent CV */
                $this->crmRevertDeclineToAttendedAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $sale_id,
                    $details
                );

                return response()->json(['success' => true, 'message' => 'CV Reverted To Attended Successfully']);
                // }else{
                //     return response()->json(['error' => true, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                // }
            } else {
                return response()->json(['error' => true, 'message' => 'Sale record not found!']);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ActionRefusedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertDeclinedToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $crmRevertToAttended = $this->crmRevertDeclineToAttendedAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($crmRevertToAttended) {
                        // Private function might throw exceptions
                        $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $request->input('sale_id'),
                            $details
                        );

                        if ($crmRevertinRebook) {
                            // Private function might throw exceptions
                            $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($revertRebookToConfirm) {
                                // Private function might throw exceptions
                                $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($revertedInRequest) {
                                    // Private function might throw exceptions
                                    $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertedInSentCv) {
                                        // Private function might throw exceptions
                                        $this->crmRevertCVInQualityAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ActionRefusedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Start Date */
    public function crmRevertStartDateToAttended(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmRevertStartDateToAttendedAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CV Reverted To Attended Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmStartDateToInvoice(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Invoiced By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $this->crmStartDateToInvoiceAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CRM Invoice Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmStartDateToHold(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Start Date Hold By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmStartDateHoldAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Start Date Hold Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmStartDateSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmStartDateSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Start Date Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmStartDateToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $crmRevertToAttended = $this->crmRevertStartDateToAttendedAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($crmRevertToAttended) {
                        // Private function might throw exceptions
                        $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $request->input('sale_id'),
                            $details
                        );

                        if ($crmRevertinRebook) {
                            // Private function might throw exceptions
                            $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($revertRebookToConfirm) {
                                // Private function might throw exceptions
                                $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($revertedInRequest) {
                                    // Private function might throw exceptions
                                    $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertedInSentCv) {
                                        // Private function might throw exceptions
                                        $this->crmRevertCVInQualityAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Start Date Hold*/
    public function crmRevertStartDateHoldToStartDate(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                // if ($sent_cv_count < $sale->cv_limit) {
                /** First revert in Sent CV */
                $this->crmRevertStartDateHoldToStartDateAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $sale_id,
                    $details
                );

                return response()->json(['success' => true, 'message' => 'CV Reverted To Start Date Successfully']);

                // }else{
                //     return response()->json(['success' => false, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                // }
            }

            return response()->json(['success' => false, 'message' => 'Sale record not found!']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmStartDateHoldSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmStartDateHoldSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Start Date Hold Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmStartDateHoldToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $revertToStartDate = $this->crmRevertStartDateHoldToStartDateAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($revertToStartDate) {
                        /** First revert in Sent CV */
                        $crmRevertToAttended = $this->crmRevertStartDateToAttendedAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $sale_id,
                            $details
                        );

                        if ($crmRevertToAttended) {
                            // Private function might throw exceptions
                            $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($crmRevertinRebook) {
                                // Private function might throw exceptions
                                $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($revertRebookToConfirm) {
                                    // Private function might throw exceptions
                                    $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertedInRequest) {
                                        // Private function might throw exceptions
                                        $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );

                                        if ($revertedInSentCv) {
                                            // Private function might throw exceptions
                                            $this->crmRevertCVInQualityAction(
                                                $request->input('applicant_id'),
                                                $user->id,
                                                $request->input('sale_id'),
                                                $details
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Invoice*/
    public function crmSendInvoiceToInvoiceSent(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Invoice Sent By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmInvoiceToInvoiceSentAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Invoice Sent Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmInvoiceToDispute(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Disputed By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmInvoiceToDisputeAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Invoice To Dispute Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmRevertInvoiceToStartDate(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $this->crmRevertInvoiceToStartDateAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    return response()->json(['success' => true, 'message' => 'CV Reverted To Start Date Successfully']);
                } else {
                    return response()->json(['success' => false, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => false, 'message' => 'Sale Not Found']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmInvoiceFinalSave(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Final By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmFinalSaveAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Invoice Final Saved Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmInvoiceToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $revertToStartDate = $this->crmRevertInvoiceToStartDateAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($revertToStartDate) {
                        /** First revert in Sent CV */
                        $crmRevertToAttended = $this->crmRevertStartDateToAttendedAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $sale_id,
                            $details
                        );

                        if ($crmRevertToAttended) {
                            // Private function might throw exceptions
                            $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $request->input('sale_id'),
                                $details
                            );

                            if ($crmRevertinRebook) {
                                // Private function might throw exceptions
                                $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($revertRebookToConfirm) {
                                    // Private function might throw exceptions
                                    $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertedInRequest) {
                                        // Private function might throw exceptions
                                        $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );

                                        if ($revertedInSentCv) {
                                            // Private function might throw exceptions
                                            $this->crmRevertCVInQualityAction(
                                                $request->input('applicant_id'),
                                                $user->id,
                                                $request->input('sale_id'),
                                                $details
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Invoice Sent */
    public function crmInvoiceSentToPaid(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Invoice Paid By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmInvoiceSentToPaidAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Invoice Paid Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmInvoiceSentToDispute(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Disputed By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            /** First revert in Sent CV */
            $this->crmInvoiceSentToDisputeAction(
                $request->input('applicant_id'),
                $user->id,
                $sale_id,
                $details
            );

            return response()->json(['success' => true, 'message' => 'CRM Invoice To Dispute Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmInvoiceSentToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $revertToInvoice = $this->crmInvoiceSentToInvoiceAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($revertToInvoice) {
                        /** First revert in Sent CV */
                        $revertToStartDate = $this->crmRevertInvoiceToStartDateAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $sale_id,
                            $details
                        );

                        if ($revertToStartDate) {
                            /** First revert in Sent CV */
                            $crmRevertToAttended = $this->crmRevertStartDateToAttendedAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $sale_id,
                                $details
                            );

                            if ($crmRevertToAttended) {
                                // Private function might throw exceptions
                                $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($crmRevertinRebook) {
                                    // Private function might throw exceptions
                                    $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertRebookToConfirm) {
                                        // Private function might throw exceptions
                                        $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );

                                        if ($revertedInRequest) {
                                            // Private function might throw exceptions
                                            $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                                $request->input('applicant_id'),
                                                $user->id,
                                                $request->input('sale_id'),
                                                $details
                                            );

                                            if ($revertedInSentCv) {
                                                // Private function might throw exceptions
                                                $this->crmRevertCVInQualityAction(
                                                    $request->input('applicant_id'),
                                                    $user->id,
                                                    $request->input('sale_id'),
                                                    $details
                                                );
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Dispute */
    public function crmRevertDisputeToInvoice(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                // if ($sent_cv_count < $sale->cv_limit) {
                /** First revert in Sent CV */
                $this->crmRevertDisputeToInvoiceAction(
                    $request->input('applicant_id'),
                    $user->id,
                    $sale_id,
                    $details
                );

                return response()->json(['success' => true, 'message' => 'CV Reverted To Start Date Successfully']);

                // }else{
                //     return response()->json(['success' => false, 'message' => 'Oops! You can`t proceed right now. The CV limit for this sale has already been reached.']);
                // }
            }

            return response()->json(['success' => false, 'message' => 'Sale record not found!']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmDisputeToQuality(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'details' => 'required',
            ]);

            $user = Auth::user();
            $details = $request->input('details') . ' --- Reverted By: ' . $user->name;
            $sale_id = $request->input('sale_id');

            $sale = Sale::find($sale_id);
            if ($sale) {
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale_id,
                    'status' => 1
                ])->count();

                if ($sent_cv_count < $sale->cv_limit) {
                    /** First revert in Sent CV */
                    $revertToInvoice = $this->crmRevertDisputeToInvoiceAction(
                        $request->input('applicant_id'),
                        $user->id,
                        $sale_id,
                        $details
                    );

                    if ($revertToInvoice) {
                        /** First revert in Sent CV */
                        $revertToStartDate = $this->crmRevertInvoiceToStartDateAction(
                            $request->input('applicant_id'),
                            $user->id,
                            $sale_id,
                            $details
                        );

                        if ($revertToStartDate) {
                            /** First revert in Sent CV */
                            $crmRevertToAttended = $this->crmRevertStartDateToAttendedAction(
                                $request->input('applicant_id'),
                                $user->id,
                                $sale_id,
                                $details
                            );

                            if ($crmRevertToAttended) {
                                // Private function might throw exceptions
                                $crmRevertinRebook = $this->crmRevertAttendedToRebookAction(
                                    $request->input('applicant_id'),
                                    $user->id,
                                    $request->input('sale_id'),
                                    $details
                                );

                                if ($crmRevertinRebook) {
                                    // Private function might throw exceptions
                                    $revertRebookToConfirm = $this->crmRevertRebookToConfirmationAction(
                                        $request->input('applicant_id'),
                                        $user->id,
                                        $request->input('sale_id'),
                                        $details
                                    );

                                    if ($revertRebookToConfirm) {
                                        // Private function might throw exceptions
                                        $revertedInRequest = $this->crmConfirmationRevertToRequestAction(
                                            $request->input('applicant_id'),
                                            $user->id,
                                            $request->input('sale_id'),
                                            $details
                                        );

                                        if ($revertedInRequest) {
                                            // Private function might throw exceptions
                                            $revertedInSentCv = $this->crmRevertRequestToSentCvAction(
                                                $request->input('applicant_id'),
                                                $user->id,
                                                $request->input('sale_id'),
                                                $details
                                            );

                                            if ($revertedInSentCv) {
                                                // Private function might throw exceptions
                                                $this->crmRevertCVInQualityAction(
                                                    $request->input('applicant_id'),
                                                    $user->id,
                                                    $request->input('sale_id'),
                                                    $details
                                                );
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    return response()->json(['error' => true, 'message' => 'Unable to proceed: You have reached the maximum number of CVs that can be submitted for this sale.']);
                }
            }

            return response()->json(['success' => true, 'message' => 'CV Reverted To Quality Successfully']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /** CRM Paid */
    public function crmChangePaidStatus(Request $request)
    {
        try {
            $request->validate([
                'applicant_id' => 'required|integer',
                'sale_id' => 'required|integer',
                'paid_status' => 'required',
                // 'details' => 'required',
            ]);

            $user = Auth::user();
            $details = 'Change paid status as ' . $request->input('paid_status') . ' --- By: ' . $user->name;
            $applicant_id = $request->input('applicant_id');
            $sale_id = $request->input('sale_id');

            $applicant = Applicant::find($applicant_id);

            if ($applicant) {
                $msg = '';
                $update_paid_status = '';

                if ($request->input('paid_status') == 'open') {
                    $audit_data['action'] = "Open Applicant CV";
                    $update_paid_status = 'open';
                    $msg = 'Opened';
                } elseif ($request->input('paid_status') == 'close') {
                    $audit_data['action'] = "Close Applicant CV";
                    $update_paid_status = 'close';
                    $msg = 'Closed';
                }
                $update_columns = ['paid_status' => $update_paid_status, 'paid_timestamp' => Carbon::now()];
                $applicant->update($update_columns);

                $audit = new ActionObserver();
                $audit->customApplicantAudit($applicant, 'paid_status');

                return response()->json(['success' => true, 'message' => 'CRM Changed Paid Status ' . $msg . ' Successfully']);
            }

            return response()->json(['success' => false, 'message' => 'Applicant Not Found']);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Operation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function crmOpenToPaidApplicants()
    {
        $applicants = Applicant::join('crm_notes', 'applicants.id', '=', 'crm_notes.applicant_id')
            ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
            ->join('offices', 'sales.office_id', '=', 'offices.id')
            ->join('units', 'sales.unit_id', '=', 'units.id')
            ->join('history', function ($join) {
                $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                $join->on('crm_notes.sale_id', '=', 'history.sale_id');
            })
            ->where([
                'applicants.status' => 1,
                'applicants.paid_status' => 'close',
                'crm_notes.moved_tab_to' => 'paid',
                'history.sub_stage' => 'crm_paid',
                'history.status' => 1
            ])
            ->whereDate('applicants.paid_timestamp', '<', Carbon::now()->subMonths(5))
            ->whereDate('crm_notes.updated_at', '<', Carbon::now()->subMonths(5))
            ->orderBy("crm_notes.updated_at", "DESC")
            ->select('applicants.id') // reduce unnecessary fields
            ->get();

        $updatedCount = 0;

        if ($applicants->isNotEmpty()) {
            foreach ($applicants as $applicant) {
                $update_columns = [
                    'paid_status' => 'open',
                    'paid_timestamp' => Carbon::now(),
                ];

                $updated = Applicant::where('id', $applicant->id)->update($update_columns);
                if ($updated) {
                    $updatedCount++;
                    // Optional: Log audit if needed
                    // (new ActionObserver())->changeCvStatus($applicant->id, $update_columns, 'Opened');
                }
            }

            return response()->json([
                'success' => $updatedCount > 0,
                'message' => $updatedCount > 0
                    ? "{$updatedCount} applicants marked as Open successfully."
                    : "No applicant status updated."
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No matching applicants found.'
        ]);
    }
    public function crmPaidRevertToInvoiceSent(Request $request)
    {
        $request->validate([
            'applicant_id' => 'required|integer',
            'sale_id' => 'required|integer',
        ]);

        $applicant_id = $request->applicant_id;
        $sale_id      = $request->sale_id;

        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_paid' => false,
                    'is_in_crm_invoice' => false,
                    'is_in_crm_invoice_sent' => true,
                    'paid_status' => 'pending',
                    'paid_timestamp' => null
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1,
                'moved_tab_to' => "paid"
            ])->update(['status' => 0]);
            // ->orderByDesc('id')
            // ->limit(1)
            // ->delete();

            CrmNote::where([
                'applicant_id' => $applicant_id,
                'sale_id'      => $sale_id,
                'status'       => 0,
                'moved_tab_to' => "invoice_sent"
            ])
                ->orderByDesc('id')
                ->limit(1)
                ->update(['status' => 1]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1,
                'sub_stage' => 'crm_paid',
            ])->update(['status' => 0]);
            // ->orderByDesc('id')
            // ->limit(1)
            // ->delete();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0,
                'sub_stage' => 'crm_invoice_sent'
            ])
                ->orderByDesc('id')
                ->limit(1)
                ->update(["status" => 1]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
            ])->update(["status" => 1]); //active

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            Log::error("Error in crmPaidAction: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** Get CRM Notes History */
    public function getApplicantCrmNotesHistoryAjaxRequest(Request $request)
    {
        $applicant_id = $request->applicant_id;
        $sale_id = $request->sale_id;

        // Prepare CRM Notes query
        $model = CrmNote::query()
            ->select('crm_notes.*')
            ->where('applicant_id', $applicant_id)
            ->where('sale_id', $sale_id);

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('created_at', 'desc');
            }
        } else {
            $model->orderBy('created_at', 'desc');
        }

        // Apply search filter BEFORE sending to DataTables
        if ($request->has('search.value')) {
            $searchTerm = $request->input('search.value');
            $model->where(function ($query) use ($searchTerm) {
                $query->where('crm_notes.moved_tab_to', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('crm_notes.details', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Handle AJAX request
        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->addColumn('created_at', function ($row) {
                    return Carbon::parse($row->created_at)->format('d M Y, h:iA');
                })
                ->addColumn('moved_tab_to', function ($row) {
                    return '<span class="badge bg-primary">' . ucwords(str_replace('_', ' ', $row->moved_tab_to)) . '</span>';
                })
                ->addColumn('details', function ($row) {
                    $short = Str::limit(strip_tags($row->details), 250);
                    $full = e($row->details);
                    $id = 'exp-' . $row->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('status', function ($row) {
                    return $row->status == 1
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-danger">Inactive</span>';
                })
                ->rawColumns(['details', 'status', 'moved_tab_to'])
                ->make(true);
        }
    }

    /** Get CRM Notes History */
    public function getApplicantCrmNotes(Request $request)
    {
        try {
            $applicant_id = $request->applicant_id;
            $sale_id = $request->sale_id;

            // Prepare CRM Notes query
            $model = CrmNote::query()
                ->select('crm_notes.*')
                ->where('applicant_id', $applicant_id)
                ->where('sale_id', $sale_id)->latest()->get();

            // Check if the module note was found
            if (!$model) {
                return response()->json(['error' => 'CRM notes not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $model,
                'success' => true
            ]);
        } catch (Exception $e) {
            // If an error occurs, catch it and return a meaningful error message
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again later.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500); // Internal server error
        }
    }

    /************************ Private Functions ***************/
    private function generateJobDetailsModal(Applicant $applicant)
    {
        $modalId = 'jobDetailsModal_' . $applicant->sale_id;  // Unique modal ID for each applicant's job details

        return '<div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . 'Label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-top modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="' . $modalId . 'Label">Job Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body modal-body-text-left">
                                <!-- Job details content will be dynamically inserted here -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>';
    }

    private function saveSentEmails($email_to, $email_cc, $email_from, $email_title, $email_subject, $email_body, $action_name, $applicant_id = null, $sale_id = null)
    {
        $sent_email = new SentEmail();
        $sent_email->action_name = $action_name;
        $sent_email->sent_from = $email_from;
        $sent_email->sent_to = $email_to;
        $sent_email->cc_emails = $email_cc;
        $sent_email->subject = $email_subject;
        $sent_email->title = $email_title;
        $sent_email->template = $email_body;
        $sent_email->applicant_id = $applicant_id;
        $sent_email->sale_id = $sale_id;
        $sent_email->user_id = Auth::user()->id;

        if ($action_name == 'Random Email') {
            $sent_email->status = '0';
        }
        $sent_email->save();

        if ($sent_email) {
            return true;
        } else {
            return false;
        }
    }

    /** No Job CRM Actions */
    private function crmNoJobRequestRejectedRevertToSentCvAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            // CVNote::where([
            //     'applicant_id' => $applicant_id,
            //     'sale_id' => $sale_id,
            //     'status' => 0
            // ])->orderBy('id', 'desc')  // Get the latest record
            //     ->limit(1)              // Only one record
            //     ->update(['status' => 1]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cleared_no_job"
            ])->orderBy('id', 'desc')  // Get the latest record
                ->limit(1)
                ->update(["status" => 1]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->whereIn("moved_tab_to", [
                "cv_sent",
                "cv_sent_saved",
                "cv_sent_request",
                "cv_sent_no_job"
            ])
                ->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_no_job";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'quality_cleared_no_job';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();
            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRequestRejectedRevertToSentCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmNoJobRequestRejectedRevertToRequestAction($applicant_id, $user_id, $sale_id, $details)
    {
        // One transaction: a failure part-way (e.g. a missing note) leaves nothing half-reverted.
        return DB::transaction(function () use ($applicant_id, $user_id, $sale_id, $details) {
            try {
                // CVNote::where([
                //     "applicant_id" => $applicant_id,
                //     "sale_id" => $sale_id,
                //     "status" => 0
                // ])
                // ->orderBy('id', 'desc')  // Get the latest record
                // ->limit(1)
                // ->update(["status" => 1]);

                /*** get latest sent cv record */
                $latest_sent_cv = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "cv_sent_no_job_request")
                    ->latest()->first();
                if (!$latest_sent_cv) {           // nothing to revert to: roll back and say why
                    throw new ActionRefusedException("There is no 'CV sent' note to revert to for this applicant and sale.");
                }

                $all_cv_sent_saved = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "cv_sent_saved")
                    ->where('created_at', '>=', $latest_sent_cv->created_at)
                    ->get();

                $crm_notes_ids[0] = $latest_sent_cv->id;
                foreach ($all_cv_sent_saved as $cv) {
                    $crm_notes_ids[] = $cv->id;
                }

                CrmNote::whereIn('id', $crm_notes_ids)
                    ->update(["status" => 1]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user_id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "cv_sent_no_job_request";
                $crm_notes->save();

                //update uid
                $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
                $crm_notes->save();

                History::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                $history = new History();
                $history->applicant_id = $applicant_id;
                $history->user_id = $user_id;
                $history->sale_id = $sale_id;
                $history->stage = 'crm';
                $history->sub_stage = 'crm_no_job_request';
                $history->save();

                //update uid
                $history->history_uid = md5((string) $history->id);
                $history->save();

                return true; // Indicate success

            } catch (Exception $e) {
                // Log the error for debugging
                Log::error("Error in crmRequestRejectedRevertToRequestAction: " . $e->getMessage());

                // Re-throw the exception to be caught by the calling method
                throw $e;
            }
        });
    }
    /** No Job CRM Actions */

    /** CRM Sent Cv */
    private function crmSentSaveAction($applicant_id, $user_id, $sale_id, $details, $reject_reason)
    {
        try {
            /** update to the existing active note of requested applicant_id and sale_id */
            CrmNote::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'status' => 1,
                'moved_tab_to' => 'cv_sent_saved'
            ])->update(['status' => 0]);

            // Create CRM note
            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_saved";
            $crm_notes->save();

            // Update UID
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            // Update history status
            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            // Create new history record
            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_save';
            $history->save();

            // Update history UID
            $history->history_uid = md5((string) $history->id);  // Cast int to string
            $history->save();

            // Handle reject reason cases
            if (in_array($reject_reason, ['position_filled', 'agency', 'manager'])) {
                $sale = Sale::findOrFail($sale_id);

                if ($reject_reason == 'position_filled') {
                    $audit = new ActionObserver();
                    $audit->changeSaleStatus($sale, ['status' => 0]);
                    $sale->update(['status' => 0, 'sale_notes' => $details, 'is_on_hold' => false]);
                } else {
                    // Just touch the record to update updated_at
                    $sale->touch();
                }

                // Update history status
                SaleNote::where([
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                // Create sale note
                $sale_note = new SaleNote();
                $sale_note->sale_id = $sale_id;
                $sale_note->user_id = $user_id;
                $sale_note->sale_note = $details;
                $sale_note->save();

                // Update sale note UID
                $sale_note->sales_notes_uid = md5((string) $sale_note->id);
                $sale_note->save();
            }

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmSentCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmSentRequestAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_request' => true,
                    'is_interview_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_request";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cleared",
                "status" => 1
            ])->update(["status" => 0]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmSentRequestAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRevertCVInQualityAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)->update([
                'is_interview_confirm' => false,
                'is_cv_in_quality_clear' => false,
                'is_cv_in_quality_reject' => true,
                'is_cv_in_quality' => false
            ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'moved_tab_to' => 'cleared',
                'status' => 1,
            ])->delete();
            // ->update([
            //     'status' => 0,
            //     'updated_at' => DB::raw('updated_at'),
            // ]);

            $quality_notes = new QualityNotes();
            $quality_notes->applicant_id = $applicant_id;
            $quality_notes->user_id = $user_id;
            $quality_notes->sale_id = $sale_id;
            $quality_notes->details = $details;
            $quality_notes->moved_tab_to = "rejected";
            $quality_notes->save();

            /** Update UID */
            $quality_notes->quality_notes_uid = md5((string) $quality_notes->id);
            $quality_notes->save();

            $cvNoteToRevert = CVNote::where([
                'sale_id'       => $sale_id,
                'applicant_id'  => $applicant_id,
                'status'        => 1,
            ])->first();

            if ($cvNoteToRevert) {
                ClearedRevertCv::create([
                    'cv_note_id'          => $cvNoteToRevert->id,
                    'applicant_id'        => $applicant_id,
                    'sale_id'             => $sale_id,
                    'user_id'             => $cvNoteToRevert->user_id,
                    'reverted_by'         => $user_id,
                    'details'             => $details,
                    'created_at'  => $cvNoteToRevert->created_at,
                    'updated_at'  => $cvNoteToRevert->updated_at,
                ]);
            }

            CVNote::where([
                'sale_id' => $sale_id,
                'applicant_id' => $applicant_id,
                'status' => 1
            ])->update(['status' => 0]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                'status' => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'quality';
            $history->sub_stage = 'quality_reject';
            $history->save();

            /** Update UID */
            $history->history_uid = md5((string) $history->id);
            $history->save();

            RevertStage::create([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'stage' => 'crm_revert',
                'user_id' => $user_id,
                'notes' => $details,
            ]);

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmSentCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmSentCVToRejectCvAction($applicant_id, $user_id, $sale_id, $details, $reject_reason)
    {
        try {
            Applicant::where("id", $applicant_id)->update([
                'is_in_crm_reject' => true,
                'is_interview_confirm' => false
            ]);

            CrmNote::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'status' => 1
            ])->update(['status' => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_reject";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            $crm_rejected_cv = new CrmRejectedCv();
            $crm_rejected_cv->applicant_id = $applicant_id;
            $crm_rejected_cv->sale_id = $sale_id;
            $crm_rejected_cv->user_id = $user_id;
            $crm_rejected_cv->crm_note_id = $crm_notes->id;
            $crm_rejected_cv->reason = $reject_reason;
            $crm_rejected_cv->crm_rejected_cv_note = $details;
            $crm_rejected_cv->save();

            //update uid
            $crm_rejected_cv->crm_rejected_cv_uid = md5((string) $crm_rejected_cv->id);
            $crm_rejected_cv->save();

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_reject';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            // Handle reject reason cases
            if (in_array($reject_reason, ['position_filled', 'agency', 'manager'])) {
                $sale = Sale::findOrFail($sale_id);

                if ($reject_reason == 'position_filled') {
                    $audit = new ActionObserver();
                    $audit->changeSaleStatus($sale, ['status' => 0]);
                    $sale->update(['status' => 0, 'sale_notes' => $details, 'is_on_hold' => false]);
                } else {
                    // Just touch the record to update updated_at
                    $sale->touch();
                }

                // Update history status
                SaleNote::where([
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                // Create sale note
                $sale_note = new SaleNote();
                $sale_note->sale_id = $sale_id;
                $sale_note->user_id = $user_id;
                $sale_note->sale_note = $details;
                $sale_note->save();

                // Update sale note UID
                $sale_note->sales_notes_uid = md5((string) $sale_note->id);
                $sale_note->save();
            }

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmSentRejectCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Rejected CV */
    private function crmRevertRejectedCvToSentCvAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            $crm_note_id = CrmNote::where('applicant_id', $applicant_id)
                ->where('sale_id', $sale_id)
                ->whereIn('moved_tab_to', [
                    'cv_sent_reject',
                    'cv_sent_reject_no_job'
                ])
                ->latest()
                ->value('id');

            CrmRejectedCv::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                'crm_note_id' => $crm_note_id,
                "status" => 1
            ])->update(["status" => 0]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0
            ])
                ->update(["status" => 1]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0
            ])->update(["status" => 1]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])
                ->whereIn("moved_tab_to", ["cv_sent", "cv_sent_saved", "cv_sent_reject"])
                ->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertRejectedToSentCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Sent No Job */
    private function crmSentCvNoJobRevertCVInQualityAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            $user_name = User::find($user_id);
            $details = request()->details . " --- Reverted By: " . $user_name->name;

            Applicant::where("id", $applicant_id)
                ->update([
                    'is_interview_confirm' => false,
                    'is_cv_in_quality_clear' => false,
                    'is_cv_in_quality_reject' => true,
                    'is_cv_in_quality' => false
                ]);

            QualityNotes::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'moved_tab_to' => 'cleared',
                'status' => 1
            ])->update(['status' => 0]);

            $quality_notes = new QualityNotes();
            $quality_notes->applicant_id = $applicant_id;
            $quality_notes->user_id = $user_id;
            $quality_notes->sale_id = $sale_id;
            $quality_notes->details = $details;
            $quality_notes->moved_tab_to = "rejected";
            $quality_notes->save();

            /** Update UID */
            $quality_notes->quality_notes_uid = md5((string) $quality_notes->id);
            $quality_notes->save();

            CVNote::where([
                'sale_id' => $sale_id,
                'applicant_id' => $applicant_id,
                'status' => 1
            ])->update(['status' => 0]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                'status' => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'quality';
            $history->sub_stage = 'quality_no_job_reject';
            $history->save();

            /** Update UID */
            $history->history_uid = md5((string) $history->id);
            $history->save();

            $details_revert = $details;

            RevertStage::create([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'stage' => 'crm_revert',
                'user_id' => $user_id,
                'notes' => $details_revert,
            ]);

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmNoJobRevertCVInQualityAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmSentCvNoJobSaveAction($applicant_id, $user_id, $sale_id, $details, $reject_reason)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_no_job";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'quality_cleared_no_job';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            if ($reject_reason == 'position_filled' || $reject_reason == 'agency' || $reject_reason == 'manager') {
                if ($reject_reason == 'position_filled') {
                    $sale = Sale::find($sale_id);
                    $audit = new ActionObserver();
                    $audit->changeSaleStatus($sale, ['status' => 0]);
                    $sale->update(['status' => 0, 'sale_notes' => $details, 'is_on_hold' => false]);
                } else {
                    $sale = Sale::find($sale_id);
                    $sale->status = $sale->status;
                    $sale->update();
                }

                SaleNote::where([
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                $sale_note = new SaleNote();
                $sale_note->sale_id = $sale_id;
                $sale_note->user_id = $user_id;
                $sale_note->sale_note = $details;
                $sale_note->save();

                //update uid
                $sale_note->sales_notes_uid = md5((string) $sale_note->id);
                $sale_note->save();

                // Disable previous module note
                ModuleNote::where([
                    'module_noteable_id' => $sale_id,
                    'module_noteable_type' => 'Horsefly\Sale'
                ])
                    ->where('status', 1)
                    ->update(['status' => 0]);

                // Create new module note
                $moduleNote = ModuleNote::create([
                    'details' => $details,
                    'module_noteable_id' => $sale_id,
                    'module_noteable_type' => 'Horsefly\Sale',
                    'user_id' => $user_id,
                ]);

                $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);
            }

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmSentCvNoJobSaveAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmNoJobSentRejectCvAction($applicant_id, $user_id, $sale_id, $details, $reject_reason)
    {
        try {
            Applicant::where("id", $applicant_id)->update([
                'is_in_crm_reject' => true,
                'is_interview_confirm' => false
            ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sales_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_reject_no_job";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            $crm_rejected_cv = new CrmRejectedCv();
            $crm_rejected_cv->applicant_id = $applicant_id;
            $crm_rejected_cv->sale_id = $sale_id;
            $crm_rejected_cv->user_id = $user_id;
            $crm_rejected_cv->crm_note_id = $crm_notes->id;
            $crm_rejected_cv->reason = $reject_reason;
            $crm_rejected_cv->crm_rejected_cv_note = $details;
            $crm_rejected_cv->save();

            //update uid
            $crm_rejected_cv->crm_rejected_cv_uid = md5((string) $crm_rejected_cv->id);
            $crm_rejected_cv->save();

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_no_job_reject';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            if ($reject_reason == 'position_filled' || $reject_reason == 'agency' || $reject_reason == 'manager') {
                if ($reject_reason == 'position_filled') {
                    $sale = Sale::find($sale_id);
                    $audit = new ActionObserver();
                    $audit->changeSaleStatus($sale, ['status' => 0]);
                    $sale->update(['status' => 0, 'sale_notes' => $details, 'is_on_hold' => false]);
                } else {
                    $sale = Sale::find($sale_id);
                    $sale->status = $sale->status;
                    $sale->update();
                }

                SaleNote::where([
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                $sale_note = new SaleNote();
                $sale_note->sale_id = $sale_id;
                $sale_note->user_id = $user_id;
                $sale_note->sale_note = $details;
                $sale_note->save();

                //update uid
                $sale_note->sales_notes_uid = md5((string) $sale_note->id);
                $sale_note->save();
            }

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmNoJobSentRejectCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmNoJobSentRequestAction($applicant_id, $user_id, $sale_id, $details, $reject_reason)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_request' => true,
                    'is_interview_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sales_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_no_job_request";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cleared_no_job",
                "status" => 1
            ])->update(["status" => 0]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_no_job_request';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            if ($reject_reason == 'position_filled' || $reject_reason == 'agency' || $reject_reason == 'manager') {
                if ($reject_reason == 'position_filled') {
                    $sale = Sale::find($sale_id);
                    $audit = new ActionObserver();
                    $audit->changeSaleStatus($sale, ['status' => 0]);
                    $sale->update(['status' => 0, 'sale_notes' => $details, 'is_on_hold' => false]);
                } else {
                    $sale = Sale::find($sale_id);
                    $sale->status = $sale->status;
                    $sale->update();
                }

                SaleNote::where([
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                $sale_note = new SaleNote();
                $sale_note->sale_id = $sale_id;
                $sale_note->user_id = $user_id;
                $sale_note->sale_note = $details;
                $sale_note->save();

                //update uid
                $sale_note->sales_notes_uid = md5((string) $sale_note->id);
                $sale_note->save();
            }

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmNoJobSentRequestAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Open CV */
    private function crmOpenCvAction($applicant_id, $user_id, $sale_id, $details, $reject_reason)
    {
        try {
            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $cv_note = new CVNote();
            $cv_note->sale_id = $sale_id;
            $cv_note->user_id = $user_id;
            $cv_note->applicant_id = $applicant_id;
            $cv_note->details = $details;
            $cv_note->save();

            //update uid
            $cv_note->cv_uid = md5((string) $cv_note->id);
            $cv_note->save();

            // Handle reject reason cases
            if (in_array($reject_reason, ['position_filled', 'agency', 'manager'])) {
                $sale = Sale::findOrFail($sale_id);

                if ($reject_reason == 'position_filled') {
                    $audit = new ActionObserver();
                    $audit->changeSaleStatus($sale, ['status' => 0]);
                    $sale->update(['status' => 0, 'sale_notes' => $details, 'is_on_hold' => false]);
                } else {
                    // Just touch the record to update updated_at
                    $sale->touch();
                }

                // Update history status
                SaleNote::where([
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                // Create sale note
                $sale_note = new SaleNote();
                $sale_note->sale_id = $sale_id;
                $sale_note->user_id = $user_id;
                $sale_note->sale_note = $details;
                $sale_note->save();

                // Update sale note UID
                $sale_note->sales_notes_uid = md5((string) $sale_note->id);
                $sale_note->save();
            }

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmOpenCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmOpenCvSentRequestAction($applicant_id, $sale_id)
    {
        try {
            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cv_hold",
                'status' => 1
            ])
                ->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cleared",
                'status' => 0
            ])
                ->update(["status" => 1]);

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmOpenCvSentRequestAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Request */
    private function crmRequestRejectAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_request_reject' => true,
                    'is_in_crm_request' => false
                ]);

            Interview::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(['status' => 0]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "request_reject";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request_reject';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRequestRejectAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    /** CRM Request No Response*/
    private function crmRequestNoResponseAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "request_no_response";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request_no_response';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRequestNoResponseAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRequestConfirmAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_request_confirm' => true,
                    'is_in_crm_request' => false,
                    'is_in_crm_request_reject' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "request_confirm";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request_confirm';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRequestConfirmAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRequestSaveAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "request_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "request_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();
            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRequestRejectAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRevertRequestToSentCvAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_request' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cleared",
                "status" => 0
            ])->update(["status" => 1]);

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertRequestToSentCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Request Reject */
    private function crmRequestRejectedRevertToSentCvAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CVNote::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'status' => 0
            ])
                ->orderBy('id', 'desc')  // Get the latest record
                ->limit(1)              // Only one record
                ->update(['status' => 1]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "cleared"
            ])
                ->orderBy('id', 'desc')  // Get the latest record
                ->limit(1)
                ->update(["status" => 1]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])
                ->whereIn("moved_tab_to", [
                    "cv_sent",
                    "cv_sent_saved",
                    "cv_sent_request",
                    "request_reject"
                ])
                ->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();
            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRequestRejectedRevertToSentCvAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRequestRejectedRevertToRequestAction($applicant_id, $user_id, $sale_id, $details)
    {
        // One transaction: a failure part-way (e.g. a missing note) leaves nothing half-reverted.
        return DB::transaction(function () use ($applicant_id, $user_id, $sale_id, $details) {
            try {
                CVNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id,
                    "status" => 0
                ])
                    ->orderBy('id', 'desc')  // Get the latest record
                    ->limit(1)
                    ->update(["status" => 1]);

                /*** get latest sent cv record */
                $latest_sent_cv = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "cv_sent")
                    ->latest()->first();
                if (!$latest_sent_cv) {           // nothing to revert to: roll back and say why
                    throw new ActionRefusedException("There is no 'CV sent' note to revert to for this applicant and sale.");
                }

                $all_cv_sent_saved = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "cv_sent_saved")
                    ->where('created_at', '>=', $latest_sent_cv->created_at)
                    ->get();

                $crm_notes_ids[0] = $latest_sent_cv->id;
                foreach ($all_cv_sent_saved as $cv) {
                    $crm_notes_ids[] = $cv->id;
                }

                CrmNote::whereIn('id', $crm_notes_ids)
                    ->update(["status" => 1]);

                // CrmNote::where([
                //     "applicant_id" => $applicant_id,
                //     "sale_id" => $sale_id,
                //     "status" => 1
                // ])->update(["status" => 0]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user_id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "cv_sent_request";
                $crm_notes->save();

                //update uid
                $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
                $crm_notes->save();

                History::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                $history = new History();
                $history->applicant_id = $applicant_id;
                $history->user_id = $user_id;
                $history->sale_id = $sale_id;
                $history->stage = 'crm';
                $history->sub_stage = 'crm_request';
                $history->save();

                //update uid
                $history->history_uid = md5((string) $history->id);
                $history->save();

                return true; // Indicate success

            } catch (Exception $e) {
                // Log the error for debugging
                Log::error("Error in crmRequestRejectedRevertToRequestAction: " . $e->getMessage());

                // Re-throw the exception to be caught by the calling method
                throw $e;
            }
        });
    }

    /** CRM Confirmation */
    private function crmInterviewSaveAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "interview_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmInterviewSaveAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmConfirmationRevertToRequestAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Interview::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'status' => 1
            ])->update(['status' => 0]);

            CrmNote::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id
            ])
                ->whereIn('moved_tab_to', [
                    'cv_sent_request',
                    'request_to_save',
                    'request_to_confirm',
                    'interview_save'
                ])->update(['status' => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "cv_sent_request";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmConfirmationRevertToRequestAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInterviewAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => true,
                    'is_crm_request_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_attended";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_attended';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmInterviewAttendedAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInterviewRebookAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => true,
                    'is_crm_request_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "rebook";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_rebook';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmInterviewRebookAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Rebook */
    private function crmRevertRebookToConfirmationAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_request_confirm' => true,
                    'is_in_crm_request' => false,
                    'is_in_crm_request_reject' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "request_confirm";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_request_confirm';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertRebookToConfirmationAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRebookSaveAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "rebook_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "rebook_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_rebook_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRebookSaveAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRebookToAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => true,
                    'is_crm_request_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_attended";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_attended';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRebookToAttendedAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRebookToNotAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => false,
                    'is_crm_request_confirm' => false
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_not_attended";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_not_attended';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRebookToNotAttendedAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Attended */
    private function crmRevertAttendedToRebookAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => true,
                    'is_crm_request_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1,
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "rebook";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_rebook';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertAttendedToRebookAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmAttendedToStartDateAction($applicant_id, $user_id, $sale_id, $details)
    {
        Applicant::where("id", $applicant_id)
            ->update([
                'is_in_crm_start_date' => true,
                'is_crm_interview_attended' => 2 //pending
            ]);

        $crm_notes = new CrmNote();
        $crm_notes->applicant_id = $applicant_id;
        $crm_notes->user_id = $user_id;
        $crm_notes->sale_id = $sale_id;
        $crm_notes->details = $details;
        $crm_notes->moved_tab_to = "start_date";
        $crm_notes->save();

        //update uid
        $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
        $crm_notes->save();

        History::where([
            "applicant_id" => $applicant_id,
            "sale_id" => $sale_id
        ])->update(["status" => 0]);

        $history = new History();
        $history->applicant_id = $applicant_id;
        $history->user_id = $user_id;
        $history->sale_id = $sale_id;
        $history->stage = 'crm';
        $history->sub_stage = 'crm_start_date';
        $history->save();

        //update uid
        $history->history_uid = md5((string) $history->id);
        $history->save();
    }
    private function crmAttendedToDeclineAction($applicant_id, $user_id, $sale_id, $details)
    {
        Applicant::where("id", $applicant_id)
            ->update([
                'is_crm_request_confirm' => false,
                'is_crm_interview_attended' => 0 //not attended
            ]);

        CVNote::where([
            "applicant_id" => $applicant_id,
            "sale_id" => $sale_id,
            "status" => 1
        ])->update(["status" => 0]);

        QualityNotes::where([
            "applicant_id" => $applicant_id,
            "sale_id" => $sale_id,
            "status" => 1
        ])->update(["status" => 0]);

        CrmNote::where([
            "applicant_id" => $applicant_id,
            "sale_id" => $sale_id,
            "status" => 1
        ])->update(["status" => 0]);

        $crm_notes = new CrmNote();
        $crm_notes->applicant_id = $applicant_id;
        $crm_notes->user_id = $user_id;
        $crm_notes->sale_id = $sale_id;
        $crm_notes->details = $details;
        $crm_notes->moved_tab_to = "declined";
        $crm_notes->save();

        //update uid
        $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
        $crm_notes->save();

        History::where([
            "applicant_id" => $applicant_id,
            "sale_id" => $sale_id
        ])->update(["status" => 0]);

        $history = new History();
        $history->applicant_id = $applicant_id;
        $history->user_id = $user_id;
        $history->sale_id = $sale_id;
        $history->stage = 'crm';
        $history->sub_stage = 'crm_declined';
        $history->save();

        //update uid
        $history->history_uid = md5((string) $history->id);
        $history->save();
    }
    private function crmPreStartDateAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "prestart_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "prestart_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_prestart_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmPreStartDateAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** Not Attended */
    private function crmInterviewNotAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => false,
                    'is_crm_request_confirm' => false
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_not_attended";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_not_attended';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmInterviewNotAttendedAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInterviewNotAttendedToAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => true
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0
            ])->update(["status" => 1]);


            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_attended";
            $crm_notes->save();

            /** update uid */
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_attended';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmInterviewNotAttendedToAttendedAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Decline */
    private function crmRevertDeclineToAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        // One transaction: a failure part-way (e.g. a missing note) leaves nothing half-reverted.
        return DB::transaction(function () use ($applicant_id, $user_id, $sale_id, $details) {
            try {
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_crm_interview_attended' => true
                    ]);

                CVNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id,
                    "status" => 0
                ])->update(["status" => 1]);

                QualityNotes::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                /*** latest sent cv records */
                $crm_notes_index = 0;
                $latest_sent_cv = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])->where("moved_tab_to", "cv_sent")
                    ->latest()->first();
                if (!$latest_sent_cv) {           // nothing to revert to: roll back and say why
                    throw new ActionRefusedException("There is no 'CV sent' note to revert to for this applicant and sale.");
                }

                $all_cv_sent_saved = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "cv_sent_saved")
                    ->where('created_at', '>=', $latest_sent_cv->created_at)
                    ->get();

                $crm_notes_ids[$crm_notes_index++] = $latest_sent_cv->id;
                foreach ($all_cv_sent_saved as $cv) {
                    $crm_notes_ids[$crm_notes_index++] = $cv->id;
                }

                /*** latest request records */
                $latest_request = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])->where("moved_tab_to", "cv_sent_request")
                    ->latest()->first();
                if (!$latest_request) {           // nothing to revert to: roll back and say why
                    throw new ActionRefusedException("There is no 'request' note to revert to for this applicant and sale.");
                }

                $all_request_saved = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "request_save")
                    ->where('created_at', '>=', $latest_request->created_at)
                    ->get();

                $crm_notes_ids[$crm_notes_index++] = $latest_request->id;
                foreach ($all_request_saved as $cv) {
                    $crm_notes_ids[$crm_notes_index++] = $cv->id;
                }

                /*** latest confirmation records */
                $latest_confirmation = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])->where("moved_tab_to", "request_confirm")
                    ->latest()->first();
                if (!$latest_confirmation) {           // nothing to revert to: roll back and say why
                    throw new ActionRefusedException("There is no 'confirmation' note to revert to for this applicant and sale.");
                }

                $all_confirmation_saved = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])
                    ->where("moved_tab_to", "interview_save")
                    ->where('created_at', '>=', $latest_confirmation->created_at)->get();

                $crm_notes_ids[$crm_notes_index++] = $latest_confirmation->id;
                foreach ($all_confirmation_saved as $cv) {
                    $crm_notes_ids[$crm_notes_index++] = $cv->id;
                }

                /*** latest rebook records */
                $latest_rebook = CrmNote::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id
                ])->where("moved_tab_to", "rebook")
                    ->latest()->first();

                if ($latest_rebook) {
                    $all_rebook_saved = CrmNote::where([
                        "applicant_id" => $applicant_id,
                        "sale_id" => $sale_id
                    ])
                        ->where("moved_tab_to", "rebook_save")
                        ->where('created_at', '>=', $latest_rebook->created_at)->get();

                    $crm_notes_ids[$crm_notes_index++] = $latest_rebook->id;
                    foreach ($all_rebook_saved as $cv) {
                        $crm_notes_ids[$crm_notes_index++] = $cv->id;
                    }
                }

                CrmNote::whereIn('id', $crm_notes_ids)->update(["status" => 1]);

                // CrmNote::where([
                //     "applicant_id" => $applicant_id,
                //     "sale_id" => $sale_id,
                //     "status" => 1
                // ])->update(["status" => 0]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user_id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "interview_attended";
                $crm_notes->save();

                //update uid
                $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
                $crm_notes->save();

                History::where([
                    "applicant_id" => $applicant_id,
                    "sale_id" => $sale_id,
                    "status" => 1
                ])->update(["status" => 0]);

                $history = new History();
                $history->applicant_id = $applicant_id;
                $history->user_id = $user_id;
                $history->sale_id = $sale_id;
                $history->stage = 'crm';
                $history->sub_stage = 'crm_interview_attended';
                $history->save();

                //update uid
                $history->history_uid = md5((string) $history->id);
                $history->save();

                return true; // Indicate success

            } catch (\Exception $e) {
                // Log the error for debugging
                Log::error("Error in crmRevertDeclineToAttendedAction: " . $e->getMessage());

                // Re-throw the exception to be caught by the calling method
                throw $e;
            }
        });
    }

    /** CRM Start Date */
    private function crmStartDateToInvoiceAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_invoice' => true,
                    'is_in_crm_start_date' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "invoice";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_invoice';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmStartDateToInvoiceAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmStartDateHoldAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_start_date_hold' => true,
                    'is_in_crm_start_date' => false
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])->update(["status" => 0]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "start_date_hold";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_start_date_hold';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmStartDateHoldAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmStartDateSaveAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "start_date_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "start_date_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_start_date_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmStartDateSaveAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmRevertStartDateToAttendedAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            //if applicant revert from start date for purpose of send cv save or quality
            $lstHistory = History::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
                'sub_stage' => 'crm_start_date'
            ])
                ->whereDate('created_at', Carbon::now())
                ->latest()
                ->first();

            // Check if last history exists and was created within the last 10 minutes
            if ($lstHistory) {
                $timeDifference = Carbon::now()->diffInMinutes($lstHistory->created_at);

                if ($timeDifference <= 10) {
                    $lstHistory->delete();
                }
            }

            Applicant::where("id", $applicant_id)
                ->update([
                    'is_crm_interview_attended' => true,
                    'is_crm_request_confirm' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "interview_attended";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_interview_attended';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertDeclineToAttendedAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    /** CRM Start Date Hold */
    private function crmRevertStartDateHoldToStartDateAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_start_date_hold' => false,
                    'is_in_crm_start_date' => true
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0
            ])->update(["status" => 1]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "start_date_back";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_start_date_back';

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertStartDateHoldToStartDateAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmStartDateHoldSaveAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "start_date_hold_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "start_date_hold_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_start_date_hold_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmStartDateHoldSaveAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Invoice */
    private function crmRevertInvoiceToStartDateAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_start_date' => true,
                    'is_crm_interview_attended' => 2, //pending 
                    'is_in_crm_invoice' => false
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "start_date";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_start_date';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertInvoiceToStartDateAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInvoiceToDisputeAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_dispute' => true,
                    'is_in_crm_invoice' => false,
                    'is_in_crm_invoice_sent' => false,
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "dispute";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_dispute';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmDisputeAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInvoiceToInvoiceSentAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_invoice' => false,
                    'is_in_crm_invoice_sent' => true,
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "invoice_sent";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_invoice_sent';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmDisputeAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmFinalSaveAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "moved_tab_to" => "final_save",
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "final_save";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_final_save';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmFinalSaveAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** CRM Invoice Sent*/
    private function crmInvoiceSentToPaidAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_paid' => true,
                    'is_in_crm_invoice' => false,
                    'is_in_crm_invoice_sent' => false,
                    'paid_status' => 'close',
                    'paid_timestamp' => Carbon::now()
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "paid";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
            ])->update(["status" => 2]); //2 is paid

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_paid';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmPaidAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInvoiceSentToDisputeAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_dispute' => true,
                    'is_in_crm_invoice' => false,
                    'is_in_crm_invoice_sent' => false,
                ]);

            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            QualityNotes::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "dispute";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_dispute';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmDisputeAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }
    private function crmInvoiceSentToInvoiceAction($applicant_id, $user_id, $sale_id, $details)
    {
        try {
            Applicant::where("id", $applicant_id)
                ->update([
                    'is_in_crm_dispute' => false,
                    'is_in_crm_invoice' => true,
                    'is_in_crm_invoice_sent' => false,
                ]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "invoice";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_invoice';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmInvoiceSentAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** Dispute */
    private function crmRevertDisputeToInvoiceAction($applicant_id, $user_id, $sale_id, $details)
    {
        /** lets start to the process of dispute */
        try {
            CVNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0
            ])->update(["status" => 1]);

            CrmNote::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 0
            ])
                ->whereIn('moved_tab_to', [
                    'cv_sent',
                    'cv_sent_saved',
                    'cv_sent_request',
                    'request_save',
                    'request_confirm',
                    'prestart_save',
                    'start_date',
                    'start_date_save',
                    'start_date_back',
                    'interview_attended',
                    'interview_save'
                ])->update(["status" => 1]);

            $crm_notes = new CrmNote();
            $crm_notes->applicant_id = $applicant_id;
            $crm_notes->user_id = $user_id;
            $crm_notes->sale_id = $sale_id;
            $crm_notes->details = $details;
            $crm_notes->moved_tab_to = "invoice";
            $crm_notes->save();

            //update uid
            $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
            $crm_notes->save();

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id,
                "status" => 1
            ])->update(["status" => 0]);

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user_id;
            $history->sale_id = $sale_id;
            $history->stage = 'crm';
            $history->sub_stage = 'crm_invoice';
            $history->save();

            //update uid
            $history->history_uid = md5((string) $history->id);
            $history->save();

            return true; // Indicate success

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Error in crmRevertDisputeToInvoiceAction: " . $e->getMessage());

            // Re-throw the exception to be caught by the calling method
            throw $e;
        }
    }

    /** Paid */
    // private function crmRevertDisputeTosInvoiceAction($applicant_id, $user_id, $sale_id, $details)
    // {
    //     try{
    //         CVNote::where([
    //             "applicant_id" => $applicant_id, 
    //             "sale_id" => $sale_id,
    //             "status" => 0
    //         ])->update(["status" => 1]);

    //         CrmNote::where([
    //             "applicant_id" => $applicant_id,
    //             "sale_id" => $sale_id,
    //             "status" => 0
    //         ])
    //         ->whereIn('moved_tab_to', [
    //             'cv_sent', 'cv_sent_saved', 
    //             'cv_sent_request', 'request_save', 
    //             'request_confirm', 'prestart_save', 
    //             'start_date', 'start_date_save', 
    //             'start_date_back', 'interview_attended', 
    //             'interview_save'
    //         ])->update(["status" => 1]);

    //         $crm_notes = new CrmNote();
    //         $crm_notes->applicant_id = $applicant_id;
    //         $crm_notes->user_id = $user_id;
    //         $crm_notes->sale_id = $sale_id;
    //         $crm_notes->details = $details;
    //         $crm_notes->moved_tab_to = "invoice";
    //         $crm_notes->save();

    //         //update uid
    //         $crm_notes->crm_notes_uid = md5((string) $crm_notes->id);
    //         $crm_notes->save();

    //         History::where([
    //             "applicant_id" => $applicant_id,
    //             "sale_id" => $sale_id,
    //             "status" => 1
    //         ])->update(["status" => 0]);

    //         $history = new History();
    //         $history->applicant_id = $applicant_id;
    //         $history->user_id = $user_id;
    //         $history->sale_id = $sale_id;
    //         $history->stage = 'crm';
    //         $history->sub_stage = 'crm_invoice';
    //         $history->save();

    //         //update uid
    //         $history->history_uid = md5((string) $history->id);
    //         $history->save();

    //        return true; // Indicate success

    //     } catch (\Exception $e) {
    //         // Log the error for debugging
    //         Log::error("Error in crmRevertDisputeToInvoiceAction: " . $e->getMessage());

    //         // Re-throw the exception to be caught by the calling method
    //         throw $e;
    //     }  
    // }

}
