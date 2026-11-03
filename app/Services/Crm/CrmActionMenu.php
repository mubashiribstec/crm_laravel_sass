<?php

namespace App\Services\Crm;

use Horsefly\Message;
use Horsefly\SentEmail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The CRM action column: the current tab's dropdown buttons, the common items and the modals.
 */
class CrmActionMenu
{
    /** Tabs whose buttons show the latest sent email / incoming SMS. */
    private const SENT_EMAIL_TABS = ['request', 'request (no job)'];
    private const MESSAGE_TABS = ['request', 'request (no job)', 'request (no response)'];

    private array $abilities = [];
    private ?array $sentEmails = null;
    private ?array $messages = null;

    public function __construct(
        private string $tabFilter,
        private CrmActionModals $modals,
        private $smsTemplateCrmSendRequest = null,
        private $smsNotificationSetting = null
    ) {
    }

    public function render($applicant): string
    {
        $tabFilter = $this->tabFilter;
        $smsTemplateCrmSendRequest = $this->smsTemplateCrmSendRequest;
        $smsNotificationSetting = $this->smsNotificationSetting;

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

        $actionButtons = $this->buttons($applicant, $tabFilter, $formattedMessage);

        $html .= $actionButtons;  // Using the action buttons defined earlier
        $url = route('crmNotesHistoryIndex', ['applicant_id' => (int)$applicant->id, 'sale_id' => (int)$applicant->sale_id]);
        $html .= '<li><hr class="dropdown-divider"></li>';
        // Common actions
        $html .= '<li><a class="dropdown-item" target="_blank" href="' . $url . '">Notes History</a></li>';
        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . (int)$applicant->sale_unit_id . ')">Manager Details</a></li>';
        $html .= '</ul></div>';

        return $html . $this->modals->render($applicant, $tabFilter);
    }

    /**
     * Loads the latest sent email / incoming SMS for all rows of the page in one query each,
     * instead of one query per row. Every row keeps its own "ORDER BY created_at DESC LIMIT 1".
     */
    public function prime(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        if (in_array($this->tabFilter, self::SENT_EMAIL_TABS, true)) {
            $union = null;
            foreach ($rows->unique(fn ($row) => $this->sentEmailKey($row)) as $row) {
                $query = SentEmail::query()
                    ->select('sent_emails.*')
                    ->selectRaw('? as crm_row_key', [$this->sentEmailKey($row)])
                    ->where('applicant_id', $row->id)
                    ->where('sale_id', $row->sale_id)
                    ->latest()
                    ->limit(1);
                $union ? $union->unionAll($query) : $union = $query;
            }
            $this->sentEmails = $union->get()->keyBy('crm_row_key')->all();
        }

        if (in_array($this->tabFilter, self::MESSAGE_TABS, true)) {
            $union = null;
            foreach ($rows->unique(fn ($row) => $this->messageKey($row)) as $row) {
                $query = Message::query()
                    ->select('messages.*')
                    ->selectRaw('? as crm_row_key', [$this->messageKey($row)])
                    ->whereIn('phone_number', [
                        $row->applicant_phone,
                        $row->applicant_phone_secondary
                    ])
                    ->where('status', 'incoming')
                    ->where('module_type', 'Horsefly\\Applicant')
                    ->orderBy('created_at', 'desc')
                    ->limit(1);
                $union ? $union->unionAll($query) : $union = $query;
            }
            $this->messages = $union->get()->keyBy('crm_row_key')->all();
        }
    }

    protected function buttons($applicant, $tabFilter, $formattedMessage): string
    {
        return match ($tabFilter) {
            'open cvs' => $this->openCvsButtons($applicant, $tabFilter, $formattedMessage),
            'sent cvs (no job)' => $this->sentCvsNoJobButtons($applicant, $tabFilter, $formattedMessage),
            'rejected cvs' => $this->rejectedCvsButtons($applicant, $tabFilter, $formattedMessage),
            'request' => $this->requestButtons($applicant, $tabFilter, $formattedMessage),
            'request (no job)' => $this->requestNoJobButtons($applicant, $tabFilter, $formattedMessage),
            'request (no response)' => $this->requestNoResponseButtons($applicant, $tabFilter, $formattedMessage),
            'rejected by request' => $this->rejectedByRequestButtons($applicant, $tabFilter, $formattedMessage),
            'confirmation' => $this->confirmationButtons($applicant, $tabFilter, $formattedMessage),
            'rebook' => $this->rebookButtons($applicant, $tabFilter, $formattedMessage),
            'attended to pre-start date' => $this->attendedToPreStartDateButtons($applicant, $tabFilter, $formattedMessage),
            'declined' => $this->declinedButtons($applicant, $tabFilter, $formattedMessage),
            'not attended' => $this->notAttendedButtons($applicant, $tabFilter, $formattedMessage),
            'start date' => $this->startDateButtons($applicant, $tabFilter, $formattedMessage),
            'start date hold' => $this->startDateHoldButtons($applicant, $tabFilter, $formattedMessage),
            'invoice' => $this->invoiceButtons($applicant, $tabFilter, $formattedMessage),
            'invoice sent' => $this->invoiceSentButtons($applicant, $tabFilter, $formattedMessage),
            'dispute' => $this->disputeButtons($applicant, $tabFilter, $formattedMessage),
            'paid' => $this->paidButtons($applicant, $tabFilter, $formattedMessage),
            default => $this->sentCvButtons($applicant, $tabFilter, $formattedMessage),
        };
    }

    /** Permissions don't depend on the row (Spatie checks the ability name only), so check each once. */
    protected function allows(string $ability, $arguments = []): bool
    {
        return $this->abilities[$ability] ??= Gate::allows($ability, $arguments);
    }

    protected function latestSentEmail($applicant)
    {
        if ($this->sentEmails !== null) {
            return $this->sentEmails[$this->sentEmailKey($applicant)] ?? null;
        }

        return SentEmail::where('applicant_id', $applicant->id)
            ->where('sale_id', $applicant->sale_id)
            ->latest()->first();
    }

    protected function latestIncomingMessage($applicant)
    {
        if ($this->messages !== null) {
            return $this->messages[$this->messageKey($applicant)] ?? null;
        }

        return Message::whereIn('phone_number', [
            $applicant->applicant_phone,
            $applicant->applicant_phone_secondary
        ])
            ->where('status', 'incoming')
            ->where('module_type', 'Horsefly\\Applicant')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    private function sentEmailKey($row): string
    {
        return $row->id . '-' . $row->sale_id;
    }

    private function messageKey($row): string
    {
        return json_encode([$row->applicant_phone, $row->applicant_phone_secondary]);
    }

    /** open cvs */
    protected function openCvsButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-add-note', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-send-request', [$applicant, $tabFilter])) {
            // Same request dialog as the Sent CV tab (crmSentCvToRequestModal; its modal is rendered
            // for every row). It used to call crmSendRequestModal(), which doesn't exist, so the item
            // did nothing (audit N-08). tab=open_cv also moves the open CV on.
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
                                        onclick="crmSentCvToRequestModal(this, ' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ', \'open_cv\')">
                                        Send Request
                                        </a>
                                    </li>';
        }
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** sent cvs (no job) */
    protected function sentCvsNoJobButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-add-note', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-send-request', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** rejected cvs */
    protected function rejectedCvsButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** request */
    protected function requestButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        $emailText = '<span class="badge bg-warning"><i class="ri-inbox-line text-white" title="Sent"></i> Email Not Sent</span>';
        $sentEmail = $this->latestSentEmail($applicant);

        if ($sentEmail && $sentEmail->status == '1') {
            $emailText = '<span class="badge bg-success"><i class="ri-inbox-line text-white" title="Sent"></i> Email Sent</span>';
        } elseif ($sentEmail && $sentEmail->status == '2') {
            $emailText = '<span class="badge bg-danger"><i class="ri-inbox-line text-white" title="Sent"></i> Email Failed</span>';
        } elseif ($sentEmail && $sentEmail->status == '0') {
            $emailText = '<span class="badge bg-info"><i class="ri-inbox-line text-white" title="Sent"></i> Email Pending</span>';
        }

        $applicant_msgs = $this->latestIncomingMessage($applicant);

        $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0)" >' . $emailText . '</a></li>';
        if ($applicant->schedule_time && $applicant->schedule_date && $applicant->interview_status == 1) {
            $actionButtons .= '<li><a href="javascript:void(0);" class="dropdown-item disabled text-danger">
                                                        <i class="ri-lock-line"></i> Already Scheduled</a></li>';
        } else {
            if ($this->allows('crm-schedule-interview', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-schedule-interview', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-request', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-request', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** request (no job) */
    protected function requestNoJobButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        // $emailIcon = '<i class="fas fa-envelope text-secondary" title="Not Sent"></i>';
        $emailText = 'Not Sent';
        $sentEmail = $this->latestSentEmail($applicant);

        if ($sentEmail && $sentEmail->status == '1') {
            $emailText = '<span class="badge bg-success"><i class="ri-inbox-line text-white" title="Sent"></i> Email Sent</span>';
        } elseif ($sentEmail && $sentEmail->status == '2') {
            $emailText = '<span class="badge bg-danger"><i class="ri-inbox-line text-white" title="Sent"></i> Email Failed</span>';
        } elseif ($sentEmail && $sentEmail->status == '0') {
            $emailText = '<span class="badge bg-info"><i class="ri-inbox-line text-white" title="Sent"></i> Email Pending</span>';
        }

        $applicant_msgs = $this->latestIncomingMessage($applicant);


        $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0)" >Email ' . $emailText . '</a></li>';
        if ($applicant->schedule_time && $applicant->schedule_date && $applicant->interview_status == 1) {
            $actionButtons .= '<li><a href="javascript:void(0);" class="dropdown-item disabled text-danger">Already Scheduled</a></li>';
        } else {
            if ($this->allows('crm-schedule-interview', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-send-confirmation', [$applicant, $tabFilter])) {
            $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#crmMoveToconfirmationModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                            data-applicant-id="' . (int)$applicant->id . '"
                                            data-sale-id="' . (int)$applicant->sale_id . '"
                                            onclick="crmMoveToconfirmationModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                            Move to Confirmation
                                        </a></li>';
        }
        if ($this->allows('crm-revert-request', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-request', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** request (no response) */
    protected function requestNoResponseButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        $applicant_msgs = $this->latestIncomingMessage($applicant);


        $actionButtons .= '<li><a class="dropdown-item" 
                                    href="javascript:void(0);" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#crmMarkRequestConfirmOrRejectModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                    data-applicant-id="' . (int)$applicant->id . '"
                                    data-sale-id="' . (int)$applicant->sale_id . '"
                                    onclick="crmMarkRequestConfirmOrRejectModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                    Mark Confirm / Reject CV
                                </a></li>';

        if ($this->allows('crm-revert-request', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-request', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** rejected by request */
    protected function rejectedByRequestButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** confirmation */
    protected function confirmationButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-accept-confirmation', [$applicant, $tabFilter])) {
            $actionButtons .= '<li><a class="dropdown-item" href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmConfirmationAcceptCVModal' . (int)$applicant->id . '-' . (int)$applicant->sale_id . '"
                                        data-applicant-id="' . (int)$applicant->id . '"
                                        data-sale-id="' . (int)$applicant->sale_id . '"
                                        onclick="crmConfirmationAcceptCVModal(' . (int)$applicant->id . ', ' . (int)$applicant->sale_id . ')">
                                        Accept CV
                                    </a></li>';
        }
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** rebook */
    protected function rebookButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-accept-rebook', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** attended to pre-start date */
    protected function attendedToPreStartDateButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-accept-attended', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** declined */
    protected function declinedButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** not attended */
    protected function notAttendedButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** start date */
    protected function startDateButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-accept-start-date', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** start date hold */
    protected function startDateHoldButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-add-note', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** invoice */
    protected function invoiceButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-accept-invoice', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** invoice sent */
    protected function invoiceSentButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-accept-invoice-sent', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** dispute */
    protected function disputeButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-revert', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** paid */
    protected function paidButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        $paid_status_button = ($applicant->paid_status == 'close') ? 'Open' : 'Close';

        if ($this->allows('crm-paid-toggle-status', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-paid-revert', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }

    /** sent cvs, and any tab without its own buttons (the old switch had `default:` on 'sent cv') */
    protected function sentCvButtons($applicant, $tabFilter, $formattedMessage): string
    {
        $actionButtons = '';
        if ($this->allows('crm-add-note', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-send-request', [$applicant, $tabFilter])) {
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
        if ($this->allows('crm-revert-in-quality', [$applicant, $tabFilter])) {
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

        return $actionButtons;
    }
}
