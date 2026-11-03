@extends('layouts.vertical', ['title' => 'Units List', 'subTitle' => 'Home'])
@section('css')
    <style>
        /* Blacklisted rows: table-danger background plus a red bar on the left edge. */
        tr.blacklisted-row > td:first-child {
            box-shadow: inset 4px 0 0 var(--bs-danger),
                inset 0 0 0 9999px var(--bs-table-bg-state, var(--bs-table-bg-type, var(--bs-table-accent-bg)));
        }
    </style>
@endsection
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
                        <div class="col-lg-3">
                            <div class="text-md-start mt-3 pt-1">
                                <div class="input-group">
                                    <div class="position-relative flex-grow-1" style="display: flex;">
                                        <input type="text" id="customSearchInput" class="form-control w-100"
                                            placeholder="Search ...">
                                        <button class="d-none" id="customClearBtn" type="button" title="Clear"><i
                                                class="ri-close-line"></i></button>
                                    </div>
                                    <button class="btn btn-primary" id="customSearchBtn" type="button"><i
                                            class="ri-search-line"></i> Search</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-9">
                            <div class="text-md-end mt-3">
                                <!-- head office Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton6" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterOffice">All Head
                                            Office</span>
                                    </button>

                                    <div class="dropdown-menu filter-dropdowns" aria-labelledby="dropdownMenuButton6">
                                        <!-- Search input -->
                                        <input type="text" class="form-control mb-2" id="officeSearchInput"
                                            placeholder="Search office...">

                                        <!-- Select/Deselect All -->
                                        <div class="d-flex justify-content-end px-1 mb-1" id="officeToggleContainer">
                                            <a href="#" class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".office-filter" data-exclude="[data-office-id='']">Select
                                                All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".office-filter" data-exclude="[data-office-id='']"
                                                style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="officesList">
                                            <div class="form-check">
                                                <input class="form-check-input office-filter" type="checkbox" value=""
                                                    id="all-offices" data-office-id="">
                                                <label class="form-check-label" for="all-offices">All Head Offices</label>
                                            </div>

                                            @foreach ($offices as $office)
                                                <div class="form-check">
                                                    <input class="form-check-input office-filter" type="checkbox"
                                                        value="{{ $office->id }}" id="office_{{ $office->id }}"
                                                        data-office-id="{{ $office->id }}">
                                                    <label class="form-check-label"
                                                        for="office_{{ $office->id }}">{{ ucwords($office->office_name) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                @canany(['unit-filters'])
                                    <!-- Button Dropdown -->
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-filter-line me-1"></i> <span id="showUnitStatusFilter">All</span>
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton1">
                                            <a class="dropdown-item unit-status-filter" href="#">All</a>
                                            <a class="dropdown-item unit-status-filter" href="#">Active</a>
                                            <a class="dropdown-item unit-status-filter" href="#">Inactive</a>
                                            <a class="dropdown-item unit-status-filter" href="#">Blacklisted</a>

                                        </div>
                                    </div>
                                @endcanany
                                <!-- Button Dropdown -->
                                @canany(['unit-export', 'unit-export-all', 'unit-export-emails'])
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-download-line me-1"></i> <span class="btn-text">Export</span>
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton1">
                                            @canany(['unit-export-all'])
                                                <a class="dropdown-item export-btn"
                                                    href="{{ route('unitsExport', ['type' => 'all']) }}">Export All Data</a>
                                            @endcanany
                                            @canany(['unit-export-emails'])
                                                <a class="dropdown-item export-btn"
                                                    href="{{ route('unitsExport', ['type' => 'emails']) }}">Export Emails</a>
                                            @endcanany
                                            <a class="dropdown-item export-btn"
                                                href="{{ route('unitsExport', ['type' => 'noLatLong']) }}">Export no LAT &
                                                LONG</a>
                                        </div>
                                    </div>
                                @endcanany
                                @canany(['unit-import'])
                                    <button type="button" class="btn btn-outline-primary me-1 my-1" data-bs-toggle="modal"
                                        data-bs-target="#csvImportModal" title="Import CSV">
                                        <i class="ri-upload-line"></i>
                                    </button>
                                @endcanany
                                @canany(['unit-create'])
                                    <a href="{{ route('units.create') }}"><button type="button"
                                            class="btn btn-success ml-1 my-1"><i class="ri-add-line"></i> Create
                                            Unit</button></a>
                                @endcanany
                            </div>
                        </div><!-- end col-->
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
                        <table id="units_table" class="table align-middle mb-3">
                            <thead class="bg-light-subtle">
                                <tr>
                                    <th>#</th>
                                    <th>Created Date</th>
                                    <th>Updated Date</th>
                                    <th>Head Office</th>
                                    <th>Unit Name</th>
                                    <th width="8%">PostCode</th>
                                    <th>Contact Email</th>
                                    <th>Contact Phone</th>
                                    <th>Contact Landline</th>
                                    @canany(['unit-view-note', 'unit-add-note'])
                                        <th width="10%">Notes</th>
                                    @endcanany
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

    <!-- Import CSV Modal -->
    <div class="modal fade" id="csvImportModal" tabindex="-1" aria-labelledby="csvImportLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form id="csvImportForm" enctype="multipart/form-data">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="csvImportLabel">Import CSV</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="csvFile" class="form-label">Choose CSV File</label>
                            <input type="file" class="form-control" id="csvFile" name="csv_file" accept=".csv"
                                required>
                        </div>
                        <div class="progress" style="height: 20px;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                                role="progressbar" style="width: 0%">0%</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="unitBlacklistModal" tabindex="-1" aria-labelledby="unitBlacklistModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <form id="unitBlacklistForm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="unitBlacklistModalLabel">Update Blacklist</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="unitBlacklistAlert" class="alert alert-warning d-flex align-items-start gap-2"
                            role="alert">
                            <iconify-icon id="unitBlacklistAlertIcon" icon="solar:danger-triangle-bold"
                                class="fs-20 flex-shrink-0"></iconify-icon>
                            <div>
                                <span id="unitBlacklistBadge" class="badge bg-warning text-dark mb-2">Blacklist</span>
                                <div id="unitBlacklistModalMessage"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="unitBlacklistNote" class="form-label">Message / reason</label>
                            <textarea id="unitBlacklistNote" name="blacklist_note" class="form-control" rows="4" maxlength="2000"
                                placeholder="Enter a message to record for this blacklist change" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning" id="unitBlacklistSubmit">Add to Blacklist</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="unitDocumentsModal" tabindex="-1" aria-labelledby="unitDocumentsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unitDocumentsModalLabel">Unit Attachments</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"></div>
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
        var currentFilter = '';
        var currentOfficeFilters = [];
        var pendingUnitBlacklist = null;

        $(document).ready(function() {
            const hasViewNotePermission = @json(auth()->user()->can('unit-view-note'));
            const hasAddNotePermission = @json(auth()->user()->can('unit-add-note'));

            let columns = [{
                    data: 'DT_RowIndex',
                    name: 'DT_RowIndex',
                    orderable: false,
                    searchable: false
                },
                {
                    data: 'created_at',
                    name: 'units.created_at'
                },
                {
                    data: 'updated_at',
                    name: 'units.updated_at'
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
                    data: 'unit_postcode',
                    name: 'units.unit_postcode'
                },
                {
                    data: 'contact_email',
                    name: 'contact_email',
                    orderable: false,

                },
                {
                    data: 'contact_phone',
                    name: 'contact_phone',
                    orderable: false,
                },
                {
                    data: 'contact_landline',
                    name: 'contact_landline',
                    orderable: false,
                },
            ];

            if (hasViewNotePermission || hasAddNotePermission) {
                columns.push({
                    data: 'unit_notes',
                    name: 'units.unit_notes',
                    orderable: false
                });
            }
            columns.push({
                data: 'status',
                name: 'units.status',
                orderable: false
            }, {
                data: 'action',
                name: 'action',
                orderable: false
            });

            let columnDefs = [];

            // Dynamically assign center alignment for columns starting from resume/applicant_experience
            const centerAlignedIndices = [];
            for (let i = 0; i < columns.length; i++) {
                const key = columns[i].data;
                if (['status', 'action'].includes(key)) {
                    centerAlignedIndices.push(i);
                }
            }

            centerAlignedIndices.forEach(idx => {
                columnDefs.push({
                    targets: idx,
                    createdCell: function(td) {
                        $(td).css('text-align', 'center');
                    }
                });
            });

            // Create loader row
            const loadingRow = `<tr><td colspan="100%" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </td></tr>`;

            // Function to show loader
            function showLoader() {
                $('#units_table tbody').empty().append(loadingRow);
            }

            // Initialize DataTable with server-side processing
            var table = $('#units_table').DataTable({
                processing: false, // Disable default processing state
                serverSide: true, // Enables server-side processing
                ajax: {
                    url: @json(route('getUnits')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        // Add the current filter to the request parameters
                        d.status_filter = currentFilter; // Send the current filter value as a parameter
                        d.office_filter =
                            currentOfficeFilters; // Send the current filter value as a parameter
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
                columns: columns,
                columnDefs: columnDefs,
                rowId: function(data) {
                    return 'row_' + data
                        .id; // Assign a unique ID to each row using the 'id' field from the data
                },
                dom: 'lrtip', // Change the order to 'filter' (f), 'length' (l), 'table' (r), 'pagination' (p), and 'information' (i)
                drawCallback: function(settings) {
                    const api = this.api();
                    const pagination = $(api.table().container()).find('.dataTables_paginate');
                    pagination.empty();

                    const pageInfo = api.page.info();
                    const currentPage = pageInfo.page + 1;
                    const totalPages = pageInfo.pages;

                    if (pageInfo.recordsTotal === 0) {
                        $('#units_table tbody').html(
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

            // Search logic helper
            function handleCustomSearch() {
                let searchValue = $('#customSearchInput').val().trim();
                table.search(searchValue).draw();
            }

            // Custom Search Button Event
            $('#customSearchBtn').on('click', function() {
                handleCustomSearch();
            });

            // Custom Search Input Enter Key Event
            $('#customSearchInput').on('keypress', function(e) {
                if (e.which == 13) { // Enter key
                    handleCustomSearch();
                }
            });

            // Show/Hide Clear button
            $('#customSearchInput').on('keyup change', function() {
                if ($(this).val().trim() !== '') {
                    $('#customClearBtn').removeClass('d-none');
                } else {
                    $('#customClearBtn').addClass('d-none');
                }
            });

            // Clear Button Event
            $('#customClearBtn').on('click', function() {
                $('#customSearchInput').val('');
                $(this).addClass('d-none');
                table.search('').draw();
            });

            /*** Office Filter Handler ***/
            $('.office-filter').on('change', function() {
                const id = $(this).data('office-id');

                // Handle "All Titles"
                if (id === '' || id === undefined) {
                    currentOfficeFilters = [];
                    $('.office-filter').not(this).prop('checked', false);
                } else {
                    // Remove or add to array
                    if (this.checked) {
                        currentOfficeFilters.push(id);
                        // Uncheck "All Titles"
                        $('.office-filter[data-office-id=""]').prop('checked', false);
                    } else {
                        currentOfficeFilters = currentOfficeFilters.filter(x => x !== id);
                    }
                }

                // Update dropdown display text and toggle visibility
                const total = $('.office-filter').not('[data-office-id=""]').length;
                const checked = $('.office-filter:checked').not('[data-office-id=""]').length;

                $('#showFilterOffice').text(checked > 0 ? `Selected Offices (${checked})` :
                    'All Head Offices');

                const container = $('#officeToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

                // Trigger DataTable reload with the selected filters
                table.ajax.reload();
            });

            $('.unit-status-filter').on('click', function(e) {
                e.preventDefault();
                currentFilter = $(this).text().trim().toLowerCase() === 'all'
                    ? ''
                    : $(this).text().trim().toLowerCase();
                $('#showUnitStatusFilter').text(
                    !currentFilter ? 'All' : currentFilter.charAt(0).toUpperCase() +
                    currentFilter.slice(1)
                );
                table.ajax.reload();
            });

            /*** Dropdown Select All Action ***/
            $(document).on('click', '.filter-select-all', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const filterClass = $(this).data('target');
                const excludeAttr = $(this).data('exclude');

                $(filterClass + excludeAttr).prop('checked', false); // uncheck "All X"
                $(filterClass).not(excludeAttr).prop('checked', true).trigger('change');
            });

            /*** Dropdown Deselect All Action ***/
            $(document).on('click', '.filter-deselect-all', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const filterClass = $(this).data('target');
                const excludeAttr = $(this).data('exclude');

                $(filterClass).not(excludeAttr).prop('checked', false).trigger('change');
            });

            // Keep dropdown open when clicking inside its content area
            $(document).on('click', '.filter-dropdowns', function(e) {
                e.stopPropagation();
            });

            $('#unitBlacklistForm').on('submit', function(event) {
                event.preventDefault();
                if (!pendingUnitBlacklist) {
                    return;
                }

                const {
                    unitId,
                    isBlacklisted
                } = pendingUnitBlacklist;
                const blacklistNote = $('#unitBlacklistNote').val().trim();
                const submitButton = $('#unitBlacklistSubmit');
                submitButton.prop('disabled', true);

                const url = '{{ route('units.toggle-blacklist', ['id' => '__ID__']) }}'.replace('__ID__',
                    unitId);
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        is_blacklist: isBlacklisted,
                        blacklist_note: blacklistNote,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        $('#unitBlacklistModal').modal('hide');
                        toastr.success(response.message);
                        $('#units_table').DataTable().ajax.reload(null, false);
                    },
                    error: function(xhr) {
                        toastr.error(xhr.responseJSON?.message ||
                            'Unable to update the unit blacklist status.');
                    },
                    complete: function() {
                        submitButton.prop('disabled', false);
                    }
                });
            });

            $('#unitBlacklistModal').on('hidden.bs.modal', function() {
                $('#unitBlacklistForm')[0].reset();
                pendingUnitBlacklist = null;
            });
        });

        document.getElementById('officeSearchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const checkboxes = document.querySelectorAll('#officesList .form-check');

            checkboxes.forEach(function(item) {
                const label = item.querySelector('label').innerText.toLowerCase();
                item.style.display = label.includes(searchValue) ? '' : 'none';
            });
        });

        function toggleUnitBlacklist(unitId, isBlacklisted) {
            const addingToBlacklist = Number(isBlacklisted) === 1;
            pendingUnitBlacklist = {
                unitId,
                isBlacklisted
            };
            $('#unitBlacklistModalLabel').text(addingToBlacklist ? 'Add to Blacklist' : 'Remove from Blacklist');
            $('#unitBlacklistAlert').attr(
                'class',
                'alert ' + (addingToBlacklist ? 'alert-warning' : 'alert-success') + ' d-flex align-items-start gap-2'
            );
            $('#unitBlacklistAlertIcon')
                .attr('icon', addingToBlacklist ? 'solar:danger-triangle-bold' : 'solar:check-circle-bold')
                .toggleClass('text-warning', addingToBlacklist)
                .toggleClass('text-success', !addingToBlacklist);
            $('#unitBlacklistBadge').attr(
                'class',
                'badge ' + (addingToBlacklist ? 'bg-warning text-dark' : 'bg-success') + ' mb-2'
            ).text(addingToBlacklist ? 'Adding to blacklist' : 'Removing from blacklist');
            $('#unitBlacklistModalMessage').text(
                addingToBlacklist ?
                'Blacklisting this unit will also automatically blacklist all its associated sales.' :
                'Removing this unit from the blacklist will not automatically remove its associated sales. You must remove each sale from the blacklist manually.'
            );
            $('#unitBlacklistSubmit')
                .text(addingToBlacklist ? 'Add to Blacklist' : 'Remove from Blacklist')
                .attr('class', 'btn ' + (addingToBlacklist ? 'btn-warning' : 'btn-success'));
            $('#unitBlacklistModal').modal('show');
        }

        function goToPage(totalPages) {
            const input = document.getElementById('goToPageInput');
            const errorMessage = document.getElementById('goToPageError');
            let page = parseInt(input.value);

            if (!isNaN(page) && page >= 1 && page <= totalPages) {
                $('#units_table').DataTable().page(page - 1).draw('page');
                input.classList.remove('is-invalid');
            } else {
                input.classList.add('is-invalid');
            }
        }

        // Function to move the page forward or backward
        function movePage(page) {
            var table = $('#units_table').DataTable();
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

        // Function to show the notes modal
        function showNotesModal(unitId, notes, unitName, unitPostcode) {
            const modalId = 'showNotesModal_' + unitId;

            // If modal doesn't exist, create it
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label">' +
                    '<div class="modal-dialog modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Unit Notes</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body modal-body-text-left">' +
                    '<div class="text-center my-3">' +
                    '<div class="spinner-border text-primary my-3" role="status"><span class="visually-hidden">Loading...</span></div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Show modal first with loader
            $('#' + modalId).modal('show');

            // Set timeout to simulate loading (remove if unnecessary)
            setTimeout(function() {
                const contentHtml =
                    'Unit Name: <strong>' + unitName + '</strong><br>' +
                    'Postcode: <strong>' + unitPostcode + '</strong><br>' +
                    'Notes Detail: <p>' + notes + '</p>';

                $('#' + modalId + ' .modal-body').html(contentHtml);
            }, 300); // you can adjust/remove this delay
        }

        // Function to show the notes modal
        function addShortNotesModal(unitID) {
            const modalId = 'shortNotesModal_' + unitID;
            const formId = 'shortNotesForm_' + unitID;
            const textareaId = 'detailsTextarea_' + unitID;
            const saveBtnId = 'saveShortNotesButton_' + unitID;

            // If the modal doesn't already exist, append it to the body
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label">' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Add Notes</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="' + formId + '">' +
                    '<div class="mb-3">' +
                    '<label for="' + textareaId + '" class="form-label">Details</label>' +
                    '<textarea class="form-control" id="' + textareaId + '" rows="4" required></textarea>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-primary" id="' + saveBtnId + '">Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Reset form on load
            $('#' + formId)[0].reset();
            $('#' + textareaId).removeClass('is-valid is-invalid');
            $('#' + textareaId).next('.invalid-feedback').remove();

            // Show the modal
            $('#' + modalId).modal('show');

            // Unbind any previous handlers and bind fresh click event
            $('#' + saveBtnId).off('click').on('click', function() {
                const notes = $('#' + textareaId).val();

                if (!notes) {
                    $('#' + textareaId).addClass('is-invalid');
                    if ($('#' + textareaId).next('.invalid-feedback').length === 0) {
                        $('#' + textareaId).after('<div class="invalid-feedback">Please provide details.</div>');
                    }

                    // Remove validation error when user starts typing
                    $('#' + textareaId).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Clean validation state
                $('#' + textareaId).removeClass('is-invalid').addClass('is-valid');
                $('#' + textareaId).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                // Send via AJAX
                $.ajax({
                    url: '{{ route('storeUnitShortNotes') }}',
                    type: 'POST',
                    data: {
                        unit_id: unitID,
                        details: notes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Notes saved successfully!');
                        $('#' + modalId).modal('hide');
                        $('#' + formId)[0].reset();
                        $('#' + textareaId).removeClass('is-valid');
                        $('#' + textareaId).next('.invalid-feedback').remove();
                        $('#units_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        alert('An error occurred while saving notes.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        function markUnitDisabledModal(unitID, status) {
            const modalId = 'markDisabledModal_' + unitID;
            const formId = 'markDisabledForm_' + unitID;
            const textareaId = 'markDisabledReason_' + unitID;
            const saveBtnId = 'saveMarkDisabledButton_' + unitID;
            const url = '{{ route('units.change-status', ['id' => '__ID__']) }}'.replace('__ID__', unitID);
            const statusText = status === 0 ? 'disabled' : 'enabled';

            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label">' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Mark Unit ' + statusText + '</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="' + formId + '">' +
                    '<div class="mb-3">' +
                    '<label for="' + textareaId + '" class="form-label">Reason for ' + statusText + '</label>' +
                    '<textarea class="form-control" id="' + textareaId + '" rows="4" required></textarea>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-success" id="' + saveBtnId + '">Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            $('#' + formId)[0].reset();
            $('#' + textareaId).removeClass('is-valid is-invalid');
            $('#' + textareaId).next('.invalid-feedback').remove();
            $('#' + modalId).modal('show');

            $('#' + saveBtnId).off('click').on('click', function() {
                const reason = $('#' + textareaId).val();

                if (!reason) {
                    $('#' + textareaId).addClass('is-invalid');
                    if ($('#' + textareaId).next('.invalid-feedback').length === 0) {
                        $('#' + textareaId).after('<div class="invalid-feedback">Please provide a reason.</div>');
                    }

                    $('#' + textareaId).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                $('#' + textareaId).removeClass('is-invalid').addClass('is-valid');
                $('#' + textareaId).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...'
                );

                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        reason: reason,
                        status: status,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success(response.message || 'Unit marked disabled successfully!');
                        $('#' + modalId).modal('hide');
                        $('#units_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        const message = xhr.responseJSON?.message ||
                            'An error occurred while marking the unit disabled.';
                        toastr.error(message);
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        function showUnitDocuments(unitId) {
            const modal = $('#unitDocumentsModal');
            const body = modal.find('.modal-body').empty();
            body.html('<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
            modal.modal('show');

            const documentsUrl = @json(url('units'));
            fetch(`${documentsUrl}/${encodeURIComponent(unitId)}/documents`, {
                headers: { 'Accept': 'application/json' }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Unable to load unit attachments.');
                    }
                    return response.json();
                })
                .then(data => {
                    body.empty();
                    if (!data.documents.length) {
                        body.text('No attachments saved.');
                        return;
                    }

                    const grid = $('<div class="row g-3"></div>');
                    data.documents.forEach(document => {
                        const column = $('<div class="col-6 col-md-4 col-lg-3"></div>');
                        const card = $('<div class="card h-100"></div>');
                        const preview = $('<a class="d-flex align-items-center justify-content-center bg-light border-bottom overflow-hidden"></a>')
                            .attr('href', document.url)
                            .attr('target', '_blank')
                            .attr('rel', 'noopener')
                            .css({ height: '150px', color: 'inherit' });

                        if (['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(document.mime_type)) {
                            preview.append($('<img class="w-100 h-100">')
                                .attr('src', document.url)
                                .attr('alt', document.name)
                                .css({ objectFit: 'contain' }));
                        } else if (document.mime_type === 'application/pdf') {
                            preview.append($('<iframe title="PDF preview" loading="lazy" class="w-100 h-100"></iframe>')
                                .attr('src', document.url)
                                .attr('tabindex', '-1')
                                .css({ border: 0, pointerEvents: 'none' }));
                        } else {
                            const extension = (document.name.split('.').pop() || 'file').toUpperCase();
                            preview.append($('<div class="text-center"></div>')
                                .append($('<iconify-icon icon="solar:document-text-bold-duotone" class="text-primary"></iconify-icon>')
                                    .css({ fontSize: '56px' }))
                                .append($('<div class="small text-muted"></div>').text(extension)));
                        }

                        const details = $('<div class="card-body p-2"></div>');
                        details.append($('<div class="small text-truncate" title=""></div>')
                            .attr('title', document.name)
                            .text(document.name));
                        if (document.size) {
                            details.append($('<div class="small text-muted"></div>')
                                .text(`${(document.size / 1024).toFixed(1)} KB`));
                        }
                        card.append(preview, details);
                        column.append(card);
                        grid.append(column);
                    });
                    body.append(grid);
                })
                .catch(error => {
                    console.error(error);
                    body.text('Unable to load attachments. Please try again.');
                });
        }

        function showDetailsModal(unitId, officeName, name, postcode, status) {
            const modalId = 'showDetailsModal_' + unitId;

            // Append modal HTML if it doesn't already exist
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label">' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Unit Details</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body modal-body-text-left">' +
                    '<div class="text-center py-3">' +
                    '<div class="spinner-border text-primary my-4 text-center" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Show the modal with loader first
            $('#' + modalId + ' .modal-body').html(
                '<div class="text-center py-3">' +
                '<div class="spinner-border text-primary" role="status">' +
                '<span class="visually-hidden">Loading...</span>' +
                '</div>' +
                '</div>'
            );
            $('#' + modalId).modal('show');

            // Render content after small delay to simulate loading
            setTimeout(function() {
                const htmlContent =
                    '<table class="table table-bordered">' +
                    '<tr><th>Unit ID</th><td>' + unitId + '</td></tr>' +
                    '<tr><th>Head Office Name</th><td>' + officeName + '</td></tr>' +
                    '<tr><th>Unit Name</th><td>' + name + '</td></tr>' +
                    '<tr><th>Postcode</th><td>' + postcode + '</td></tr>' +
                    '<tr><th>Status</th><td>' + status + '</td></tr>' +
                    '</table>';

                $('#' + modalId + ' .modal-body').html(htmlContent);
            }, 300); // Optional: Adjust delay for realism
        }

        // Function to show the notes modal
        function viewNotesHistory(id) {
            const modalId = 'viewUnitNotesHistoryModal';

            // Add the modal HTML to the page (only once)
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label">' +
                    '<div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Unit Notes History</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body text-start">' +
                    '<div class="text-center my-4">' +
                    '<div class="spinner-border text-primary" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            } else {
                // Reset loader content if modal already exists
                $('#' + modalId + ' .modal-body').html(
                    '<div class="text-center my-4">' +
                    '<div class="spinner-border text-primary" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Show the modal before AJAX completes
            $('#' + modalId).modal('show');

            // AJAX call to fetch notes
            $.ajax({
                url: '{{ route('getModuleNotesHistory') }}',
                type: 'GET',
                data: {
                    id: id,
                    module: 'Unit'
                },
                success: function(response) {
                    var notesHtml = '';

                    if (response.data.length === 0) {
                        notesHtml = '<p>No record found.</p>';
                    } else {
                        response.data.forEach(function(note) {
                            var notes = note.details;
                            var created = moment(note.created_at).format('DD MMM YYYY, h:mmA');
                            var statusClass = (note.status == 1) ? 'bg-success' : 'bg-dark';
                            var statusText = (note.status == 1) ? 'Active' : 'Inactive';

                            notesHtml +=
                                '<div class="note-entry">' +
                                '<p><strong>Dated:</strong> ' + created + ' <span class="badge ' +
                                statusClass + '">' + statusText + '</span></p>' +
                                '<p><strong>Notes Detail:</strong><br>' + notes + '</p>' +
                                '</div><hr>';
                        });
                    }

                    $('#' + modalId + ' .modal-body').html(notesHtml);
                },
                error: function(xhr, status, error) {
                    console.log("Error fetching notes history: " + error);
                    $('#' + modalId + ' .modal-body').html(
                        '<p>There was an error retrieving the notes. Please try again later.</p>');
                }
            });
        }

        // Contact filter radios only for users with show-private-data permission.
        const canShowPrivateData = @json(auth()->user()?->can('show-private-data') ?? false);

        // Function to show the manager details modal
        function viewManagerDetails(id) {
            const unitId = parseInt(id, 10) || 0;
            if (unitId <= 0) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No unit selected',
                        text: 'Manager details are unavailable for this row.',
                    });
                } else {
                    alert('Manager details are unavailable for this row.');
                }
                return;
            }

            const modalId = 'viewUnitManagerDetailsModal_' + unitId;
            window.managerDetailsModalID = modalId;

            // Add modal to DOM only once
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label">' +
                    '<div class="modal-dialog modal-dialog-scrollable modal-dialog-top modal-lg">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Unit Manager Details</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body text-start">' +
                    '<div class="text-center my-4">' +
                    '<div class="spinner-border text-primary" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            } else {
                // Reset loader if modal already exists
                $('#' + modalId + ' .modal-body').html(
                    '<div class="text-center my-4">' +
                    '<div class="spinner-border text-primary" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Show modal early with loader
            $('#' + modalId).modal('show');

            // AJAX to get manager details
            $.ajax({
                url: '{{ route('getModuleContacts') }}',
                type: 'GET',
                data: {
                    id: unitId,
                    module: 'Unit'
                },
                success: function(response) {
                    window.managerContacts = response.data || [];
                    renderContacts('all');
                },
                error: function(xhr) {
                    let message = 'Something went wrong.';

                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }

                    $('#' + modalId + ' .modal-body').html(
                        '<p class="text-danger">' + message + '</p>'
                    );
                }
            });
        }

        $(document).on('change', 'input[name="contact_filter"]', function() {
            if (!canShowPrivateData) {
                return;
            }
            renderContacts($(this).val());
        });

        function renderContacts(filterType) {
            var contacts = window.managerContacts || [];
            var modalId = window.managerDetailsModalID;
            if (!modalId) {
                return;
            }

            // Without show-private-data, always show the full (server-filtered) list.
            if (!canShowPrivateData) {
                filterType = 'all';
            }

            var contactHtml = '';

            if (canShowPrivateData) {
                contactHtml += `
                    <div class="mb-3">
                        <label class="me-3">
                            <input type="radio" name="contact_filter" value="all" ${filterType === 'all' ? 'checked' : ''}>
                            All
                        </label>

                        <label class="me-3">
                            <input type="radio" name="contact_filter" value="kingsburry" ${filterType === 'kingsburry' ? 'checked' : ''}>
                            Kingsburry
                        </label>

                        <label>
                            <input type="radio" name="contact_filter" value="others" ${filterType === 'others' ? 'checked' : ''}>
                            Others
                        </label>
                    </div>
                    <hr>
                `;
            }

            if (contacts.length === 0) {
                contactHtml += '<p>No records found.</p>';
            } else {
                contacts.forEach(function(contact) {
                    var name = contact.contact_name || '';
                    var email = contact.contact_email || '';
                    var phone = contact.contact_phone || 'N/A';
                    var landline = contact.contact_landline || 'N/A';
                    var note = contact.contact_note || '';

                    if (canShowPrivateData) {
                        var sourceName = (contact.job_source_name ||
                            (contact.job_source && contact.job_source.name) ||
                            '').toString().toLowerCase().trim();
                        var isHayaibuSource = contact.is_hayaibu_source === true ||
                            contact.is_hayaibu_source === 1 ||
                            sourceName.indexOf('hayaibu') !== -1;

                        if (filterType === 'kingsburry' && isHayaibuSource) {
                            return;
                        }

                        if (filterType === 'others' && !isHayaibuSource) {
                            return;
                        }
                    }

                    contactHtml += `
                <div class="note-entry">
                    <p><strong>Name:</strong> ${name}</p>
                    <p><strong>Email:</strong> ${email}</p>
                    <p><strong>Phone:</strong> ${phone}</p>
                    <p><strong>Landline:</strong> ${landline}</p>
                    <p><strong>Note:</strong> ${note || 'N/A'}</p>
                </div>
                <hr>
            `;
                });
            }

            $('#' + modalId + ' .modal-body').html(contactHtml);
        }

        $(document).ready(function() {
            $('#csvImportForm').on('submit', function(e) {
                e.preventDefault();

                let form = $(this);
                let submitBtn = form.find('button[type="submit"]');
                let formData = new FormData(this);
                let xhr = new XMLHttpRequest();

                // Disable button
                submitBtn.prop('disabled', true).text('Uploading...');

                xhr.open('POST', '{{ route('units.import') }}', true);
                xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');

                xhr.upload.addEventListener("progress", function(event) {
                    if (event.lengthComputable) {
                        let percent = Math.round((event.loaded / event.total) * 100);
                        $('#uploadProgressBar').css('width', percent + '%').text(percent + '%');
                        console.log('Uploading: ' + percent + '%');
                    }
                });

                xhr.onload = function() {
                    console.log('Upload response:', xhr.status, xhr.responseText);

                    if (xhr.status === 200) {
                        $('#uploadProgressBar')
                            .removeClass('bg-danger')
                            .addClass('bg-success')
                            .text('Upload Complete');

                        form[0].reset();
                        $('#units_table').DataTable().ajax.reload();

                        // ✅ Close modal after short delay
                        setTimeout(() => {
                            $('#csvImportModal').modal('hide');
                            $('#uploadProgressBar')
                                .css('width', '0%')
                                .removeClass('bg-success bg-danger')
                                .text('0%');
                        }, 800);
                    } else {
                        $('#uploadProgressBar')
                            .removeClass('bg-success')
                            .addClass('bg-danger')
                            .text('Upload Failed');
                        alert('Server Error: ' + xhr.responseText);
                    }

                    // Re-enable button
                    submitBtn.prop('disabled', false).text('Import CSV');
                };

                xhr.onerror = function() {
                    console.error('XHR error:', xhr.responseText);
                    $('#uploadProgressBar')
                        .removeClass('bg-success')
                        .addClass('bg-danger')
                        .text('Upload Error');
                    alert('XHR Error: ' + xhr.responseText);

                    // Re-enable button
                    submitBtn.prop('disabled', false).text('Import CSV');
                };

                xhr.send(formData);
            });

            $(document).on('click', '.export-btn', function(e) {
                e.preventDefault();

                const $link = $(this);
                const url = new URL($link.attr('href'), window.location.origin);
                const $dropdown = $link.closest('.dropdown');
                const $btn = $dropdown.find('button');
                const $icon = $btn.find('i');
                const $text = $btn.find('.btn-text');

                url.searchParams.set('status_filter', currentFilter || '');
                url.searchParams.set('blacklist_filter', currentBlacklistFilter || '');
                url.searchParams.set('search', ($('#customSearchInput').val() || '').trim());
                url.searchParams.delete('office_filter[]');
                url.searchParams.delete('office_filter');
                (currentOfficeFilters || []).forEach(function(id) {
                    if (id !== '' && id !== null && typeof id !== 'undefined') {
                        url.searchParams.append('office_filter[]', id);
                    }
                });

                // Disable button + show loader
                $btn.prop('disabled', true);
                $icon.removeClass().addClass('spinner-border spinner-border-sm me-1');
                $text.text('Exporting...');

                $.ajax({
                    url: url.toString(),
                    type: 'GET',
                    xhrFields: {
                        responseType: 'blob'
                    }, // for binary file
                    success: function(data, status, xhr) {
                        const blob = new Blob([data]);
                        const link = document.createElement('a');
                        const fileName = xhr.getResponseHeader('Content-Disposition')
                            ?.split('filename=')[1]?.replace(/['"]/g, '') || 'export.xlsx';
                        link.href = window.URL.createObjectURL(blob);
                        link.download = fileName;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    },
                    error: function() {
                        alert('Export failed. Please try again.');
                    },
                    complete: function() {
                        // Re-enable button + reset text
                        $btn.prop('disabled', false);
                        $icon.removeClass().addClass('ri-download-line me-1');
                        $text.text('Export');
                    }
                });
            });
        });
    </script>
@endsection
@endsection
