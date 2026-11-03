<?php

namespace App\Services\Crm;

use App\Support\DialLink;
use Carbon\Carbon;
use Closure;
use Horsefly\EmailTemplate;
use Horsefly\JobCategory;
use Horsefly\JobTitle;
use Horsefly\Message;
use Horsefly\Setting;
use Horsefly\SmsTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * DataTables response for the CRM page: the display columns and the action column.
 */
class CrmApplicantsTable
{
    public function __construct(private string $tabFilter)
    {
    }

    public function make(Builder $model, Closure $applySorting)
    {
        $tabFilter = $this->tabFilter;

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
        // Depends on the user only: checked once, not twice per row.
        $mayShowBlocked = Gate::allows('applicant-show-blocked-data');

        $actionMenu = new CrmActionMenu(
            $tabFilter,
            app()->makeWith(CrmActionModals::class, [
                'emailNotificationSetting' => $emailNotificationSetting,
                'emailTemplateRequestConfiguration' => $emailTemplateRequestConfiguration,
                'emailTemplateRequestRejected' => $emailTemplateRequestRejected,
            ]),
            $smsTemplateCrmSendRequest,
            $smsNotificationSetting
        );

        // Runs on the page rows only, not on the count query.
        $unreadMessageApplicants = [];
        $model->afterQuery(function ($rows) use ($actionMenu, &$unreadMessageApplicants) {
            $actionMenu->prime($rows);
            $unreadMessageApplicants = Message::applicantIdsWithUnread($rows->pluck('id'));
        });

        // CrmDataTable: same as DataTables::eloquent(), with a lighter filtered-records count.
        return CrmDataTable::create($model)
            // Applicants with an unread SMS blink in the list (crm/list.blade.php keeps it up to date).
            ->setRowClass(function ($applicant) use (&$unreadMessageApplicants) {
                return isset($unreadMessageApplicants[(int) $applicant->id]) ? 'crm-unread-message' : '';
            })
            ->setRowAttr(['data-applicant-id' => fn ($applicant) => (int) $applicant->id])
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
            ->addColumn('applicantPhone', function ($applicant) use ($mayShowBlocked) {

                if ($applicant->is_blocked && !$mayShowBlocked) {
                    return "<span class='badge bg-dark'>Blocked</span>";
                }

                $showBlockedData = $applicant->is_blocked && $mayShowBlocked;

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
            ->addColumn('action', fn ($applicant) => $actionMenu->render($applicant))
            ->rawColumns(['notes_detail', 'applicant_email', 'sale_source_name', 'show_created_at', 'user_name', 'applicantPhone', 'schedule_date', 'paid_status', 'job_details', 'applicant_postcode', 'job_title', 'job_category', 'job_source', 'action'])
            ->make(true);
    }
}
