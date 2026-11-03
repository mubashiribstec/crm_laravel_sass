<?php

namespace App\Services\Crm;

use Carbon\Carbon;

/**
 * The modals behind the CRM action menu. A row only gets the modals its tab's buttons
 * (and the crm/list page JS) open, instead of all of them.
 */
class CrmActionModals
{
    /** Render order (the order the action column always used). */
    public const ORDER = [
        'updateCrmNotesModal',
        'crmSentCvToRequestModal',
        'crmRevertInQualityModal',
        'crmRevertRequestedCvToSentCvModal',
        'crmRevertRequestedCvToQualityModal',
        'crmRevertRejectedCvToSentCvModal',
        'crmRevertRejectedCvToQualityModal',
        'crmScheduleInterviewModal',
        'crmReScheduleInterviewModal',
        'crmSendApplicantEmailRequestModal',
        'crmMoveToconfirmationModal',
        'crmMarkRequestConfirmOrRejectModal',
        'crmMoveRequestToNoResponseModal',
        'crmSendApplicantEmailOnRequestRejectModal',
        'crmConfirmationAcceptCVModal',
        'updateCrmNoJobNotesModal',
        'crmSendNoJobRequestModal',
        'crmSentCvNoJobRevertInQualityModal',
        'crmRejectRequestRevertToSentCvModal',
        'crmRejectRequestRevertToRequestModal',
        'crmRejectRequestRevertToQualityModal',
        'crmRevertConfirmationToRequestModal',
        'crmConfirmationRevertToQualityModal',
        'crmRebookRevertToQualityModal',
        'crmAttendedRevertToQualityModal',
        'crmDeclinedRevertToQualityModal',
        'crmRevertNotAttendedToAttendedModal',
        'crmNotAttendedToQualityModal',
        'crmStartDateToQualityModal',
        'crmStartDateHoldToQualityModal',
        'crmInvoiceToQualityModal',
        'crmInvoiceSentToQualityModal',
        'crmDisputeToQualityModal',
        'crmAttendedPreStartDateAcceptCVModal',
        'crmRebookAcceptCVModal',
        'crmRevertRebookToConfirmationModal',
        'crmRevertAttendToRebookModal',
        'crmRevertDeclinedToAttendedModal',
        'crmStartDateAcceptCVModal',
        'crmRevertStartDateToAttendedModal',
        'crmStartDateHoldAcceptCVModal',
        'crmRevertStartDateHoldToStartDateModal',
        'crmInvoiceAcceptCVModal',
        'crmRevertInvoiceToStartDateModal',
        'crmInvoiceSentAcceptCVModal',
        'crmRevertDisputeToInvoiceModal',
        'crmChangePaidStatusModal',
    ];

    public const TAB_MODALS = [
        // crmSentCvToRequestModal: Open CVs' "Send request" uses the Sent CV tab's dialog (audit N-08).
        'open cvs' => ['updateCrmNotesModal', 'crmSentCvToRequestModal', 'crmRevertInQualityModal'],
        'sent cvs (no job)' => ['updateCrmNoJobNotesModal', 'crmSendNoJobRequestModal', 'crmSentCvNoJobRevertInQualityModal'],
        'rejected cvs' => ['crmRevertRejectedCvToSentCvModal', 'crmRevertRejectedCvToQualityModal'],
        'request' => ['crmScheduleInterviewModal', 'crmSendApplicantEmailRequestModal', 'crmMoveToconfirmationModal', 'crmMoveRequestToNoResponseModal', 'crmRevertRequestedCvToSentCvModal', 'crmRevertRequestedCvToQualityModal', 'crmSendApplicantEmailOnRequestRejectModal'],
        'request (no job)' => ['crmScheduleInterviewModal', 'crmSendApplicantEmailRequestModal', 'crmMoveToconfirmationModal', 'crmRevertRequestedCvToSentCvModal', 'crmRevertRequestedCvToQualityModal', 'crmSendApplicantEmailOnRequestRejectModal'],
        'request (no response)' => ['crmMarkRequestConfirmOrRejectModal', 'crmRevertRequestedCvToSentCvModal', 'crmRevertRequestedCvToQualityModal'],
        'rejected by request' => ['crmRejectRequestRevertToSentCvModal', 'crmRejectRequestRevertToRequestModal', 'crmRejectRequestRevertToQualityModal'],
        'confirmation' => ['crmConfirmationAcceptCVModal', 'crmRevertConfirmationToRequestModal', 'crmConfirmationRevertToQualityModal', 'crmSendApplicantEmailOnRequestRejectModal'],
        'rebook' => ['crmRebookAcceptCVModal', 'crmRevertRebookToConfirmationModal', 'crmRebookRevertToQualityModal', 'crmReScheduleInterviewModal'],
        'attended to pre-start date' => ['crmAttendedPreStartDateAcceptCVModal', 'crmRevertAttendToRebookModal', 'crmAttendedRevertToQualityModal', 'crmSendApplicantEmailOnRequestRejectModal'],
        'declined' => ['crmRevertDeclinedToAttendedModal', 'crmDeclinedRevertToQualityModal'],
        'not attended' => ['crmRevertNotAttendedToAttendedModal', 'crmNotAttendedToQualityModal'],
        'start date' => ['crmStartDateAcceptCVModal', 'crmRevertStartDateToAttendedModal', 'crmStartDateToQualityModal'],
        'start date hold' => ['crmStartDateHoldAcceptCVModal', 'crmRevertStartDateHoldToStartDateModal', 'crmStartDateHoldToQualityModal'],
        'invoice' => ['crmInvoiceAcceptCVModal', 'crmRevertInvoiceToStartDateModal', 'crmInvoiceToQualityModal'],
        'invoice sent' => ['crmInvoiceSentAcceptCVModal', 'crmInvoiceSentToQualityModal'],
        'dispute' => ['crmRevertDisputeToInvoiceModal', 'crmDisputeToQualityModal'],
        'paid' => ['crmChangePaidStatusModal'],
        'sent cvs' => ['updateCrmNotesModal', 'crmSentCvToRequestModal', 'crmRevertInQualityModal'],
    ];

    public function __construct(
        protected $emailNotificationSetting = null,
        protected $emailTemplateRequestConfiguration = null,
        protected $emailTemplateRequestRejected = null
    ) {
    }

    /** Unknown tabs get every modal, as before. */
    public function modalsFor(string $tab): array
    {
        return self::TAB_MODALS[$tab] ?? self::ORDER;
    }

    public function render($applicant, string $tab): string
    {
        $wanted = array_flip($this->modalsFor($tab));
        $html = '';
        foreach (self::ORDER as $modal) {
            if (isset($wanted[$modal])) {
                $html .= $this->{$modal}($applicant);
            }
        }

        return $html;
    }

    protected function updateCrmNotesModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmSentCvToRequestModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertInQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertRequestedCvToSentCvModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertRequestedCvToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertRejectedCvToSentCvModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertRejectedCvToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmScheduleInterviewModal($applicant): string
    {
        $html = '';
        /*** Schedule Interview Modal */
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

        return $html;
    }

    protected function crmReScheduleInterviewModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmSendApplicantEmailRequestModal($applicant): string
    {
        $emailNotificationSetting = $this->emailNotificationSetting;
        $emailTemplateRequestConfiguration = $this->emailTemplateRequestConfiguration;
        $html = '';
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

        return $html;
    }

    protected function crmMoveToconfirmationModal($applicant): string
    {
        $emailNotificationSetting = $this->emailNotificationSetting;
        $emailTemplateRequestRejected = $this->emailTemplateRequestRejected;
        $html = '';
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

        return $html;
    }

    protected function crmMarkRequestConfirmOrRejectModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmMoveRequestToNoResponseModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmSendApplicantEmailOnRequestRejectModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmConfirmationAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function updateCrmNoJobNotesModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmSendNoJobRequestModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmSentCvNoJobRevertInQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRejectRequestRevertToSentCvModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRejectRequestRevertToRequestModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRejectRequestRevertToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertConfirmationToRequestModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmConfirmationRevertToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRebookRevertToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmAttendedRevertToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmDeclinedRevertToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertNotAttendedToAttendedModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmNotAttendedToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmStartDateToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmStartDateHoldToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmInvoiceToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmInvoiceSentToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmDisputeToQualityModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmAttendedPreStartDateAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRebookAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertRebookToConfirmationModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertAttendToRebookModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertDeclinedToAttendedModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmStartDateAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertStartDateToAttendedModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmStartDateHoldAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertStartDateHoldToStartDateModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmInvoiceAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertInvoiceToStartDateModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmInvoiceSentAcceptCVModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmRevertDisputeToInvoiceModal($applicant): string
    {
        $html = '';
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

        return $html;
    }

    protected function crmChangePaidStatusModal($applicant): string
    {
        $html = '';
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
    }
}
