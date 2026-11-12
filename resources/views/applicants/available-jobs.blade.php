@extends('layouts.vertical', ['title' => 'Available Jobs', 'subTitle' => 'Applicants'])
@section('content')

    <div class="row">
        <div class="col-lg-12">
            <div class="card card-highlight">
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <ul class="list-unstyled mb-0">
                                        <li><strong>PostCode:</strong>
                                            <span style="font-size:18px;">
                                                {{ strtoupper($applicant->applicant_postcode) ?? 'N/A' }}
                                            </span>
                                            <button type="button"
                                                class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode"
                                                data-postcode="{{ strtoupper($applicant->applicant_postcode ?? 'N/A') }}"
                                                title="Copy Postcode">
                                                <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                            </button>
                                        </li>
                                        <li><strong>Name:</strong> {{ $applicant->applicant_name ?? 'N/A' }}</li>
                                        <li><strong>Email <small>(Primary)</small>:</strong>
                                            {{ $applicant->applicant_email ?? 'N/A' }}</li>
                                        <li><strong>Email <small>(Secondary)</small>:</strong>
                                            {{ $applicant->applicant_email_secondary ?? 'N/A' }}</li>
                                        <li><strong>Phone <small>(Primary)</small>:</strong>
                                            {{ $applicant->applicant_phone ?? 'N/A' }}</li>
                                        <li><strong>Phone <small>(Secondary)</small>:</strong>
                                            {{ $applicant->applicant_phone_secondary ?? 'N/A' }}</li>
                                        <li><strong>Landline:</strong> {{ $applicant->applicant_landline ?? 'N/A' }}</li>
                                    </ul>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <ul class="list-unstyled mb-0">
                                        <li><strong>Applicant ID#:</strong> {{ $applicant->id ?? 'N/A' }}</li>
                                        <li><strong>Gender:</strong>
                                            @if ($applicant->gender == 'm')
                                                Male
                                            @elseif($applicant->gender == 'f')
                                                Female
                                            @else
                                                Unknown
                                            @endif
                                        </li>
                                        <li><strong>Category:</strong>
                                            {{ $jobCategory ? ucwords($jobCategory->name) . $jobType : 'N/A' }}</li>
                                        <li><strong>Title:</strong> {{ $jobTitle ? ucwords($jobTitle->name) : 'N/A' }}</li>
                                        <li><strong>Source:</strong> {{ $jobSource ? ucwords($jobSource->name) : 'N/A' }}
                                        </li>
                                        <li><strong>Status:</strong>
                                            @php
                                                $status = $applicant->status;
                                                if ($status == '1') {
                                                    $statusClass = '<span class="badge bg-success">Active</span>';
                                                } else {
                                                    $statusClass = '<span class="badge bg-danger">Inactive</span>';
                                                }
                                            @endphp
                                            {!! $statusClass !!}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row justify-content-center">
        <div class="col-xl-12 col-lg-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-xl-12">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="card-title">Active Jobs within {{ $radius }}KMs /
                                    {{ $radiusInMiles }}Miles</h4>
                                <div>
                                    <!-- Button Dropdown -->
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButton4" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-filter-line me-1"></i> <span id="showFilterStatus">All</span>
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton4">
                                            <a class="dropdown-item status-filter" href="#">All</a>
                                            <a class="dropdown-item status-filter" href="#">Open</a>
                                            <a class="dropdown-item status-filter" href="#">Sent</a>
                                            <a class="dropdown-item status-filter" href="#">Reject Job</a>
                                            <a class="dropdown-item status-filter" href="#">Paid</a>
                                        </div>
                                    </div>
                                    {{-- <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button" id="dropdownMenuButton5" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-download-line me-1"></i> Export
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton5">
                                            <a class="dropdown-item" href="{{ route('applicantsExport', ['type' => 'withinRadius', 'radius' => $radius]) }}">Export Data</a>
                                        </div>
                                    </div> --}}
                                </div>
                                <!-- Button Dropdown -->
                            </div>
                            <div class="card">
                                <div class="card-body p-3">
                                    <div class="table-responsive">
                                        <table id="sales_table" class="table align-middle mb-3">
                                            <thead class="bg-light-subtle">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Date</th>
                                                    <th>Head Office</th>
                                                    <th>Unit Name</th>
                                                    <th>Position Type</th>
                                                    <th>Title</th>
                                                    <th>Category</th>
                                                    <th>PostCode</th>
                                                    <th>Experience</th>
                                                    <th>Qualification</th>
                                                    <th>Salary</th>
                                                    <th>CV Limit</th>
                                                    <th>Notes</th>
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
                </div>
            </div>
        </div>
    </div>
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
                $('#sales_table tbody').empty().append(loadingRow);
            }

            // Initialize DataTable with server-side processing
            var table = $('#sales_table').DataTable({
                processing: false, // Disable default processing state
                serverSide: true, // Enables server-side processing
                ajax: {
                    url: @json(route('getAvailableJobs')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        d.applicant_id = {{ $applicant->id }};
                        d.radius = {{ $radius }};
                        // Add the current filter to the request parameters
                        d.status_filter = currentFilter;
                        // Clean up search parameter
                        if (d.search && d.search.value) {
                            d.search.value = d.search.value.toString().trim();
                        }
                    },
                    beforeSend: function() {
                        showLoader(); // Show loader before AJAX request starts
                    },
                    error: function(xhr) {
                        console.error('DataTable AJAX error:', xhr.status, xhr.responseJSON);
                        $('#applicants_table tbody').empty().html(
                            '<tr><td colspan="100%" class="text-center">Failed to load data</td></tr>'
                        );
                    }
                },
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'updated_at',
                        name: 'sales.updated_at'
                    },
                    {
                        data: 'office_name',
                        name: 'offices.office_name'
                    },
                    {
                        data: 'unit_name',
                        name: 'units.unit_name'
                    },
                    {
                        data: 'position_type',
                        name: 'sales.position_type',
                        searchable: false
                    },
                    {
                        data: 'job_title',
                        name: 'job_titles.name'
                    },
                    {
                        data: 'job_category',
                        name: 'job_categories.name'
                    },
                    {
                        data: 'sale_postcode',
                        name: 'sales.sale_postcode'
                    },
                    {
                        data: 'experience',
                        name: 'sales.experience'
                    },
                    {
                        data: 'qualification',
                        name: 'sales.qualification'
                    },
                    {
                        data: 'salary',
                        name: 'sales.salary'
                    },
                    {
                        data: 'cv_limit',
                        name: 'sales.cv_limit'
                    },
                    {
                        data: 'sale_notes',
                        name: 'sales.sale_notes',
                        orderable: false
                    },
                    {
                        data: 'status',
                        name: 'sales.status',
                        orderable: false
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false
                    }
                ],
                columnDefs: [
                    {
                        targets: 13, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    },
                ],
                rowId: function(data) {
                    return 'row_' + data
                        .id; // Assign a unique ID to each row using the 'id' field from the data
                },
                dom: 'flrtip', // Change the order to 'filter' (f), 'length' (l), 'table' (r), 'pagination' (p), and 'information' (i)
                drawCallback: function(settings) {
                    const api = this.api();
                    const pagination = $(api.table().container()).find('.dataTables_paginate');
                    pagination.empty();

                    const pageInfo = api.page.info();
                    const currentPage = pageInfo.page + 1;
                    const totalPages = pageInfo.pages;

                    if (pageInfo.recordsTotal === 0) {
                        $('#sales_table tbody').html(
                            '<tr><td colspan="100%" class="text-center">Data not found</td></tr>');
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
                        paginationHtml +=
                            `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                    }

                    for (let i = start; i <= end; i++) {
                        paginationHtml += `<li class="page-item ${currentPage === i ? 'active' : ''}">
                                <a class="page-link" href="javascript:void(0);" onclick="movePage(${i})">${i}</a>
                            </li>`;
                    }

                    if (end < totalPages - 1) {
                        paginationHtml +=
                            `<li class="page-item disabled"><span class="page-link">...</span></li>`;
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
            // Status filter dropdown handler
            $('.status-filter').on('click', function() {
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
                $('#sales_table').DataTable().page(page - 1).draw('page');
                input.classList.remove('is-invalid');
            } else {
                input.classList.add('is-invalid');
            }
        }

        // Function to move the page forward or backward
        function movePage(page) {
            var table = $('#sales_table').DataTable();
            var currentPage = table.page.info().page + 1;
            var totalPages = table.page.info().pages;

            if (page === 'previous' && currentPage > 1) {
                table.page(currentPage - 2).draw('page'); // Move to the previous page
            } else if (page === 'next' && currentPage < totalPages) {
                table.page(currentPage).draw('page'); // Move to the next page
            } else if (typeof page === 'number' && page !== currentPage) {
                table.page(page - 1).draw('page'); // Move to the selected page
            }
        }

        // Function to mark as not interested modal
        function markNotInterestedModal(applicantID, saleID) {
            const modalId = `markApplicantNotInterestedModal-${applicantID}-${saleID}`;
            const formId = `markApplicantNotInterestedForm-${applicantID}-${saleID}`;
            const textareaId = `detailsTextareaApplicantNotInterested-${applicantID}-${saleID}`;
            const saveBtnId = `saveApplicantNotInterestedButton-${applicantID}-${saleID}`;

            // Append modal if not exists
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Mark As Not Interested On Sale</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="${formId}">
                                        <div class="mb-3">
                                            <label for="${textareaId}" class="form-label">Details</label>
                                            <textarea class="form-control" id="${textareaId}" rows="4" required></textarea>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Reset form
            $(`#${formId}`)[0].reset();
            $(`#${textareaId}`).removeClass('is-invalid is-valid').next('.invalid-feedback').remove();

            // Show modal
            $(`#${modalId}`).modal('show');

            // Handle save click
            $(`#${saveBtnId}`).off('click').on('click', function() {
                const notes = $(`#${textareaId}`).val();

                if (!notes) {
                    $(`#${textareaId}`).addClass('is-invalid');
                    if ($(`#${textareaId}`).next('.invalid-feedback').length === 0) {
                        $(`#${textareaId}`).after('<div class="invalid-feedback">Please provide details.</div>');
                    }

                    $(`#${textareaId}`).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                $(`#${textareaId}`).removeClass('is-invalid').addClass('is-valid');
                $(`#${textareaId}`).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                // AJAX
                $.ajax({
                    url: '{{ route('markApplicantNotInterestedOnSale') }}',
                    type: 'POST',
                    data: {
                        applicant_id: applicantID,
                        sale_id: saleID,
                        details: notes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Marked as not interested successfully!');
                        $(`#${modalId}`).modal('hide');
                        $(`#${formId}`)[0].reset();
                        $(`#${textareaId}`).removeClass('is-valid').next('.invalid-feedback').remove();
                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while saving notes.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        // Function to show the notes modal
        function showNotesModal(saleID, notes, officeName, unitName, unitPostcode) {
            const modalId = `showNotesModal_${saleID}`;

            // Append the modal HTML only once
            if ($('#' + modalId).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label">
                        <div class="modal-dialog modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Sale Notes</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body ">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                <div class="modal-footer d-none">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Show the modal immediately with the loader
            const $modal = $('#' + modalId);
            $modal.find('.modal-body').html(`
                <div class="d-flex justify-content-center align-items-center" style="min-height: 150px;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `);
            $modal.modal('show');

            // Simulate async loading (replace with real fetch if needed)
            setTimeout(() => {
                const html = `
                    Office Name: <strong>${officeName}</strong><br>
                    Unit Name: <strong>${unitName}</strong><br>
                    Postcode: <strong>${unitPostcode}</strong><br>
                    Notes Detail: <p style="line-height: 1.6;">${notes}</p>
                `;

                $modal.find('.modal-body').html(html);
                $modal.find('.modal-footer').removeClass('d-none');
            }, 500); // simulate delay
        }

        // Function to send cv modal
        function legacySendCVModal(applicantID, saleID, applicantPostcode, applicantNursingHomeExp) {
            const modalId = `sendCVModal-${applicantID}-${saleID}`;
            const formId = `sendCV_form_${applicantID}_${saleID}`;
            const noteId = `note_details_${applicantID}_${saleID}`;
            const saveBtnId = `saveSendCVBtn_${applicantID}_${saleID}`;

            // Append modal if not exists
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Fill Out Form To Send CV</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form class="form-horizontal" id="${formId}">
                                        <input type="hidden" name="applicant_id" value="${applicantID}">
                                        <input type="hidden" name="sale_id" value="${saleID}">

                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">1.</strong> Current Employer Name</label>
                                            <div class="col-sm-9">
                                                <input type="text" name="current_employer_name" class="form-control" placeholder="Enter Employer Name">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">2.</strong> PostCode</label>
                                            <div class="col-sm-9">
                                                <input type="text" name="postcode" class="form-control" placeholder="Enter PostCode" value="${applicantPostcode}">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">3.</strong> Current/Expected Salary</label>
                                            <div class="col-sm-9">
                                                <input type="number" name="expected_salary" class="form-control" placeholder="Enter Salary">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">4.</strong> Qualification</label>
                                            <div class="col-sm-9">
                                                <input type="text" name="qualification" class="form-control" placeholder="Enter Qualification">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">5.</strong> Transport Type</label>
                                            <div class="col-sm-9 d-flex align-items-center">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="transport_type[]" id="by_walk" value="By Walk"><label class="form-check-label" for="by_walk">By Walk</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="transport_type[]" id="cycle" value="Cycle">
                                                    <label class="form-check-label" for="cycle">Cycle</label>
                                                </div>
                                                <div class="form-check form-check-inline ml-3">
                                                    <input class="form-check-input" type="checkbox" name="transport_type[]" id="car" value="Car">
                                                    <label class="form-check-label" for="car">Car</label>
                                                </div>
                                                <div class="form-check form-check-inline ml-3">
                                                    <input class="form-check-input" type="checkbox" name="transport_type[]" id="public_transport" value="Public Transport">
                                                    <label class="form-check-label" for="public_transport">Public Transport</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">6.</strong> Shift Pattern</label>
                                            <div class="col-sm-9 d-flex align-items-center">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="shift_pattern[]" id="day" value="Day">
                                                    <label class="form-check-label" for="day">Day</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="shift_pattern[]" id="night" value="Night">
                                                    <label class="form-check-label" for="night">Night</label>
                                                </div>
                                                <div class="form-check form-check-inline ml-3">
                                                    <input class="form-check-input" type="checkbox" name="shift_pattern[]" id="full_time" value="Full Time">
                                                    <label class="form-check-label" for="full_time">Full Time</label>
                                                </div>
                                                <div class="form-check form-check-inline ml-3">
                                                    <input class="form-check-input" type="checkbox" name="shift_pattern[]" id="part_time" value="Part Time">
                                                    <label class="form-check-label" for="part_time">Part Time</label>
                                                </div>
                                                <div class="form-check form-check-inline ml-3">
                                                    <input class="form-check-input" type="checkbox" name="shift_pattern[]" id="twenty_four_hours" value="24 hours">
                                                    <label class="form-check-label" for="twenty_four_hours">24 Hours</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="shift_pattern[]" id="day_night" value="Day/Night">
                                                    <label class="form-check-label" for="day_night">Day/Night</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-form-label col-sm-3"><strong style="font-size:18px">7.</strong> Visa Status</label>
                                            <div class="col-sm-9 d-flex align-items-center">
                                                <div class="d-flex">
                                                    <div class="form-check form-check-inline">
                                                        <input type="radio" name="visa_status" id="british" class="form-check-input" value="British">
                                                        <label class="form-check-label" for="british">British</label>
                                                    </div>
                                                    <div class="form-check form-check-inline ml-3">
                                                        <input type="radio" name="visa_status" id="required_sponsorship" class="form-check-input" value="Required Sponsorship">
                                                        <label class="form-check-label" for="required_sponsorship">Required Sponsorship</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <div class="col-6 my-2">
                                                <div class="form-check form-switch">
                                                     <input class="form-check-input" type="checkbox" role="switch"
                                                        name="nursing_home"
                                                        id="nursing_home_checkbox"
                                                        ${applicantNursingHomeExp == 1 ? 'checked ' : ''}
                                                        value="${applicantNursingHomeExp}">
                                                    <label class="form-check-label" for="nursing_home_checkbox">Nursing Home</label>
                                                </div>
                                            </div>
                                            <div class="col-6 my-2">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" name="alternate_weekend" id="alternate_weekend_checkbox">
                                                    <label class="form-check-label" for="alternate_weekend_checkbox">Alternate Weekend</label>
                                                </div>
                                            </div>
                                            <div class="col-6 my-2">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" name="interview_availability" id="interview_availability_checkbox">
                                                    <label class="form-check-label" for="interview_availability_checkbox">Interview Availability</label>
                                                </div>
                                            </div>
                                            <div class="col-6 my-2">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" name="no_job" id="no_job_checkbox" onclick="handleCheckboxClick(\'no_job_checkbox\', \'hangup_call_checkbox\')">
                                                    <label class="form-check-label" for="no_job_checkbox">No Job</label>
                                                </div>
                                            </div>
                                            <div class="col-6 my-2">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="hangup_call" role="switch" id="hangup_call_checkbox" onclick="handleCheckboxClick(\'hangup_call_checkbox\', \'no_job_checkbox\')">
                                                    <label class="form-check-label" for="hangup_call_checkbox">Call Hung up/Not Interested</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="col-form-label col-sm-12" for="${noteId}">Other Details <span class="text-danger">*</span></label>
                                            <div class="col-sm-12">
                                                <textarea name="details" id="${noteId}" class="form-control" rows="4" placeholder="Type here ..." required></textarea>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Reset form on modal show
            $(`#${modalId}`).on('show.bs.modal', function() {
                $(`#${formId}`)[0].reset();
                $(`#${noteId}`).removeClass('is-invalid is-valid').next('.invalid-feedback').remove();
            });

            // Show the modal
            $(`#${modalId}`).modal('show');

            // Handle save button click
            $(`#${saveBtnId}`).off('click').on('click', function() {
                const notes = $(`#${noteId}`).val();

                if (!notes) {
                    $(`#${noteId}`).addClass('is-invalid');
                    if ($(`#${noteId}`).next('.invalid-feedback').length === 0) {
                        $(`#${noteId}`).after('<div class="invalid-feedback">Please enter note details.</div>');
                    }

                    $(`#${noteId}`).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });
                    return;
                }

                $(`#${noteId}`).removeClass('is-invalid').addClass('is-valid');
                $(`#${noteId}`).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                const formData = $(`#${formId}`).serialize() + '&_token={{ csrf_token() }}';

                $.ajax({
                    url: '{{ route('sendCVtoQuality') }}',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        console.log(response);
                        toastr.success(response.message);

                        $(`#${modalId}`).modal('hide');
                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        toastr.error(response.message);
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        function sendCVModal(applicantID, saleID) {
            const modalId = `sendCVModal-${applicantID}-${saleID}`;
            const formId = `sendCV_form_${applicantID}_${saleID}`;
            const saveBtnId = `saveCVBtn_${applicantID}_${saleID}`;
            const backBtnId = `backCVBtn_${applicantID}_${saleID}`;
            const csrfToken = '{{ csrf_token() }}';
            const applicantFields = [
                ['visa_status', '8. Visa Status', 'Enter the applicant’s visa status'],
                ['dbs_convictions', '9. Convictions on DBS', 'Record the applicant’s statement'],
                ['expected_hourly_rate', '10. Expected Hourly Rate', 'Enter the expected hourly rate'],
                ['holidays_booked', '11. Holidays Booked', 'Record any holidays already booked'],
                ['reasons_for_job_change', '12. Reasons for Job Change', 'Record the applicant’s reasons'],
                ['nursing_experience_years', '13. Years of Experience as a Nurse', 'Enter years of experience', '13. Years of Experience as a Carer, care assistant or support worker', '13. Years of Experience as a Nursery'],
                ['nursing_home_experience', '14. Years of Experience as a Nurse in Nursing Home', 'Record the applicant’s nursing home experience', '14. Years of Experience as a Carer, care assistant or support worker', '14. Years of Experience as a nursery day care center'],
                ['manager_rota', '15. Working According to the Manager’s Rota', 'Record the applicant’s response'],
                ['current_dbs_status', '16. Current DBS Status', 'Enter the current DBS status'],
                ['expected_salary', '17. Expected Salary', 'Enter the expected salary'],
                ['qualification', '18. Qualification', 'Enter the qualification']
            ];
            const fieldId = function(name) {
                return `${name}_${applicantID}_${saleID}`;
            };
            const textArea = function(name, label, placeholder, nurseOnly, nonNurseLabel, nurseryLabel) {
                const id = fieldId(name);
                const nurseOnlyAttribute = nurseOnly ? ' data-nurse-only' : '';
                const experienceLabelAttributes = nonNurseLabel ?
                    ` data-nurse-label="${label}" data-non-nurse-label="${nonNurseLabel}" data-nursery-label="${nurseryLabel}"` : '';
                return `<div class="col-12 col-md-6" data-send-cv-question${nurseOnlyAttribute}>
                    <label class="form-label" for="${id}"${experienceLabelAttributes}>${label}</label>
                    <textarea name="${name}" id="${id}" class="form-control" rows="2" placeholder="${placeholder}"></textarea>
                </div>`;
            };
            const checkboxes = function(name, options) {
                return options.map(function(option) {
                    const id = fieldId(`${name}_${option.toLowerCase().replace(/[^a-z0-9]+/g, '_')}`);
                    return `<div class="form-check">
                        <input class="form-check-input" type="checkbox" name="${name}[]" id="${id}" value="${option}">
                        <label class="form-check-label" for="${id}">${option}</label>
                    </div>`;
                }).join('');
            };

            if ($(`#${modalId}`).length === 0) {
                const clinicalSkills = [
                    'Wound Care', 'Catheterization', 'Venepuncture', 'PEG Feeding',
                    'Tracheostomy Care', 'Suctioning', 'Dementia Care', 'Palliative Care'
                ];
                const clinicalSkillMarkup = clinicalSkills.map(function(skill) {
                    const id = fieldId(`clinical_${skill.toLowerCase().replace(/[^a-z0-9]+/g, '_')}`);
                    return `<div class="col-6 col-md-3"><div class="form-check">
                        <input class="form-check-input" type="checkbox" name="clinical_skills[]" id="${id}" value="${skill}">
                        <label class="form-check-label" for="${id}">${skill}</label>
                    </div></div>`;
                }).join('');
                const applicantFieldMarkup = applicantFields.map(function(field) {
                    return textArea(field[0], field[1], field[2], false, field[3], field[4]);
                }).join('');

                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Fill Out Form To Send CV</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form class="form-horizontal" id="${formId}">
                                        <input type="hidden" name="applicant_id" value="${applicantID}">
                                        <input type="hidden" name="sale_id" value="${saleID}">
                                        <input type="hidden" name="_token" value="${csrfToken}">
                                        <div data-send-cv-page="2" class="d-none">
                                            <div class="card border-0 shadow-sm mb-4">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-1">Applicant Information</h6>
                                                    <small class="text-muted">Add the applicant’s statement for each question.</small>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row g-3">
                                                      <div class="col-12">
                                                          <label class="form-label d-block">Category</label>
                                                          <div class="form-check form-check-inline">
                                                              <input class="form-check-input" type="radio" name="category" id="${fieldId('category_nurse')}" value="Nurse" checked>
                                                              <label class="form-check-label" for="${fieldId('category_nurse')}">Nurse</label>
                                                          </div>
                                                          <div class="form-check form-check-inline">
                                                              <input class="form-check-input" type="radio" name="category" id="${fieldId('category_non_nurse')}" value="Non Nurse">
                                                              <label class="form-check-label" for="${fieldId('category_non_nurse')}">Non Nurse</label>
                                                          </div>
                                                          <div class="form-check form-check-inline">
                                                              <input class="form-check-input" type="radio" name="category" id="${fieldId('category_nursery')}" value="Nursery">
                                                              <label class="form-check-label" for="${fieldId('category_nursery')}">Nursery</label>
                                                          </div>
                                                          <div class="text-danger small d-none" data-category-error>Please select a category.</div>
                                                      </div>
                                                      ${textArea('nmc_pin_status', '5. NMC PIN Status', 'Enter the applicant’s NMC PIN status', true)}
                                                      ${textArea('notice_period', '6. Notice Period', 'Enter the applicant’s notice period')}
                                                      <div class="col-12" data-send-cv-question data-nurse-only>
                                                          <label class="form-label d-block">7. Clinical Skills</label>
                                                          <div class="row g-2">${clinicalSkillMarkup}</div>
                                                      </div>
                                                        ${applicantFieldMarkup}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div data-send-cv-page="1">
                                            <div class="card border-0 shadow-sm mb-4">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-1">Additional Information</h6>
                                                    <small class="text-muted">Add transport, shift, and availability details.</small>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row g-3">
                                                        <div class="col-12">
                                                            <label class="form-label d-block">1. Transport Type</label>
                                                            <div class="send-cv-questionnaire-options">${checkboxes('transport_type', ['By Walk', 'Cycle', 'Car', 'Public Transport'])}</div>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label d-block">2. Shift Pattern</label>
                                                            <div class="send-cv-questionnaire-options">${checkboxes('shift_pattern', ['Day', 'Night', 'Full Time', 'Part Time', '24 hours', 'Day/Night'])}</div>
                                                        </div>
                                                        <div class="col-12 col-sm-6">
                                                            <label class="form-label" for="${fieldId('interview_option_1')}">3. Interview Availability Same Week</label>
                                                            <input class="form-control" type="datetime-local" name="interview_option_1" id="${fieldId('interview_option_1')}">
                                                        </div>
                                                        <div class="col-12 col-sm-6">
                                                            <label class="form-label" for="${fieldId('interview_option_2')}">4. Interview Availability Next Week</label>
                                                            <input class="form-control" type="datetime-local" name="interview_option_2" id="${fieldId('interview_option_2')}">
                                                        </div>
                                                        <div class="col-12 col-sm-6 my-2">
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" type="checkbox" name="hangup_call" role="switch" id="${fieldId('hangup_call')}" value="1">
                                                                <label class="form-check-label" for="${fieldId('hangup_call')}">Call Hung up/Not Interested</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-12 col-sm-6 my-2">
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" type="checkbox" name="no_job" role="switch" id="${fieldId('no_job')}" value="1">
                                                                <label class="form-check-label" for="${fieldId('no_job')}">No Job</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label" for="${fieldId('details')}">Other Details <span class="text-danger" data-details-required-indicator>*</span></label>
                                                            <textarea name="details" id="${fieldId('details')}" class="form-control" rows="4" placeholder="Type here ..." required></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-outline-secondary d-none" id="${backBtnId}">Back</button>
                                    <button type="button" class="btn btn-success" id="${saveBtnId}">Next</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            const $modal = $(`#${modalId}`);
            const $form = $(`#${formId}`);
            const $pageOne = $form.find('[data-send-cv-page="1"]');
            const $pageTwo = $form.find('[data-send-cv-page="2"]');
            const $saveButton = $(`#${saveBtnId}`);
            const $backButton = $(`#${backBtnId}`);
            const $hangup = $form.find('[name="hangup_call"]');
            const $noJob = $form.find('[name="no_job"]');
            const $details = $form.find('[name="details"]');
            let currentPage = 1;
            const updateNurseFields = function() {
                const category = $form.find('[name="category"]:checked').val();
                const isNonNurse = category === 'Non Nurse';
                const isNursery = category === 'Nursery';
                const hideNurseFields = category !== 'Nurse';
                $pageTwo.find('[data-send-cv-question] .form-label').each(function() {
                    const $label = $(this);
                    if ($label.data('original-label') === undefined) {
                        $label.data('original-label', $label.text());
                    }
                    $label.text($label.data('original-label'));
                });
                $form.find('[data-nurse-label]').each(function() {
                    const $label = $(this);
                    $label.text(isNursery
                        ? $label.data('nursery-label')
                        : isNonNurse
                            ? $label.data('non-nurse-label')
                            : $label.data('nurse-label'));
                });
                $pageTwo.find('[data-nurse-only]').each(function() {
                    const $fields = $(this).find(':input');
                    $(this).toggleClass('d-none', hideNurseFields);
                    $fields.prop('disabled', hideNurseFields);
                    if (hideNurseFields) {
                        $fields.filter(':checkbox').prop('checked', false);
                        $fields.filter('textarea').val('');
                    }
                });
                let questionNumber = 5;
                $pageTwo.find('[data-send-cv-question]').each(function() {
                    const $question = $(this);
                    if ($question.hasClass('d-none')) {
                        return;
                    }
                    const $label = $question.find('.form-label').first();
                    $label.text($label.text().replace(/^\d+\.\s*/, `${questionNumber++}. `));
                });
            };
            const isShortcut = function() {
                return $hangup.is(':checked') || $noJob.is(':checked');
            };
            const showPageOne = function() {
                currentPage = 1;
                $pageOne.removeClass('d-none');
                $pageTwo.addClass('d-none');
                $backButton.addClass('d-none');
                $saveButton.text(isShortcut() ? 'Submit' : 'Next');
            };
            const showPageTwo = function() {
                currentPage = 2;
                $pageOne.addClass('d-none');
                $pageTwo.removeClass('d-none');
                $backButton.removeClass('d-none');
                $saveButton.text('Submit');
            };
            const updateShortcut = function() {
                const shortcut = isShortcut();
                $details.prop('required', !shortcut);
                $form.find('[data-details-required-indicator]').toggleClass('d-none', shortcut);
                if (shortcut) {
                    showPageOne();
                    $pageTwo.find('.is-invalid, .invalid-feedback').remove();
                } else if (currentPage === 2) {
                    $saveButton.text('Submit');
                } else {
                    $saveButton.text('Next');
                }
            };

            $modal.off('show.bs.modal.sendCv').on('show.bs.modal.sendCv', function() {
                $form[0].reset();
                $form.find('.is-invalid, .is-valid').removeClass('is-invalid is-valid');
                $form.find('.invalid-feedback').remove();
                showPageOne();
                updateNurseFields();
                updateShortcut();
            });
            $backButton.off('click').on('click', showPageOne);
            $form.off('change', '[name="category"]').on('change', '[name="category"]', function() {
                updateNurseFields();
                $form.find('[data-category-error]').addClass('d-none');
            });
            $form.off('change', '[name="hangup_call"], [name="no_job"]').on(
                'change',
                '[name="hangup_call"], [name="no_job"]',
                function() {
                    if (this.checked) {
                        $form.find('[name="hangup_call"], [name="no_job"]').not(this).prop('checked', false);
                    }
                    updateShortcut();
                }
            );
            $(document).off('click', `#${saveBtnId}`).on('click', `#${saveBtnId}`, function() {
                const shortcut = isShortcut();
                if (!shortcut && currentPage === 1) {
                    showPageTwo();
                    return;
                }

                $form.find('.is-invalid').removeClass('is-invalid');
                $form.find('.invalid-feedback').remove();
                let valid = true;
                if (!shortcut && !$form.find('[name="category"]:checked').length) {
                    $form.find('[data-category-error]').removeClass('d-none');
                    valid = false;
                }
                if (!shortcut && !$details.val()) {
                    $details.addClass('is-invalid').after('<div class="invalid-feedback">Please enter note details.</div>');
                    valid = false;
                }
                if (!shortcut) {
                    ['interview_option_1', 'interview_option_2'].forEach(function(name) {
                        const $field = $form.find(`[name="${name}"]`);
                        if (!$field.val()) {
                            $field.addClass('is-invalid').after(
                                `<div class="invalid-feedback">Please select ${name === 'interview_option_1' ? 'Interview Availability Same Week.' : 'Interview Availability Next Week.'}</div>`
                            );
                            valid = false;
                        }
                    });
                    if ($form.find('[name="transport_type[]"]:checked').length === 0) {
                        $form.find('[name="transport_type[]"]').first().closest('.col-12').append(
                            '<div class="invalid-feedback d-block">Please select at least one transport type.</div>'
                        );
                        valid = false;
                    }
                    const $visaStatus = $form.find('[name="visa_status"]');
                    if (!$visaStatus.val().trim()) {
                        $visaStatus.addClass('is-invalid').after('<div class="invalid-feedback">Please enter visa status.</div>');
                        valid = false;
                    }
                }
                if (!valid) {
                    return;
                }

                const $button = $(this);
                const originalText = $button.html();
                $button.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...'
                );
                $.ajax({
                    url: shortcut ? '{{ route('saveSendCvHangupNote') }}' : '{{ route('sendCVtoQuality') }}',
                    type: 'POST',
                    data: $form.serialize(),
                    success: function(response) {
                        if (response.success) {
                            toastr.success(response.message || 'CV sent successfully!');
                            if (response.download_url) {
                                const downloadLink = document.createElement('a');
                                downloadLink.href = response.download_url;
                                downloadLink.download = '';
                                document.body.appendChild(downloadLink);
                                downloadLink.click();
                                downloadLink.remove();
                            }
                            $modal.modal('hide');
                            $('#sales_table').DataTable().ajax.reload();
                        } else {
                            toastr.error(response.message || 'Failed to send CV');
                        }
                    },
                    error: function(xhr) {
                        const message = xhr.responseJSON && xhr.responseJSON.message ?
                            xhr.responseJSON.message : 'An error occurred while submitting the form.';
                        toastr.error(message);
                    },
                    complete: function() {
                        $button.prop('disabled', false).html(originalText);
                    }
                });
            });
            $modal.modal('show');
        }

        // Function to mark no nursing home modal
        function markNoNursingHomeModal(applicantID) {
            const modalId = `markNoNursingHomeModal-${applicantID}`;
            const formId = `markNoNursingHomeForm-${applicantID}`;
            const textareaId = `detailsTextareaMarkNoNursingHome-${applicantID}`;
            const saveBtnId = `saveMarkNoNursingHomeButton-${applicantID}`;

            // Add modal to DOM if it doesn't exist
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Mark As No Nursing Home</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="${formId}">
                                        <div class="mb-3">
                                            <label for="${textareaId}" class="form-label">Details</label>
                                            <textarea class="form-control" id="${textareaId}" rows="4" required></textarea>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Reset form and validation states on open
            $(`#${formId}`)[0].reset();
            $(`#${textareaId}`).removeClass('is-invalid is-valid').next('.invalid-feedback').remove();

            // Show modal
            $(`#${modalId}`).modal('show');

            // Save button click handler
            $(`#${saveBtnId}`).off('click').on('click', function() {
                const notes = $(`#${textareaId}`).val();

                if (!notes) {
                    $(`#${textareaId}`).addClass('is-invalid');
                    if ($(`#${textareaId}`).next('.invalid-feedback').length === 0) {
                        $(`#${textareaId}`).after('<div class="invalid-feedback">Please provide details.</div>');
                    }

                    $(`#${textareaId}`).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Clean validation
                $(`#${textareaId}`).removeClass('is-invalid').addClass('is-valid');
                $(`#${textareaId}`).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                // Send via AJAX
                $.ajax({
                    url: '{{ route('markApplicantNoNursingHome') }}',
                    type: 'POST',
                    data: {
                        applicant_id: applicantID,
                        details: notes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Marked as no nursing home successfully!');
                        $(`#${modalId}`).modal('hide');
                        $(`#${formId}`)[0].reset();
                        $(`#${textareaId}`).removeClass('is-valid').next('.invalid-feedback').remove();
                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while saving.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        // Function to mark no nursing home modal
        function markApplicantCallbackModal(applicantID, saleID) {
            const modalId = `markApplicantCallbackModal-${applicantID}-${saleID}`;
            const formId = `markCallbackForm-${applicantID}-${saleID}`;
            const textareaId = `detailsTextareaMarkCallback-${applicantID}-${saleID}`;
            const saveBtnId = `saveMarkCallbackButton-${applicantID}-${saleID}`;

            // Append the modal if it doesn't exist already
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Mark As Callback</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="${formId}">
                                        <div class="mb-3">
                                            <label for="${textareaId}" class="form-label">Details</label>
                                            <textarea class="form-control" id="${textareaId}" rows="4" required></textarea>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Reset the form and clear validation/errors
            $(`#${formId}`)[0].reset();
            $(`#${textareaId}`).removeClass('is-invalid is-valid').next('.invalid-feedback').remove();

            // Show modal
            $(`#${modalId}`).modal('show');

            // Attach save button handler (remove old if re-attached)
            $(`#${saveBtnId}`).off('click').on('click', function() {
                const notes = $(`#${textareaId}`).val();

                if (!notes) {
                    $(`#${textareaId}`).addClass('is-invalid');
                    if ($(`#${textareaId}`).next('.invalid-feedback').length === 0) {
                        $(`#${textareaId}`).after('<div class="invalid-feedback">Please provide details.</div>');
                    }

                    // Real-time validation
                    $(`#${textareaId}`).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Remove validation
                $(`#${textareaId}`).removeClass('is-invalid').addClass('is-valid');
                $(`#${textareaId}`).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                // AJAX request
                $.ajax({
                    url: '{{ route('markApplicantCallback') }}',
                    type: 'POST',
                    data: {
                        applicant_id: applicantID,
                        sale_id: saleID,
                        details: notes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Mark callback saved successfully!');
                        $(`#${modalId}`).modal('hide');
                        $(`#${formId}`)[0].reset();
                        $(`#${textareaId}`).removeClass('is-valid').next('.invalid-feedback').remove();
                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while saving notes.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }
    </script>
@endsection
@endsection
