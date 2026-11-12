@extends('layouts.vertical', ['title' => 'Send Email to Applicants', 'subTitle' => 'Emails'])

{{-- @section('css')
    @vite(['node_modules/quill/dist/quill.snow.css'])
@endsection --}}

@section('content')

    <div class="card">
        <div class="row g-0">
            <div class="shadow-sm rounded-4">
                <div class="card-header bg-light text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">New Email</h5>
                    <div>
                        <a href="#" id="export_email" class="btn bg-primary text-white">
                            <i class="icon-cloud-upload"></i> Export Emails
                        </a>
                    </div>
                </div>

                <form id="composeEmailForm" method="POST" action="">
                    @csrf
                    <div class="card-body" id="composeCard">
                        <input type="hidden" name="sale_id" id="sale_id" value="{{ $sale->id }}">
                        <div class="mb-3">
                            <input type="text" class="form-control" id="toEmail" name="to_email[]" placeholder="To: " value="{{ $emails }}">
                            <div class="invalid-feedback">Please provide emails</div>
                        </div>

                        <div class="mb-3">
                            <input type="text" class="form-control" id="subject" name="subject" placeholder="Subject"
                                value="{{ $subject }}">
                            <div class="invalid-feedback">Please provide subject</div>
                        </div>

                        <div class="mb-3">
                            {{-- <div id="snow-editor" style="height: 400px;">{!! $formattedMessage !!}</div> --}}
                            {{-- <input type="hidden" name="body" id="emailBody" value=""> --}}
                            <div class="alert alert-info" role="alert">
                                Please do not add your signature to the email body. Your email signature is added automatically.
                            </div>
                            <textarea name="body" id="emailBody" class="summernote form-control @error('body') is-invalid @enderror">{!! $formattedMessage !!}</textarea>
                            <div class="invalid-feedback">Please provide email body</div>
                        </div>

                        <div class="d-flex justify-content-end align-items-center gap-1">
                            <a href="{{ route('resources.directIndex') }}" class="btn bg-dark text-white">
                                 Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" id="sendEmailBtn">Send</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
@section('script')
    <!-- jQuery CDN (make sure this is loaded before DataTables) -->
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>

    <!-- Toastify CSS -->
    <link rel="stylesheet" href="{{ asset('css/toastr.min.css') }}">

    <!-- SweetAlert2 CDN -->
    <script src="{{ asset('js/sweetalert2@11.js')}}"></script>

    <!-- Toastr JS -->
    <script src="{{ asset('js/toastr.min.js')}}"></script>
            <!-- Summernote CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css" rel="stylesheet">

    <!-- Summernote JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>
    <script>
        $("#export_email").on("click", function(e){
            var app_email = $("input[name='to_email[]']")
                .val();

            let csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            $.ajax({
                url: "{{route('exportDirectApplicantsEmails')}}",
                type: "post",
                data: {
                    app_email : app_email,
                    _token: csrfToken
                },
                success: function(response, status, xhr) {
                    var blob = new Blob([response], { type: xhr.getResponseHeader('content-type') });
                    var link = document.createElement('a');
                    link.href = window.URL.createObjectURL(blob);
                    link.download = 'applicants_email_data.csv';
                    link.click();
                    toastr.success('Email export successfully!');

                },
                error: function (response) {

                    toastr.error('Email is not export!');
                }
            });
        });
        $(document).ready(function() {
            // Initialize Summernote and set content
            $('.summernote').summernote({
                height: 500,
                toolbar: [
                    ['style', ['bold', 'italic', 'underline', 'clear']],
                    ['font', ['strikethrough', 'superscript', 'subscript']],
                    ['fontsize', ['fontsize']],
                    ['color', []],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', []],
                    ['view', []]
                ]
            });
        });
        $("#sendEmailBtn").on("click", function (e) {
            e.preventDefault();

            // Extract content from Quill editor
            // const quill = new Quill('#snow-editor'); // Ensure you initialized it globally
            // const email_body = window.quill.root.innerHTML;
            
            const sale_id = $('#sale_id').val(); // Put into hidden input
            const email_body = $('#emailBody').val(); // Put into hidden input
            const app_email = $("#toEmail").val().trim();
            const subject = $("#subject").val().trim();

            let isValid = true;

            // Reset validation
            $("#toEmail, #subject, #emailBody").removeClass('is-invalid');

            if (!app_email) {
                $("#toEmail").addClass('is-invalid');
                isValid = false;
            }

            if (!subject) {
                $("#subject").addClass('is-invalid');
                isValid = false;
            }

            if (!email_body || email_body === '<p><br></p>') {
                $("#snow-editor").addClass('is-invalid');
                isValid = false;
            } else {
                $("#snow-editor").removeClass('is-invalid');
            }

            if (!isValid) return;

            const btn = $(this);
            const originalText = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...');

            $.ajax({
                url: "{{ route('emails.saveEmailsForApplicants') }}",
                type: "POST",
                dataType: "json",
                data: {
                    sale_id: sale_id,
                    email_body: email_body,
                    app_email: app_email,
                    email_subject: subject,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    $("#toEmail").val('');
                    // quill.setContents([{ insert: '\n' }]); // Clear editor
                    toastr.success(response.message);
                },
                error: function (xhr) {
                    toastr.error(xhr.responseJSON.message || 'Email failed to send.');
                },
                complete: function () {
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });

    </script>
    @endsection

    {{-- @section('script-bottom')
        @vite(['resources/js/components/form-quilljs.js'])
    @endsection --}}
