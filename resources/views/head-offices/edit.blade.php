@extends('layouts.vertical', ['title' => 'Edit Head Office', 'subTitle' => 'Head Offices'])

@section('css')
    @vite(['node_modules/choices.js/public/assets/styles/choices.min.css'])
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12 col-lg-12">
            <form id="editHeadOfficeForm" action="{{ route('head-offices.update') }}" method="POST" class="needs-validation"
                novalidate enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="office_id" value="{{ $office->id }}">
                <input type="hidden" name="redirect_url" value="{{ $redirect_url }}">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Head Office Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            @if ($office->status == 4 || $office->status == 5)
                                @php $colClass = 'col-lg-4 col-md-6 col-sm-12'; @endphp
                            @else
                                @php $colClass = 'col-lg-3 col-md-6 col-sm-12'; @endphp
                            @endif
                            <div class="{{ $colClass }}">
                                <div class="mb-3">
                                    <label for="office_name" class="form-label">Name</label>
                                    <input type="text" id="office_name" class="form-control"
                                        @cannot('office-edit-name') readonly @endcannot name="office_name"
                                        value="{{ old('office_name', $office->office_name) }}" placeholder="Full Name"
                                        required>
                                    <div class="invalid-feedback">Please provide a name</div>
                                </div>
                            </div>
                            <div class="{{ $colClass }}">
                                <div class="mb-3">
                                    <label for="office_type" class="form-label">Type</label>
                                    <select class="form-select" id="office_type" name="office_type" required>
                                        <option value="">Choose Type</option>
                                        <option value="head_office"
                                            {{ old('office_type', $office->office_type == 'head_office' ? 'selected' : '') }}>
                                            Head Office</option>
                                        <option value="individual"
                                            {{ old('office_type', $office->office_type == 'individual' ? 'selected' : '') }}>
                                            Individual</option>
                                        <option value="independent"
                                            {{ old('office_type', $office->office_type == 'independent' ? 'selected' : '') }}>
                                            Independent</option>
                                    </select>
                                    <div class="invalid-feedback">Please select type</div>
                                </div>
                            </div>
                            <div class="{{ $colClass }}">
                                <div class="mb-3">
                                    <label for="office_postcode" class="form-label">PostCode</label>
                                    <input type="text" id="office_postcode"
                                        @cannot('office-edit-postcode') readonly @endcannot class="form-control"
                                        value="{{ old('office_postcode', $office->office_postcode) }}"
                                        name="office_postcode" placeholder="Enter PostCode" required maxlength="8">
                                    <div class="invalid-feedback">Please provide a postcode</div>
                                </div>
                            </div>

                            <div class="{{ $colClass }}">
                                <div class="mb-3">
                                    <label for="office_website" class="form-label">Website</label>
                                    <input type="url" id="office_website" class="form-control" name="office_website"
                                        value="{{ old('office_website', $office->office_website) }}"
                                        placeholder="Enter URL">
                                </div>
                            </div>
                            @if ($office->status == 4 || $office->status == 5)
                                <div class="col-lg-3 col-md-6 col-sm-12">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="">Choose Status</option>
                                            <option value="1"
                                                {{ old('status', $office->status == '1' ? 'selected' : '') }}>Active
                                            </option>
                                            <option value="4"
                                                {{ old('status', $office->status == '4' ? 'selected' : '') }}>Scrapped
                                            </option>
                                        </select>
                                        <div class="invalid-feedback">Please select type</div>
                                    </div>
                                </div>
                            @endif
                            <div class="col-lg-12">
                                <div class="mb-3 border px-3 py-5 rounded" style="background-color: #e2e2e2;">
                                    <label class="form-label">Contact Persons</label>
                                    <div id="contactPersonsContainer">
                                        @forelse($contacts as $row)
                                            <div class="contact-person-form row g-3 mb-3">
                                                <div class="col-lg-3">
                                                    <input type="text" class="form-control" name="contact_name[]"
                                                        placeholder="Contact Name" required
                                                        value="{{ $row->contact_name }}">
                                                    <div class="invalid-feedback">Please provide a contact name</div>
                                                </div>
                                                <div class="col-lg-3">
                                                    <input type="email" class="form-control" name="contact_email[]"
                                                        placeholder="Contact Email" required
                                                        value="{{ $row->contact_email }}">
                                                    <div class="invalid-feedback">Please provide a valid email</div>
                                                </div>
                                                <div class="col-lg-3">
                                                    <input type="text" class="form-control" name="contact_phone[]"
                                                        placeholder="Contact Phone" maxlength="20"
                                                        value="{{ $row->contact_phone }}">
                                                    <div class="invalid-feedback">Please provide a phone number</div>
                                                </div>
                                                <div class="col-lg-3">
                                                    <input type="text" class="form-control" name="contact_landline[]"
                                                        placeholder="Contact Landline" maxlength="20"
                                                        value="{{ $row->contact_landline }}">
                                                    <div class="invalid-feedback">Please provide a landline number</div>
                                                </div>
                                                @if (!$loop->first)
                                                <div class="col-lg-11">@else<div class="col-lg-12">
                                                @endif
                                                <textarea class="form-control" rows="3" name="contact_note[]" placeholder="Enter Contact Note">{{ $row->contact_note }}</textarea>
                                                <div class="invalid-feedback">Please provide a contact note</div>
                                            </div>
                                            @if (!$loop->first)
                                                <div class="col-lg-1 d-flex align-items-center">
                                                    <button type="button"
                                                        class="btn btn-transparent btn-sm removeContactPersonButton">
                                                        <iconify-icon icon="solar:trash-bin-minimalistic-bold"
                                                            class="text-danger fs-24"></iconify-icon></button>
                                                </div>
                                            @endif
                                    </div>
                                @empty
                                    <div class="contact-person-form row g-3 mb-3">
                                        <div class="col-lg-3">
                                            <input type="text" class="form-control" name="contact_name[]"
                                                placeholder="Contact Name" required>
                                            <div class="invalid-feedback">Please provide a contact name</div>
                                        </div>
                                        <div class="col-lg-3">
                                            <input type="email" class="form-control" name="contact_email[]"
                                                placeholder="Contact Email" required>
                                            <div class="invalid-feedback">Please provide a valid email</div>
                                        </div>
                                        <div class="col-lg-3">
                                            <input type="text" class="form-control" name="contact_phone[]"
                                                placeholder="Contact Phone" maxlength="20">
                                            <div class="invalid-feedback">Please provide a phone number</div>
                                        </div>
                                        <div class="col-lg-3">
                                            <input type="text" class="form-control" name="contact_landline[]"
                                                placeholder="Contact Landline" maxlength="20">
                                            <div class="invalid-feedback">Please provide a landline number</div>
                                        </div>
                                        <div class="col-lg-12">
                                            <textarea class="form-control" rows="3" name="contact_note[]" placeholder="Enter Contact Note"></textarea>
                                            <div class="invalid-feedback">Please provide a contact note</div>
                                        </div>
                                    </div>
                                    @endforelse
                                </div>
                                @canany('office-add-more-contact-btn')
                                    <button type="button" class="btn btn-secondary float-end"
                                        id="addContactPersonButton">Add More</button>
                                @endcanany
                            </div>
                        </div>

                        <script>
                            document.getElementById('addContactPersonButton').addEventListener('click', function() {
                                const container = document.getElementById('contactPersonsContainer');
                                const newForm = document.createElement('div');
                                newForm.classList.add('contact-person-form', 'row', 'g-3', 'mb-3');
                                newForm.innerHTML = `
                                    <div class="col-lg-3">
                                        <input type="text" class="form-control" name="contact_name[]" placeholder="Contact Name" required>
                                        <div class="invalid-feedback">Please provide a contact name</div>
                                    </div>
                                    <div class="col-lg-3">
                                        <input type="email" class="form-control" name="contact_email[]" placeholder="Contact Email" required>
                                        <div class="invalid-feedback">Please provide a valid email</div>
                                    </div>
                                    <div class="col-lg-3">
                                        <input type="text" class="form-control" name="contact_phone[]" placeholder="Contact Phone" required>
                                        <div class="invalid-feedback">Please provide a phone number</div>
                                    </div>
                                    <div class="col-lg-3">
                                        <input type="text" class="form-control" name="contact_landline[]" placeholder="Contact Landline">
                                        <div class="invalid-feedback">Please provide a landline number</div>
                                    </div>
                                    <div class="col-lg-11">
                                        <textarea class="form-control" name="contact_note[]" placeholder="Enter Contact Note"></textarea>
                                        <div class="invalid-feedback">Please provide a landline number</div>
                                    </div>
                                    <div class="col-lg-1 d-flex align-items-center">
                                        <button type="button" class="btn btn-transparent btn-sm removeContactPersonButton"> <iconify-icon icon="solar:trash-bin-minimalistic-bold" class="text-danger fs-24"></iconify-icon></button>
                                    </div>
                                `;
                                container.appendChild(newForm);
                            });

                            document.getElementById('contactPersonsContainer').addEventListener('click', function(e) {
                                const button = e.target.closest('.removeContactPersonButton');
                                if (button) {
                                    const forms = document.querySelectorAll('.contact-person-form');
                                    if (forms.length > 1) {
                                        button.closest('.contact-person-form').remove();
                                    }
                                }
                            });
                        </script>

                        <div class="col-lg-12">
                            <div class="mb-3">
                                <label for="office_notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="office_notes" name="office_notes" rows="7" placeholder="Enter Notes"
                                    required>{{ old('office_notes', $office->office_notes) }}</textarea>
                                <div class="invalid-feedback">Please provide notes</div>
                            </div>
                        </div>
                        <div class="col-lg-12">
                            <div class="mb-3">
                                <label for="officeAttachmentDropzone" class="form-label">Add Attachments</label>
                                <div id="officeAttachmentDropzone" class="dropzone dz-clickable attachment-dropzone">
                                    <div class="dz-message needsclick">
                                        <i class="h1 ri-upload-cloud-2-line"></i>
                                        <h3>Drop files here or click to upload.</h3>
                                        <span class="text-muted fs-13">You can attach multiple files (up to 10 MB each).</span>
                                    </div>
                                </div>
                                <ul class="list-unstyled mb-0" id="officeAttachmentPreview"></ul>
                                <div id="dz-preview-template" style="display:none;">
                                    <li class="mt-2 dz-preview dz-file-preview">
                                        <div class="border rounded">
                                            <div class="d-flex p-2">
                                                <div class="flex-shrink-0 me-3">
                                                    <div class="avatar-sm bg-light rounded d-flex align-items-center justify-content-center">
                                                        <div data-dz-thumbnail class="dz-iconify">
                                                            <iconify-icon icon="solar:file-bold" class="fs-32 text-secondary"></iconify-icon>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="pt-1">
                                                        <h5 class="fs-14 mb-1" data-dz-name></h5>
                                                        <p class="fs-13 text-muted mb-0" data-dz-size></p>
                                                        <strong class="error text-danger" data-dz-errormessage></strong>
                                                    </div>
                                                </div>
                                                <div class="flex-shrink-0 ms-3">
                                                    <button data-dz-remove class="btn btn-sm btn-transparent text-danger" title="Remove file">
                                                        <iconify-icon icon="solar:trash-bin-minimalistic-bold" class="text-danger fs-24"></iconify-icon>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                </div>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Saved Attachments</label>
                                <div id="officeSavedDocuments" class="row g-3">
                                    @forelse ($office->documents as $document)
                                        @php
                                            $extension = strtolower($document->document_extension ?? pathinfo($document->document_name, PATHINFO_EXTENSION));
                                            $previewUrl = route('head-offices.documents.show', ['id' => $office->id, 'documentId' => $document->id]);
                                        @endphp
                                        <div class="col-6 col-md-4 col-lg-2 saved-document">
                                            <div class="card h-100">
                                                <a href="{{ $previewUrl }}" target="_blank" rel="noopener"
                                                    class="d-flex align-items-center justify-content-center bg-light border-bottom overflow-hidden"
                                                    style="height: 150px; color: inherit;">
                                                    @if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true))
                                                        <img src="{{ $previewUrl }}" alt="{{ $document->document_name }}"
                                                            class="w-100 h-100" style="object-fit: contain;">
                                                    @elseif ($extension === 'pdf')
                                                        <iframe src="{{ $previewUrl }}" title="PDF preview"
                                                            class="w-100 h-100" style="border: 0; pointer-events: none;"
                                                            tabindex="-1"></iframe>
                                                    @else
                                                        <div class="text-center">
                                                            <iconify-icon icon="solar:document-text-bold-duotone"
                                                                class="text-primary" style="font-size: 56px;"></iconify-icon>
                                                            <div class="small text-muted">{{ strtoupper($extension ?: 'FILE') }}</div>
                                                        </div>
                                                    @endif
                                                </a>
                                                <div class="card-body p-2">
                                                    <div class="small text-truncate" title="{{ $document->document_name }}">
                                                        {{ $document->document_name }}
                                                    </div>
                                                    <div class="small text-muted mb-2">
                                                        {{ number_format(($document->document_size ?? 0) / 1024, 1) }} KB
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-saved-document"
                                                        data-delete-url="{{ route('head-offices.documents.destroy', ['id' => $office->id, 'documentId' => $document->id]) }}"
                                                        data-restore-url="{{ route('head-offices.documents.restore', ['id' => $office->id, 'documentId' => $document->id]) }}"
                                                        data-document-name="{{ $document->document_name }}"
                                                        data-document-size="{{ $document->document_size ?? 0 }}"
                                                        aria-label="Remove {{ $document->document_name }}" title="Remove attachment">
                                                        <iconify-icon icon="solar:trash-bin-minimalistic-bold" aria-hidden="true" style="font-size: 16px;"></iconify-icon>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="text-muted no-saved-documents col-12">No attachments saved.</div>
                                    @endforelse
                                </div>
                            </div>
                            <div class="mb-3" id="officeDeletedDocumentsSection">
                                <label class="form-label">Temporarily Deleted Attachments</label>
                                <div id="officeDeletedDocuments" class="row g-3">
                                    @forelse ($deletedDocuments as $document)
                                        @php
                                            $extension = strtolower($document->document_extension ?? pathinfo($document->document_name, PATHINFO_EXTENSION));
                                            $previewUrl = route('head-offices.documents.show', ['id' => $office->id, 'documentId' => $document->id]);
                                        @endphp
                                        <div class="col-6 col-md-4 col-lg-2 deleted-document">
                                            <div class="card h-100">
                                                <a href="{{ $previewUrl }}" target="_blank" rel="noopener"
                                                    class="d-flex align-items-center justify-content-center bg-light border-bottom overflow-hidden"
                                                    style="height: 150px; color: inherit;">
                                                    @if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true))
                                                        <img src="{{ $previewUrl }}" alt="{{ $document->document_name }}"
                                                            class="w-100 h-100" style="object-fit: contain;">
                                                    @elseif ($extension === 'pdf')
                                                        <iframe src="{{ $previewUrl }}" title="PDF preview"
                                                            class="w-100 h-100" style="border: 0; pointer-events: none;"
                                                            tabindex="-1"></iframe>
                                                    @else
                                                        <div class="text-center">
                                                            <iconify-icon icon="solar:document-text-bold-duotone"
                                                                class="text-primary" style="font-size: 56px;"></iconify-icon>
                                                            <div class="small text-muted">{{ strtoupper($extension ?: 'FILE') }}</div>
                                                        </div>
                                                    @endif
                                                </a>
                                                <div class="card-body p-2">
                                                    <div class="small text-truncate" title="{{ $document->document_name }}">
                                                        {{ $document->document_name }}
                                                    </div>
                                                    <div class="small text-muted">
                                                        {{ number_format(($document->document_size ?? 0) / 1024, 1) }} KB
                                                    </div>
                                                    <div class="small text-danger mb-2 deleted-at">
                                                        Deleted: {{ $document->deleted_at->format('d M Y, h:i A') }}
                                                    </div>
                                                    @if ($document->delete_reason)
                                                        <div class="small text-muted mb-2 deleted-reason" title="{{ $document->delete_reason }}">
                                                            Reason: {{ \Illuminate\Support\Str::limit($document->delete_reason, 80) }}
                                                        </div>
                                                    @endif
                                                    <button type="button" class="btn btn-sm btn-outline-success restore-saved-document"
                                                        data-restore-url="{{ route('head-offices.documents.restore', ['id' => $office->id, 'documentId' => $document->id]) }}"
                                                        data-delete-url="{{ route('head-offices.documents.destroy', ['id' => $office->id, 'documentId' => $document->id]) }}"
                                                        data-preview-url="{{ $previewUrl }}"
                                                        data-document-name="{{ $document->document_name }}"
                                                        data-document-size="{{ $document->document_size ?? 0 }}"
                                                        title="Restore attachment">Restore</button>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="text-muted no-deleted-documents col-12">No temporarily deleted attachments.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 rounded">
                        <div class="row justify-content-end g-2">
                            <div class="col-lg-2">
                                <a href="{{ route($redirect_url) }}" class="btn btn-dark w-100">Cancel</a>
                            </div>
                            <div class="col-lg-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    Update</button>
                            </div>
                        </div>
                    </div>
                </div>
        </div>
        </form>
    </div>
    </div>
@endsection
@section('script')
    <!-- jQuery CDN (make sure this is loaded before DataTables) -->
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>

    <!-- DataTables CSS (for styling the table) -->
    <link rel="stylesheet" href="{{ asset('css/jquery.dataTables.min.css') }}">

    <!-- DataTables JS (for the table functionality) -->
    <script src="{{ asset('js/jquery.dataTables.min.js') }}"></script>

    <!-- Toastify CSS -->
    <link rel="stylesheet" href="{{ asset('css/toastr.min.css') }}">

    <!-- SweetAlert2 CDN -->
    <script src="{{ asset('js/sweetalert2@11.js') }}"></script>

    <!-- Toastr JS -->
    <script src="{{ asset('js/toastr.min.js') }}"></script>

    <!-- Moment JS -->
    <script src="{{ asset('js/moment.min.js') }}"></script>

    <!-- Summernote CSS -->
    <link rel="stylesheet" href="{{ asset('css/summernote-lite.min.css') }}">

    <!-- Summernote JS -->
    <script src="{{ asset('js/summernote-lite.min.js') }}"></script>

    <script>
        // Form validation
        (function() {
            'use strict'
            const forms = document.querySelectorAll('.needs-validation')
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()

        document.addEventListener('DOMContentLoaded', function() {
            // Handle form submission
            const form = document.getElementById('editHeadOfficeForm');
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                // Collect form data
                const formData = new FormData(form);
                document.querySelector('#officeAttachmentDropzone')?.dropzone?.getAcceptedFiles()
                    .forEach(file => formData.append('attachments[]', file));

                fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            toastr.success(data.message);
                            window.location.href = data.redirect;
                        } else {
                            // Handle validation errors
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = 'Save';

                            if (data.errors) {
                                // Clear previous errors
                                form.querySelectorAll('.is-invalid').forEach(el => {
                                    el.classList.remove('is-invalid');
                                });
                                form.querySelectorAll('.invalid-feedback').forEach(el => {
                                    el.textContent = '';
                                    el.classList.remove('d-block');
                                });

                                // Display new errors
                                Object.entries(data.errors).forEach(([field, messages]) => {
                                    const input = (/^attachments\.\d+$/.test(field) || field === 'attachments')
                                        ? form.querySelector('#officeAttachmentDropzone')
                                        : form.querySelector(`[name="${field}"]`);
                                    const feedback = input?.closest('.mb-3')?.querySelector(
                                        '.invalid-feedback');

                                    if (input && feedback) {
                                        input.classList.add('is-invalid');
                                        feedback.textContent = messages.join(' ');
                                        feedback.classList.add('d-block');
                                    }
                                });
                            } else {
                                if (data.errors) {
                                    let errorMessages = Object.values(data.errors).flat().join('\n');
                                    alert('Validation Errors:\n' + errorMessages);
                                } else {
                                    alert(data.message);
                                }
                            }
                        }
                    })
                    .catch(error => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Save';
                        alert('An unexpected error occurred. Please try again.');
                        console.error('Error:', error);
                    });
            });

            // Postcode formatting
            document.getElementById('applicant_postcode').addEventListener('input', function(e) {
                const cursorPos = this.selectionStart;
                let rawValue = this.value.replace(/[^a-z0-9\s]/gi, '');

                let formattedValue = rawValue.length > 8 ?
                    rawValue.substring(0, 8) :
                    rawValue;

                this.value = formattedValue.toUpperCase();

                const newCursorPos = Math.min(cursorPos, this.value.length);
                this.setSelectionRange(newCursorPos, newCursorPos);
            });

            // Phone number formatting
            ['applicant_phone', 'applicant_landline'].forEach(id => {
                document.getElementById(id)?.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9+]/g, '');
                    if (this.value.startsWith('+')) return;
                    if (this.value.length > 5) {
                        this.value = this.value.replace(/(\d{5})(\d+)/, '$1 $2');
                    }
                });
            });
        });
    </script>
    <script>
        document.addEventListener('click', function(event) {
            const button = event.target.closest('.remove-saved-document');
            if (!button) return;

            Swal.fire({
                title: 'Remove attachment?',
                text: 'This file will be deleted temporarily and removed permanently after 3 days. Please be careful.',
                input: 'textarea',
                inputLabel: 'Reason for temporarily deleting this attachment',
                inputPlaceholder: 'Enter the reason for deletion',
                inputAttributes: { maxlength: 2000 },
                showCancelButton: true,
                confirmButtonText: 'Remove',
                confirmButtonColor: '#dc3545',
                preConfirm: reason => {
                    if (!reason || reason.trim().length < 3) {
                        Swal.showValidationMessage('Please provide a reason of at least 3 characters.');
                        return false;
                    }
                    return reason.trim();
                }
            }).then(result => {
                if (!result.isConfirmed) return;

                button.disabled = true;
                fetch(button.dataset.deleteUrl, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @json(csrf_token()),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ reason: result.value })
                })
                    .then(async response => {
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Unable to remove attachment.');
                        }
                        const card = button.closest('.saved-document');
                        card.classList.replace('saved-document', 'deleted-document');
                        const cardBody = card.querySelector('.card-body');
                        button.remove();

                        const deletedAt = document.createElement('div');
                        deletedAt.className = 'small text-danger mb-2 deleted-at';
                        deletedAt.textContent = `Deleted: ${data.deleted_at}`;
                        const reason = document.createElement('div');
                        reason.className = 'small text-muted mb-2 deleted-reason';
                        reason.textContent = `Reason: ${result.value}`;

                        const restoreButton = document.createElement('button');
                        restoreButton.type = 'button';
                        restoreButton.className = 'btn btn-sm btn-outline-success restore-saved-document';
                        restoreButton.dataset.restoreUrl = button.dataset.restoreUrl;
                        restoreButton.dataset.deleteUrl = button.dataset.deleteUrl;
                        restoreButton.dataset.documentName = button.dataset.documentName;
                        restoreButton.dataset.documentSize = button.dataset.documentSize;
                        restoreButton.title = 'Restore attachment';
                        restoreButton.textContent = 'Restore';
                        cardBody.append(deletedAt, reason, restoreButton);

                        document.querySelector('#officeSavedDocuments .no-saved-documents')?.remove();
                        if (!document.querySelector('#officeSavedDocuments .saved-document')) {
                            document.getElementById('officeSavedDocuments').innerHTML =
                                '<div class="text-muted no-saved-documents col-12">No attachments saved.</div>';
                        }
                        document.getElementById('officeDeletedDocuments').querySelector('.no-deleted-documents')?.remove();
                        document.getElementById('officeDeletedDocuments').append(card);
                        return Swal.fire('Removed', data.message, 'success');
                    })
                    .catch(error => {
                        button.disabled = false;
                        Swal.fire('Unable to remove attachment', error.message, 'error');
                    });
            });
        });
    </script>
    <script>
        document.addEventListener('click', function(event) {
            const button = event.target.closest('.restore-saved-document');
            if (!button) return;

            button.disabled = true;
            fetch(button.dataset.restoreUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Unable to restore attachment.');
                    }

                    const card = button.closest('.deleted-document');
                    const cardBody = card.querySelector('.card-body');
                    cardBody.querySelectorAll('.deleted-at, .deleted-reason').forEach(element => element.remove());
                    button.remove();
                    card.classList.replace('deleted-document', 'saved-document');

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn btn-sm btn-outline-danger remove-saved-document';
                    removeButton.dataset.deleteUrl = button.dataset.deleteUrl;
                    removeButton.dataset.documentName = button.dataset.documentName;
                    removeButton.setAttribute('aria-label', `Remove ${button.dataset.documentName}`);
                    removeButton.title = 'Remove attachment';
                    removeButton.innerHTML = '<iconify-icon icon="solar:trash-bin-minimalistic-bold" aria-hidden="true" style="font-size: 16px;"></iconify-icon>';
                    cardBody.append(removeButton);

                    document.querySelector('#officeSavedDocuments .no-saved-documents')?.remove();
                    document.getElementById('officeSavedDocuments').append(card);

                    if (!document.querySelector('#officeDeletedDocuments .deleted-document')) {
                        document.getElementById('officeDeletedDocuments').innerHTML =
                            '<div class="text-muted no-deleted-documents col-12">No temporarily deleted attachments.</div>';
                    }
                    toastr.success(data.message);
                })
                .catch(error => {
                    button.disabled = false;
                    Swal.fire('Unable to restore attachment', error.message, 'error');
                });
        });
    </script>
@endsection
@section('script-bottom')
    @vite(['resources/js/components/form-fileupload.js'])
@endsection
