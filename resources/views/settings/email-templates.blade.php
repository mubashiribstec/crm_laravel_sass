@extends('layouts.vertical', ['title' => 'Email Templates', 'subTitle' => 'Administrator'])
@section('style')
<style>
    .dropdown-toggle::after {
        display: none !important;
    }
    table.dataTable.no-footer {
        border-bottom: none !important;
    }
</style>
@endsection
@section('content')
        <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header border-0">
                    <div class="row justify-content-between">
                        <div class="col-lg-12">
                            <div class="text-md-end mt-3">
                                <!-- Button Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button" id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i>  <span id="showFilterStatus">All</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton1">
                                        <a class="dropdown-item" href="#">All</a>
                                        <a class="dropdown-item" href="#">Active</a>
                                        <a class="dropdown-item" href="#">Inactive</a>
                                    </div>
                                </div>
                                <!-- Create User Button triggers modal -->
                                <button type="button" class="btn btn-success ml-1 my-1" onclick="createTemplate()">
                                    <i class="ri-add-line"></i> Create New
                                </button>
                            </div>
                        </div>
                        <!-- end col-->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="template_table" class="table align-middle mb-3">
                            <thead class="bg-light-subtle">
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Slug</th>
                                    <th>From Email</th>
                                    <th>Subject</th>
                                    <th>Template</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- The data will be populated here by DataTables --}}
                            </tbody>
                        </table>
                    </div>
                    <!-- end table-responsive -->
                </div>
            </div>
        </div>
    </div>

    <!-- Create ip address Modal -->
    <div class="modal fade" id="createTemplateModal" tabindex="-1" aria-labelledby="createTemplateModal" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-top">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createTemplateForm">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Title <small>(Event Name)</small></label>
                            <input id="title" type="text" class="form-control" name="title" required>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">From Email</label>
                            <input id="from_email" type="email" class="form-control" name="from_email" required>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input id="subject" type="text" class="form-control" name="subject" required>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Template</label>
                            <textarea id="template" class="form-control summernote" name="template" rows="7" required></textarea>
                            <div class="invalid-feedback"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success" id="savecreateTemplateButton">Save</button>
                </div>
            </div>
        </div>
    </div>

@section('script')
    <!-- jQuery CDN (make sure this is loaded before DataTables) -->
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>

    <!-- DataTables CSS (for styling the table) -->
    <link rel="stylesheet" href="{{ asset('css/jquery.dataTables.min.css')}}">

    <!-- DataTables JS (for the table functionality) -->
    <script src="{{ asset('js/jquery.dataTables.min.js')}}"></script>

    <!-- Toastify CSS -->
    <link rel="stylesheet" href="{{ asset('css/toastr.min.css') }}">

    <!-- SweetAlert2 CDN -->
    <script src="{{ asset('js/sweetalert2@11.js')}}"></script>

    <!-- Toastr JS -->
    <script src="{{ asset('js/toastr.min.js')}}"></script>

    <!-- Moment JS -->
    <script src="{{ asset('js/moment.min.js')}}"></script>
    <!-- Summernote CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css" rel="stylesheet">

    <!-- Summernote JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>  

    <script>
        $(document).ready(function() {
            // Initialize Summernote and set content
            $(`#template`).summernote({
                height: 200,
                toolbar: [
                    ['style', ['bold', 'italic', 'underline', 'clear']],
                    ['font', ['strikethrough', 'superscript', 'subscript']],
                    ['fontsize', ['fontsize']],
                    ['color', []],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link']],
                    ['view', []]
                ]
            });
        });

        $(document).ready(function() {
            // Store the current filter in a variable
            var currentFilter = '';

            // Create loader row
            const loadingRow = `<tr><td colspan="100%" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </td></tr>`;

            // Function to show loader
            function showLoader() {
                $('#template_table tbody').empty().append(loadingRow);
            }

            // Initialize DataTable with server-side processing
            var table = $('#template_table').DataTable({
                processing: false,  // Disable default processing state
                serverSide: true,  // Enables server-side processing
                ajax: {
                    url: @json(route('getEmailTemplates')),  // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        // Add the current filter to the request parameters
                        d.status_filter = currentFilter;  // Send the current filter value as a parameter
                    },
                    beforeSend: function() {
                        showLoader(); // Show loader before AJAX request starts
                    },
                    error: function(xhr) {
                        console.error('DataTable AJAX error:', xhr.status, xhr.responseJSON);
                        $('#template_table tbody').empty().html('<tr><td colspan="100%" class="text-center">Failed to load data</td></tr>');
                    }
                },
                columns: [
                    { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
                    { data: 'title', name: 'email_templates.title' },
                    { data: 'slug', name: 'email_templates.title' },
                    { data: 'from_email', name: 'email_templates.from_email' },
                    { data: 'subject', name: 'email_templates.subject' },
                    { data: 'template', name: 'email_templates.template'  },
                    { data: 'in_active', name: 'email_templates.in_active' },
                    { data: 'action', name: 'action', orderable: false }
                ],
                rowId: function(data) {
                    return 'row_' + data.id; // Assign a unique ID to each row using the 'id' field from the data
                },
                dom: 'flrtip',  // Change the order to 'filter' (f), 'length' (l), 'table' (r), 'pagination' (p), and 'information' (i)
                drawCallback: function (settings) {
                    const api = this.api();
                    const pagination = $(api.table().container()).find('.dataTables_paginate');
                    pagination.empty();

                    const pageInfo = api.page.info();
                    const currentPage = pageInfo.page + 1;
                    const totalPages = pageInfo.pages;

                    if (pageInfo.recordsTotal === 0) {
                        $('#template_table tbody').html('<tr><td colspan="100%" class="text-center">Data not found</td></tr>');
                        return;
                    }

                    let paginationHtml = `
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <nav aria-label="Page navigation">
                                    <ul class="pagination pagination-rounded mb-0">
                                        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                                            <a class="page-link" href="javascript:void(0);" aria-label="Previous" onclick="movePage('previous')">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>`;

                        const visiblePages = 3;
                        const showDots = totalPages > visiblePages + 2;

                        // Always show page 1
                        paginationHtml += `<li class="page-item ${currentPage === 1 ? 'active' : ''}">
                            <a class="page-link" href="javascript:void(0);" onclick="movePage(1)">1</a>
                        </li>`;

                        let start = Math.max(2, currentPage - 1);
                        let end = Math.min(totalPages - 1, currentPage + 1);

                        if (start > 2) {
                            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                        }

                        for (let i = start; i <= end; i++) {
                            paginationHtml += `<li class="page-item ${currentPage === i ? 'active' : ''}">
                                <a class="page-link" href="javascript:void(0);" onclick="movePage(${i})">${i}</a>
                            </li>`;
                        }

                        if (end < totalPages - 1) {
                            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                        }

                        if (totalPages > 1) {
                            paginationHtml += `<li class="page-item ${currentPage === totalPages ? 'active' : ''}">
                                <a class="page-link" href="javascript:void(0);" onclick="movePage(${totalPages})">${totalPages}</a>
                            </li>`;
                        }

                        // Next button
                        paginationHtml += `
                            <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                                <a class="page-link" href="javascript:void(0);" aria-label="Next" onclick="movePage('next')">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                        </nav>

                        <div class="d-flex align-items-center ms-3 text-primary">
                            <span class="me-2">Go to page:</span>
                            <input type="number" id="goToPageInput" min="1" max="${totalPages}" class="form-control form-control-sm" style="width: 80px;" 
                                onkeydown="if(event.key === 'Enter') goToPage(${totalPages})">
                        </div>
                        <small id="goToPageError" class="text-danger mt-1" style="font-size: 12px;"></small>
                        </div>`;

                    pagination.html(paginationHtml);
                },
            });

            // Handle filter button clicks and send filter parameters to the DataTable
            $('.dropdown-item').on('click', function() {
                  currentFilter = $(this).text().toLowerCase();

                // Capitalize each word
                const formattedText = currentFilter
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');

                $('#showFilterStatus').html(formattedText);
                table.ajax.reload(); // Reload with updated status filter
            });
        });

        function goToPage(totalPages) {
            const input = document.getElementById('goToPageInput');
            const errorMessage = document.getElementById('goToPageError');
            let page = parseInt(input.value);

            if (!isNaN(page) && page >= 1 && page <= totalPages) {
                $('#template_table').DataTable().page(page - 1).draw('page');
                input.classList.remove('is-invalid');
            } else {
                input.classList.add('is-invalid');
            }
        }

        // Function to move the page forward or backward
        function movePage(page) {
            var table = $('#template_table').DataTable();
            var currentPage = table.page.info().page + 1;
            var totalPages = table.page.info().pages;

            if (page === 'previous' && currentPage > 1) {
                table.page(currentPage - 2).draw('page');  // Move to the previous page
            } else if (page === 'next' && currentPage < totalPages) {
                table.page(currentPage).draw('page');  // Move to the next page
            } else if (typeof page === 'number' && page !== currentPage) {
                table.page(page - 1).draw('page');  // Move to the selected page
            }
        }

        function showEditModal(id) {
            const modalId = `editTemplateModal-${id}`;
            const formId = `editTemplateForm-${id}`;
            const saveBtnId = `saveEditTemplateButton-${id}`;
            const titleId = `edit_title-${id}`;
            const fromEmailId = `edit_from-${id}`;
            const subjectId = `edit_subject-${id}`;
            const templateId = `edit_template-${id}`;
            const statusId = `editTemplateStatus-${id}`;

            // Remove existing modal to prevent conflicts
            $(`#${modalId}`).remove();

            // Build modal HTML
            const modalHtml = `
                <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-top">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="${modalId}Label">Edit Template</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="${formId}">
                                    <input type="hidden" name="id" value="${id}">
                                    <div class="mb-3">
                                        <label class="form-label">Title <small>(Event Name)</small></label>
                                        <input id="${titleId}" type="text" class="form-control" name="edit_title" required readonly>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">From Email</label>
                                        <input id="${fromEmailId}" type="email" class="form-control" name="edit_from" required>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Subject</label>
                                        <input id="${subjectId}" type="text" class="form-control" name="edit_subject" required>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Template</label>
                                        <textarea id="${templateId}" class="form-control summernote" name="edit_template" rows="7" required></textarea>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select id="${statusId}" class="form-select" name="status" required>
                                            <option value="">Select Status</option>
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-success" id="${saveBtnId}">Update</button>
                            </div>
                        </div>
                    </div>
                </div>`;

            // Append modal to body or a container
            $('#dynamicModalsContainer').length ? $('#dynamicModalsContainer').append(modalHtml) : $('body').append(modalHtml);

            // Fetch template data via AJAX
            $.ajax({
                url: "{{ url('emailEditTemplate') }}",
                method: 'post',
                data: { 
                    id: id,
                    _token: '{{ csrf_token() }}'
                 },
                success: function(response) {
                    console.log(response);
                    // Populate form fields
                    $(`#${titleId}`).val(response.data.title);
                    $(`#${fromEmailId}`).val(response.data.from_email);
                    $(`#${subjectId}`).val(response.data.subject);
                    $(`#${templateId}`).val(response.data.template);
                    $(`#${statusId}`).val(response.data.is_active);

                    // Initialize Summernote and set content
                    $(`#${templateId}`).summernote({
                        height: 200,
                        toolbar: [
                            ['style', ['bold', 'italic', 'underline', 'clear']],
                            ['font', ['strikethrough', 'superscript', 'subscript']],
                            ['fontsize', ['fontsize']],
                            ['color', []],
                            ['para', ['ul', 'ol', 'paragraph']],
                            ['table', ['table']],
                            ['insert', ['link']],
                            ['view', []]
                        ]
                    });
                    $(`#${templateId}`).summernote('code', response.template);

                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById(modalId));
                    modal.show();
                },
                error: function(xhr) {
                    toastr.error('Failed to load template data');
                }
            });

            // Save handler
            $(document).off('click', `#${saveBtnId}`).on('click', `#${saveBtnId}`, function() {
                const form = $(`#${formId}`);

                // Prepare form data
                const formData = {
                    id: form.find('[name="id"]').val(),
                    edit_title: form.find('[name="edit_title"]').val(),
                    edit_from: form.find('[name="edit_from"]').val(),
                    edit_subject: form.find('[name="edit_subject"]').val(),
                    edit_template: $(`#${templateId}`).summernote('code'),
                    status: form.find('[name="status"]').val(),
                    _token: '{{ csrf_token() }}'
                };

                // Clear previous errors
                form.find('.is-invalid').removeClass('is-invalid');
                form.find('.invalid-feedback').text('');

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

                $.ajax({
                    url: '{{ route("emailTemplates.update") }}',
                    method: 'PUT',
                    data: formData,
                    success: function(response) {
                        toastr.success(response.message || 'Template updated successfully');
                        $(`#${modalId}`).modal('hide');
                        $('#template_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        if (xhr.status === 422) {
                            const errors = xhr.responseJSON.errors;
                            for (let field in errors) {
                                const input = form.find(`[name="${field}"]`);
                                input.addClass('is-invalid');
                                input.next('.invalid-feedback').text(errors[field][0]);
                            }
                        } else {
                            toastr.error('An error occurred while updating.');
                        }
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });

            // Clean up modal on hide
            $(`#${modalId}`).on('hidden.bs.modal', function() {
                $(this).remove();
            });
        }

        function createTemplate() {
            $('#createTemplateModal').modal('show');

            $('#savecreateTemplateButton').off('click').on('click', function () {
                let form = $('#createTemplateForm');
                let formData = form.serialize();

                // Clear previous errors
                form.find('.is-invalid').removeClass('is-invalid');
                form.find('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                    );

                $.ajax({
                    url: '{{ route("emailTemplates.store") }}',
                    type: 'POST',
                    data: formData,
                    success: function (response) {
                        toastr.success('Email template created successfully!');
                        $('#createTemplateModal').modal('hide');
                        form[0].reset();

                        $('#template_table').DataTable().ajax.reload(); // Reload the DataTable
                    },
                    error: function (xhr) {
                        if (xhr.status === 422) {
                            let errors = xhr.responseJSON.errors;
                            for (let key in errors) {
                                let input = form.find('[name="' + key + '"]');
                                input.addClass('is-invalid');
                                input.after('<div class="invalid-feedback">' + errors[key][0] + '</div>');
                            }
                        } else {
                            alert('An error occurred.');
                        }
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        // function deleteTemplate(id) {
        //     Swal.fire({
        //     title: 'Are you sure?',
        //     text: 'This template will be permanently deleted. Are you sure you want to continue?',
        //     icon: 'warning',
        //     showCancelButton: true,
        //     customClass: {
        //         confirmButton: 'btn bg-danger text-white me-2 mt-2',
        //         cancelButton: 'btn btn-secondary mt-2'
        //     },
        //     cancelButtonColor: '#d33',
        //     confirmButtonText: 'Yes, delete it!'
        //     }).then((result) => {
        //     if (result.isConfirmed) {
        //         $.ajax({
        //         url: '{{ route("emailTemplates.delete") }}',
        //         type: 'post',
        //         data: {
        //             id: id,
        //             _token: '{{ csrf_token() }}'
        //         },
        //         success: function(response) {
        //             toastr.success(response.message || 'Template deleted successfully!');
        //             $('#template_table').DataTable().ajax.reload();
        //         },
        //         error: function(xhr) {
        //             toastr.error('An error occurred while deleting the template.');
        //         }
        //         });
        //     }
        //     });
        // }
    </script>
    
@endsection
@endsection