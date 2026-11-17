<div class="offcanvas offcanvas-end border-0" tabindex="-1" id="chatOffcanvas">
    <div class="offcanvas-header bg-primary text-white">
        <div class="d-flex align-items-center">
            <img src="{{ asset('images/users/boy.png') ?? asset('images/users/default.jpg') }}"
                class="rounded-circle me-2" width="40" height="40" id="chatUserAvatar">
            <div>
                <h5 class="mb-0" id="chatUserName"></h5>
                <small id="chatUserPhone"></small>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
            aria-label="Close"></button>
    </div>

    <div class="offcanvas-body p-0 d-flex flex-column">
        <!-- Messages Container -->
        <div class="flex-grow-1 p-3 overflow-auto" id="messagesContainer" style="background-color: #f8f9fa;">
            <!-- Messages will be loaded here -->
            <div class="text-center py-5" id="noMessages">
                <i class="ri-chat-3-line fs-1 text-muted"></i>
                <p class="text-muted">No messages yet</p>
            </div>
        </div>

        <!-- Message Input -->
        <div class="border-top p-3 bg-white">
            <form id="messageForm">
                <input type="hidden" id="applicantId">
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="Type your message..." id="messageInput"
                        required>
                    <button class="btn btn-primary" type="submit">
                        <i class="ri-send-plane-2-fill"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Email Offcanvas -->
<div class="offcanvas offcanvas-end border-0" tabindex="-1" id="emailOffcanvas">
    <div class="offcanvas-header bg-primary text-white">
        <div class="d-flex align-items-center">
            <img src="{{ asset('images/users/boy.png') ?? asset('images/users/default.jpg') }}"
                class="rounded-circle me-2" width="40" height="40" id="emailUserAvatar">
            <div>
                <h5 class="mb-0" id="emailUserName">Recipient Name</h5>
                <small id="emailUserEmail">recipient@example.com</small>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column p-0">
        <!-- Email Form -->
        @php
            $signatureSettings = DB::table('settings')
                ->where('group', 'signature')
                ->pluck('value', 'key');
        @endphp
        <form id="emailForm" class="flex-grow-1 d-flex flex-column"
            data-sender-pseudo-name="{{ auth()->user()->pseudo_name ?? auth()->user()->name ?? '' }}"
            data-logo-url="{{ asset('images/Kingsbury-Personnel-Logo.png') }}"
            data-signature-image="{{ $signatureSettings->get('email_signature_image', '') }}"
            data-signature-company-name="{{ $signatureSettings->get('email_signature_company_name', '') }}"
            data-signature-phone="{{ $signatureSettings->get('email_signature_phone', '') }}"
            data-signature-email="{{ $signatureSettings->get('email_signature_email', '') }}">
            @csrf
            <input type="hidden" id="emailapplicantId" name="applicant_id">
            <input type="hidden" id="emailSaleId" name="sale_id">

            <!-- Header Section -->
            <div class="p-3 border-bottom bg-light">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        @php
                            $primaryEmail = DB::table('settings')->where('key', 'primary_email_address')->value('value')
                                ?: 'info@kingsburypersonnel.com';
                        @endphp
                        <label for="fromEmail" class="form-label fw-semibold">From</label>
                        <input type="email" class="form-control" name="fromEmail" id="fromEmail"
                            value="{{ $primaryEmail }}" readonly required>
                    </div>
                    <div class="col-md-6">
                        <label for="emailTo" class="form-label fw-semibold">To</label>
                        <select class="form-select" id="emailTo" name="to" required>
                            <option value="">Select applicant email</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="emailTitle" class="form-label fw-semibold">Email title</label>
                    <input
                        type="text"
                        class="form-control"
                        id="emailTitle"
                        name="email_title"
                        maxlength="191"
                        placeholder="Enter email title"
                        required>
                </div>

                <div class="mb-0">
                    <label for="emailSubject" class="form-label fw-semibold">Subject
                    </label>
                    <input 
                        type="text" 
                        class="form-control" 
                        id="emailSubject" 
                        name="subject" 
                        maxlength="191"
                        placeholder="Enter email subject" 
                        required>
                </div>
            </div>

            <!-- Email Body -->
            <div class="flex-grow-1 p-3 bg-light overflow-auto">
                <label for="emailBody" class="form-label fw-semibold mb-2">Message
                </label>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                      <textarea 
                        name="emailBody"
                        id="emailBody"
                        class="form-control border-0 p-3 shadow-none summernote"
                        placeholder="Write your email message here..."
                        style="resize:none; min-height:270px;">
                    </textarea>

                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="border-top p-3 bg-white d-flex justify-content-between align-items-center">
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm me-2" data-bs-dismiss="offcanvas">
                        <i class="ri-close-line me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" id="sendEmailBtn">
                        <i class="ri-send-plane-2-fill me-1"></i> Send Email
                    </button>
                </div>
                <small class="text-muted" id="emailStatus"></small>
            </div>
        </form>
    </div>
</div>

<style>
    /* Custom styling for right offcanvas */
    #chatOffcanvas.offcanvas-end {
        position: fixed;
        bottom: 0;
        top: auto;
        right: 0;
        width: 50%;
        max-width: 50%;
        height: 850px;
        transform: translateX(100%);
        border-left: 1px solid rgba(0, 0, 0, .1);
        box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1), 0 -5px 15px rgba(0, 0, 0, 0.1);
        border-radius: 10px 0 0 0;
    }
    
    #emailOffcanvas.offcanvas-end {
        position: fixed;
        bottom: 0;
        top: auto;
        right: 0;
        width: 50%;
        max-width: 50%;
        height: 850px;
        transform: translateX(100%);
        border-left: 1px solid rgba(0, 0, 0, .1);
        box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1), 0 -5px 15px rgba(0, 0, 0, 0.1);
        border-radius: 10px 0 0 0;
    }

    #chatOffcanvas.offcanvas-end.show, #emailOffcanvas.offcanvas-end.show {
        transform: translateX(0);
    }

    /* Message styling */
    .message {
        max-width: 80%;
        margin-bottom: 15px;
        padding: 10px 15px;
        border-radius: 18px;
    }

    .incoming {
        background-color: var(--bs-primary);
        color: #ffffff;
        border-radius: 10px 0 10px 10px;
        padding: 7px 15px;
    }

    .outgoing {
        background-color: var(--bs-secondary);
        color: #ffffff;
        border-radius: 0 10px 10px 10px;
        padding: 7px 15px;
    }

    .message-time {
        font-size: 0.75rem;
        margin-top: 5px;
        display: block;
        text-align: right;
        opacity: 0.7;
    }

    /* Scrollbar styling */
    #messagesContainer::-webkit-scrollbar {
        width: 6px;
    }

    #messagesContainer::-webkit-scrollbar-track {
        background: #f1f1f1;
    }

    #messagesContainer::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 3px;
    }

    /* Responsive adjustments */
    @media (max-width: 992px) {
        #chatOffcanvas.offcanvas-end {
            width: 30%;
            max-width: 30%;
        }
        #emailOffcanvas.offcanvas-end {
            width: 30%;
            max-width: 30%;
        }
    }

    @media (max-width: 768px) {
        #chatOffcanvas.offcanvas-end {
            width: 50%;
            max-width: 50%;
        }
        #emailOffcanvas.offcanvas-end {
            width: 50%;
            max-width: 50%;
        }
    }

    @media (max-width: 576px) {
        #chatOffcanvas.offcanvas-end {
            width: 80%;
            max-width: 80%;
        }
        #emailOffcanvas.offcanvas-end {
            width: 80%;
            max-width: 80%;
        }
    }
</style>
