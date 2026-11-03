<?php

namespace App\Services\Crm;

use App\Traits\HidesPrivateContacts;
use Closure;
use Horsefly\Applicant;
use Horsefly\JobSource;
use Horsefly\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the applicants query behind the CRM page tabs (CrmController::getCrmApplicantsAjaxRequest).
 * Each tab adds its own joins and selects in its own method below.
 */
class CrmApplicantsQuery
{
    use HidesPrivateContacts;

    public function __construct(private Request $request)
    {
    }

    public function tab(): string
    {
        return (string) $this->request->input('tab_filter', '');
    }

    public function build(): Builder
    {
        $request = $this->request;

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

        match ($tabFilter) {
            'open cvs' => $this->openCvsTab($model),
            'sent cvs (no job)' => $this->sentCvsNoJobTab($model),
            'rejected cvs' => $this->rejectedCvsTab($model),
            'request' => $this->requestTab($model),
            'request (no response)' => $this->requestNoResponseTab($model),
            'request (no job)' => $this->requestNoJobTab($model),
            'rejected by request' => $this->rejectedByRequestTab($model),
            'confirmation' => $this->confirmationTab($model, $date_range_filter),
            'rebook' => $this->rebookTab($model),
            'attended to pre-start date' => $this->attendedToPreStartDateTab($model),
            'declined' => $this->declinedTab($model),
            'not attended' => $this->notAttendedTab($model),
            'start date' => $this->startDateTab($model),
            'start date hold' => $this->startDateHoldTab($model),
            'invoice' => $this->invoiceTab($model),
            'invoice sent' => $this->invoiceSentTab($model),
            'dispute' => $this->disputeTab($model),
            'paid' => $this->paidTab($model),
            // The old switch had `default:` on the 'sent cvs' case, so unknown tabs fall back to it.
            default => $this->sentCvsTab($model),
        };

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

        return $model;
    }

    /** Sorting goes through DataTables' order() callback so it stays out of the count query. */
    public function sorter(): Closure
    {
        $request = $this->request;

        // Sorting logic — deferred to DataTables' ->order() callback below so the
        // ORDER BY clause is not baked into the filtered-count query (it only needs
        // to apply to the final, paginated result set).
        return function ($query) use ($request) {
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
    }

    /**
     * Applicant/sale pairs currently at one of $stages (history.status = 1). Each tab only shows these
     * pairs, so its "latest note per pair" subqueries are limited to them too.
     */
    private function historyPairs(array $stages)
    {
        return DB::table('history')
            ->select('applicant_id', 'sale_id')
            ->whereIn('sub_stage', $stages)
            ->where('status', 1);
    }

    /** open cvs */
    protected function openCvsTab(Builder $model): void
    {
        $model->leftJoinSub(
            DB::table('cv_notes')
                ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
                ->whereIn('id', function ($subQuery) {
                    $subQuery->select(DB::raw('MAX(id)'))
                        ->from('cv_notes')
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cvs_hold']))
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cvs_hold']))
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
            ->joinSub($this->historyPairs(['quality_cvs_hold'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'cv_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'cv_notes.sale_id');
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
    }

    /** sent cvs (no job) */
    protected function sentCvsNoJobTab(Builder $model): void
    {
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cleared_no_job']))
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
            ->joinSub($this->historyPairs(['quality_cleared_no_job'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'quality_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'quality_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cleared_no_job']))
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cleared_no_job']))
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
    }

    /** rejected cvs */
    protected function rejectedCvsTab(Builder $model): void
    {
        // First reject note (MIN id) and latest reject note (MAX id) per applicant + sale, in one pass
        // over crm_notes. Both are then joined by primary key as crm_last_notes and latest_crm.
        $rejectNoteIds = DB::table('crm_notes')
            ->select('applicant_id', 'sale_id')
            ->selectRaw('MIN(id) as first_id, MAX(id) as last_id')
            ->whereIn('moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
            ->groupBy('applicant_id', 'sale_id');

        // Subquery to get latest CV notes
        $cvNotesSubQuery = DB::table('cv_notes as cv1')
            ->select('cv1.*')
            ->joinSub(function ($q) {
                $q->select(DB::raw('MAX(id) as latest_id'))
                    ->from('cv_notes')
                    ->groupBy('applicant_id', 'sale_id');
            }, 'latest_ids', 'latest_ids.latest_id', '=', 'cv1.id');

        // Main query
        $model->joinSub($rejectNoteIds, 'reject_note_ids', 'applicants.id', '=', 'reject_note_ids.applicant_id')
            ->join('crm_notes as crm_last_notes', 'crm_last_notes.id', '=', 'reject_note_ids.first_id')
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
            ->joinSub($this->historyPairs(['crm_reject', 'crm_no_job_reject'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_last_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_last_notes.sale_id');
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
            ->join('crm_notes as latest_crm', 'latest_crm.id', '=', 'reject_note_ids.last_id')
            ->leftJoin('users', 'users.id', '=', 'cv_last_notes.user_id')
            ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
            ->addSelect([
                // Applicants
                'applicants.id as applicant_id',

                // CRM Notes
                'latest_crm.details as notes_detail',
                'latest_crm.created_at as notes_created_at',

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
    }

    /** request */
    protected function requestTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request', 'crm_request_save']))
                    ->groupBy('applicant_id', 'sale_id');
            });

        // Subquery for latest cv_notes per applicant_id and sale_id
        $cvNotesSubQuery = DB::table('cv_notes')
            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
            ->whereIn('id', function ($subQuery) {
                $subQuery->select(DB::raw('MAX(id)'))
                    ->from('cv_notes')
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request', 'crm_request_save']))
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request', 'crm_request_save']))
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
            ->joinSub($this->historyPairs(['crm_request', 'crm_request_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
    }

    /** request (no response) */
    protected function requestNoResponseTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request_no_response']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request_no_response']))
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
            ->joinSub($this->historyPairs(['crm_request_no_response'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request_no_response']))
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
    }

    /** request (no job) */
    protected function requestNoJobTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_no_job_request', 'crm_request_no_job_save']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_no_job_request', 'crm_request_no_job_save']))
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
            ->joinSub($this->historyPairs(['crm_no_job_request', 'crm_request_no_job_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_no_job_request', 'crm_request_no_job_save']))
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
    }

    /** rejected by request */
    protected function rejectedByRequestTab(Builder $model): void
    {
        // Latest reject note (MAX id) per applicant + sale, in one pass over crm_notes. It is both the
        // row the tab lists and the note it shows, so it is joined once by primary key.
        $rejectNoteIds = DB::table('crm_notes')
            ->select('applicant_id', 'sale_id')
            ->selectRaw('MAX(id) as last_id')
            ->whereIn('moved_tab_to', ['request_reject', 'request_no_job_reject'])
            ->groupBy('applicant_id', 'sale_id');

        // Subquery for latest cv_notes
        $cvNotesSubQuery = DB::table('cv_notes')
            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
            ->joinSub(function ($subQuery) {
                $subQuery->select(DB::raw('MAX(id) as latest_id'))
                    ->from('cv_notes')
                    ->groupBy('applicant_id', 'sale_id');
            }, 'latest_ids', 'latest_ids.latest_id', '=', 'id');

        $model->joinSub($rejectNoteIds, 'reject_note_ids', 'applicants.id', '=', 'reject_note_ids.applicant_id')
            ->join('crm_notes as latest_crm_notes', 'latest_crm_notes.id', '=', 'reject_note_ids.last_id')
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
            ->joinSub($this->historyPairs(['crm_request_reject', 'crm_request_no_job_reject'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'latest_crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'latest_crm_notes.sale_id');
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
            ->leftJoin('users', 'users.id', '=', 'latest_cv_notes.user_id')
            ->leftJoin('job_sources as sale_job_sources', fn($join) => $join->on('sales.job_source_id', '=', 'sale_job_sources.id'))
            ->addSelect([
                // Applicants
                'applicants.id as applicant_id',
                // CRM Notes
                'latest_crm_notes.details as notes_detail',
                'latest_crm_notes.created_at as notes_created_at',

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
    }

    /** confirmation */
    protected function confirmationTab(Builder $model, $date_range_filter): void
    {
        // Define reusable subqueries with MAX(id) grouped — using joinSub with indexes
        $crmNotesSubQuery = DB::table('crm_notes as cn1')
            ->joinSub(function ($q) {
                $q->selectRaw('MIN(id) as id')
                    ->from('crm_notes')
                    ->where('status', 1)
                    ->whereIn('moved_tab_to', ['request_confirm', 'request_no_job_confirm'])
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request_confirm', 'crm_interview_save', 'crm_request_no_job_confirm']))
                    ->groupBy('applicant_id', 'sale_id');
            }, 'first_cn', 'cn1.id', '=', 'first_cn.id')
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
            ->joinSub(function ($q) {
                $q->selectRaw('MAX(id) as id')
                    ->from('cv_notes')
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request_confirm', 'crm_interview_save', 'crm_request_no_job_confirm']))
                    ->groupBy('applicant_id', 'sale_id');
            }, 'latest_cv', 'cv1.id', '=', 'latest_cv.id');

        // Subquery: latest CRM note per applicant-sale (for details)
        $latestCrmNotes = DB::table('crm_notes as cn_latest')
            ->select('cn_latest.applicant_id', 'cn_latest.sale_id', 'cn_latest.details as latest_details', 'cn_latest.created_at as latest_created_at')
            ->where('cn_latest.status', 1)
            ->whereIn("cn_latest.moved_tab_to", ["request_confirm", "interview_save", "request_no_job_confirm"])
            ->joinSub(function ($q) {
                $q->selectRaw('MAX(id) as latest_id')
                    ->from('crm_notes')
                    ->whereIn("moved_tab_to", ["request_confirm", "interview_save", "request_no_job_confirm"])
                    ->where('status', 1)
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_request_confirm', 'crm_interview_save', 'crm_request_no_job_confirm']))
                    ->groupBy('applicant_id', 'sale_id');
            }, 'latest_ids', 'latest_ids.latest_id', '=', 'cn_latest.id');

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
            ->joinSub($this->historyPairs(['crm_request_confirm', 'crm_interview_save', 'crm_request_no_job_confirm'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
    }

    /** rebook */
    protected function rebookTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_rebook', 'crm_rebook_save']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_rebook', 'crm_rebook_save']))
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
            ->joinSub($this->historyPairs(['crm_rebook', 'crm_rebook_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_rebook', 'crm_rebook_save']))
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
    }

    /** attended to pre-start date */
    protected function attendedToPreStartDateTab(Builder $model): void
    {
        // Subquery to get the latest crm_notes per applicant_id and sale_id
        $crmNotesSubQuery = DB::table('crm_notes')
            ->select('applicant_id', 'sale_id', 'details', 'created_at')
            ->whereIn('moved_tab_to', ["interview_attended"])
            ->whereIn('id', function ($subQuery) {
                $subQuery->select(DB::raw('MAX(id)'))
                    ->from('crm_notes')
                    ->whereIn('moved_tab_to', ["interview_attended"])
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_interview_attended', 'crm_prestart_save']))
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_interview_attended', 'crm_prestart_save']))
                    ->groupBy('applicant_id', 'sale_id');
            });

        // Subquery for latest cv_notes per applicant_id and sale_id
        $cvNotesSubQuery = DB::table('cv_notes')
            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
            ->whereIn('id', function ($subQuery) {
                $subQuery->select(DB::raw('MAX(id)'))
                    ->from('cv_notes')
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_interview_attended', 'crm_prestart_save']))
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
            ->joinSub($this->historyPairs(['crm_interview_attended', 'crm_prestart_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
    }

    /** declined */
    protected function declinedTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_declined']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_declined']))
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
            ->joinSub($this->historyPairs(['crm_declined'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_declined']))
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
    }

    /** not attended */
    protected function notAttendedTab(Builder $model): void
    {
        $model->joinSub(
            DB::table('crm_notes')
                ->select('applicant_id', 'sale_id', 'details', 'created_at')
                ->whereIn('moved_tab_to', ["interview_not_attended"])
                ->joinSub(fn($subQuery) =>
                    $subQuery->select(DB::raw('MAX(id) as latest_id'))
                        ->from('crm_notes')
                        ->whereIn('moved_tab_to', ["interview_not_attended"])
                        ->groupBy('applicant_id', 'sale_id'), 'latest_ids', 'latest_ids.latest_id', '=', 'id'),
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
                    ->joinSub(function ($subQuery) {
                        $subQuery->select(DB::raw('MAX(id) as latest_id'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    }, 'latest_ids', 'latest_ids.latest_id', '=', 'id'),
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
    }

    /** start date */
    protected function startDateTab(Builder $model): void
    {
        // Subquery to get the latest crm_notes per applicant_id and sale_id
        $crmNotesSubQuery = DB::table('crm_notes')
            ->select('applicant_id', 'sale_id', 'details', 'created_at')
            ->whereIn("moved_tab_to", ["start_date"])
            ->whereIn('id', function ($subQuery) {
                $subQuery->select(DB::raw('MAX(id)'))
                    ->from('crm_notes')
                    ->whereIn("moved_tab_to", ["start_date"])
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_start_date', 'crm_start_date_save', 'crm_start_date_back']))
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_start_date', 'crm_start_date_save', 'crm_start_date_back']))
                    ->groupBy('applicant_id', 'sale_id');
            });

        // Subquery for latest cv_notes per applicant_id and sale_id
        $cvNotesSubQuery = DB::table('cv_notes')
            ->select('applicant_id', 'sale_id', 'user_id', 'status', 'created_at')
            ->whereIn('id', function ($subQuery) {
                $subQuery->select(DB::raw('MAX(id)'))
                    ->from('cv_notes')
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_start_date', 'crm_start_date_save', 'crm_start_date_back']))
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
            ->joinSub($this->historyPairs(['crm_start_date', 'crm_start_date_save', 'crm_start_date_back'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
    }

    /** start date hold */
    protected function startDateHoldTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_start_date_hold', 'crm_start_date_hold_save']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_start_date_hold', 'crm_start_date_hold_save']))
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
            ->joinSub($this->historyPairs(['crm_start_date_hold', 'crm_start_date_hold_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_start_date_hold', 'crm_start_date_hold_save']))
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
    }

    /** invoice */
    protected function invoiceTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_invoice', 'crm_final_save']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_invoice', 'crm_final_save']))
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
            ->joinSub($this->historyPairs(['crm_invoice', 'crm_final_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_invoice', 'crm_final_save']))
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
    }

    /** invoice sent */
    protected function invoiceSentTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_invoice_sent', 'crm_final_save']))
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_invoice_sent', 'crm_final_save']))
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
            ->joinSub($this->historyPairs(['crm_invoice_sent', 'crm_final_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_invoice_sent', 'crm_final_save']))
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
    }

    /** dispute */
    protected function disputeTab(Builder $model): void
    {
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
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_dispute']))
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
            ->joinSub($this->historyPairs(['crm_dispute'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_dispute']))
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
    }

    /** paid */
    protected function paidTab(Builder $model): void
    {
        $model->joinSub(
            DB::table('crm_notes')
                ->select('applicant_id', 'sale_id', 'details', 'created_at')
                ->where('moved_tab_to', 'paid')
                ->whereIn('id', function ($subQuery) {
                    $subQuery->select(DB::raw('MAX(id)'))
                        ->from('crm_notes')
                        ->where('moved_tab_to', 'paid')
                        ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_paid']))
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
            ->joinSub($this->historyPairs(['crm_paid'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'crm_notes.applicant_id')
                    ->on('history_pairs.sale_id', '=', 'crm_notes.sale_id');
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
                            ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['crm_paid']))
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
    }

    /** sent cvs */
    protected function sentCvsTab(Builder $model): void
    {
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cleared', 'crm_save']))
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
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cleared', 'crm_save']))
                    ->groupBy('applicant_id', 'sale_id');
            });

        // Derived table for latest cv_notes (if needed for user_name)
        $latestCv = DB::table('cv_notes')
            ->select('applicant_id', 'sale_id', 'user_id', 'id', 'updated_at')
            ->whereIn('id', function ($sub) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('cv_notes')
                    ->whereIn(DB::raw('(applicant_id, sale_id)'), $this->historyPairs(['quality_cleared', 'crm_save']))
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
            ->joinSub($this->historyPairs(['quality_cleared', 'crm_save'])->distinct(), 'history_pairs', function ($join) {
                $join->on('history_pairs.applicant_id', '=', 'applicants.id')
                    ->on('history_pairs.sale_id', '=', 'sales.id');
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
    }
}
