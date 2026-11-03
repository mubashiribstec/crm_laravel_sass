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
use App\Services\Crm\CrmApplicantsQuery;
use App\Services\Crm\CrmApplicantsTable;
// use Illuminate\Http\JsonResponse;

class CrmController extends Controller
{

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

        // Every other action needs the permission its button on the CRM tabs already checks
        // (getCrmApplicantsAjaxRequest), so nobody loses a button they can see. The "no
        // response" actions have a button with no permission check, so crm-index is enough.
        foreach (self::ACTION_PERMISSIONS as $permissions => $actions) {
            $this->middleware('permission:' . $permissions)->only($actions);
        }
    }

    /** Permission(s) (any one) => the actions behind the buttons that check it. */
    private const ACTION_PERMISSIONS = [
        'crm-add-note' => ['updateCrmNotes', 'crmSendRejectedCv', 'updateCrmNoJobNotes', 'crmSendNoJobToRejectedCv',
            'crmStartDateHoldSave'],
        'crm-send-request' => ['crmSendRequest', 'crmSendNoJobRequest'],
        'crm-schedule-interview' => ['crmScheduleInterview', 'crmRequestedInterviewEmailToApplicant'],
        'crm-schedule-interview,crm-send-confirmation' => ['crmRequestConfirm', 'crmRequestReject', 'crmRequestSave'],
        'crm-revert-request' => ['crmRevertRequestedCvToSentCv', 'crmRevertRequestedCvToQuality'],
        'crm-revert' => ['crmRevertRejectedCvToSentCv', 'crmRevertRequestRejectToSentCv', 'crmRevertRequestRejectToRequest',
            'crmRevertConfirmToRequest', 'crmRevertRebookToConfirmation', 'crmRevertAttendedToRebook',
            'crmRevertDeclinedToAttended', 'crmNotAttendedToAttended', 'crmRevertStartDateToAttended',
            'crmRevertStartDateHoldToStartDate', 'crmRevertInvoiceToStartDate', 'crmRevertDisputeToInvoice'],
        'crm-revert-in-quality' => ['crmRevertInQuality', 'crmSentCvNoJobRevertInQuality', 'crmRevertRejectedCvToQuality',
            'crmRequestRejectToQuality', 'crmRevertConfirmToQuality', 'crmRevertRebookToQuality',
            'crmRevertAttendedToQuality', 'crmRevertDeclinedToQuality', 'crmNotAttendedToQuality',
            'crmStartDateToQuality', 'crmStartDateHoldToQuality', 'crmInvoiceToQuality', 'crmInvoiceSentToQuality',
            'crmDisputeToQuality'],
        'crm-accept-confirmation' => ['crmConfirmInterviewToAttend', 'crmConfirmInterviewToNotAttend',
            'crmConfirmInterviewToRebook', 'crmConfirmSave'],
        'crm-accept-rebook' => ['crmRebookSave', 'crmRebookToAttended', 'crmRebookToNotAttended'],
        'crm-accept-attended' => ['crmAttendedSave', 'crmAttendedToDecline', 'crmAttendedToStartDate'],
        'crm-accept-start-date' => ['crmStartDateSave', 'crmStartDateToHold', 'crmStartDateToInvoice'],
    ];

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
        if (!$request->ajax()) {
            return;
        }

        // Query per tab: App\Services\Crm\CrmApplicantsQuery. Columns and action menu: CrmApplicantsTable.
        $query = new CrmApplicantsQuery($request);

        return (new CrmApplicantsTable($query->tab()))->make($query->build(), $query->sorter());
    }

    /** Which of the applicants shown on the CRM page have an unread SMS (polled by crm/list to keep the row blink current). */
    public function getCrmUnreadMessageApplicants(Request $request)
    {
        $ids = array_slice((array) $request->input('applicant_ids', []), 0, 500);

        return response()->json([
            'applicant_ids' => array_keys(Message::applicantIdsWithUnread($ids)),
        ]);
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
            // validate() runs before the transaction starts: nothing to roll back.
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
            // validate() runs before the transaction starts: nothing to roll back.
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
            // validate() runs before the transaction starts: nothing to roll back.
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
