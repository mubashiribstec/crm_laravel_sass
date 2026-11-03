@extends('layouts.vertical', ['title' => 'Closed Sales List', 'subTitle' => 'Sales'])
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
                                <!-- Date Range filter -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dateRangeDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-calendar-line me-1"></i> <span id="showDateRange">Last 3 Months</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dateRangeDropdown">
                                        <a class="dropdown-item date-range-filter" href="#">Last 3 Months</a>
                                        <a class="dropdown-item date-range-filter" href="#">Last 6 Months</a>
                                        <a class="dropdown-item date-range-filter" href="#">Last 9 Months</a>
                                        <a class="dropdown-item date-range-filter" href="#">Other</a>
                                    </div>
                                </div>
                                <!-- user Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton5" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterUser">All Users</span>
                                    </button>

                                    <div class="dropdown-menu filter-dropdowns" aria-labelledby="dropdownMenuButton5">
                                        <!-- Search input -->
                                        <input type="text" class="form-control mb-2" id="userSearchInput"
                                            placeholder="Search user...">

                                        <!-- Select/Deselect All -->
                                        <div class="d-flex justify-content-end px-1 mb-1" id="userToggleContainer">
                                            <a href="#" class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".user-filter" data-exclude="[data-user-id='']">Select All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".user-filter" data-exclude="[data-user-id='']"
                                                style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="usersList">
                                            <!-- <div class="form-check">
                                                    <input class="form-check-input user-filter" type="checkbox" value=""
                                                        id="all-Users" data-title-id="">
                                                    <label class="form-check-label" for="all-Users">All Users</label>
                                                </div> -->

                                            @foreach ($users as $user)
                                                <div class="form-check">
                                                    <input class="form-check-input user-filter" type="checkbox"
                                                        value="{{ $user->id }}" id="user_{{ $user->id }}"
                                                        data-user-id="{{ $user->id }}">
                                                    <label class="form-check-label"
                                                        for="user_{{ $user->id }}">{{ ucwords($user->name) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
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
                                            <a href="#"
                                                class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".office-filter" data-exclude="[data-office-id='']">Select
                                                All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".office-filter" data-exclude="[data-office-id='']"
                                                style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="officesList">
                                            <!-- <div class="form-check">
                                                    <input class="form-check-input office-filter" type="checkbox" value=""
                                                        id="all-offices" data-title-id="">
                                                    <label class="form-check-label" for="all-offices">All Head Office</label>
                                                </div> -->

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
                                <!-- Category Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterCategory">All
                                            Category</span>
                                    </button>

                                    <div class="dropdown-menu filter-dropdowns" aria-labelledby="dropdownMenuButton1">
                                        <!-- Search input -->
                                        <input type="text" class="form-control mb-2" id="categorySearchInput"
                                            placeholder="Search category...">

                                        <!-- Select/Deselect All -->
                                        <div class="d-flex justify-content-end px-1 mb-1" id="categoryToggleContainer">
                                            <a href="#"
                                                class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".category-filter" data-exclude="[data-category-id='']">Select
                                                All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".category-filter" data-exclude="[data-category-id='']"
                                                style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="categoryList">
                                            <!-- <div class="form-check">
                                                    <input class="form-check-input category-filter" type="checkbox" value=""
                                                        id="all-categories" data-title-id="">
                                                    <label class="form-check-label" for="all-categories">All Category</label>
                                                </div> -->

                                            @foreach ($jobCategories as $category)
                                                <div class="form-check">
                                                    <input class="form-check-input category-filter" type="checkbox"
                                                        value="{{ $category->id }}" id="category_{{ $category->id }}"
                                                        data-category-id="{{ $category->id }}">
                                                    <label class="form-check-label"
                                                        for="category_{{ $category->id }}">{{ ucwords($category->name) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <!-- Type Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton4" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterType">All Types</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton4">
                                        <a class="dropdown-item type-filter" href="#">All Types</a>
                                        <a class="dropdown-item type-filter" href="#">Specialist</a>
                                        <a class="dropdown-item type-filter" href="#">Regular</a>
                                    </div>
                                </div>
                                <!-- Title Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton2" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterTitle">All Titles</span>
                                    </button>

                                    <div class="dropdown-menu p-2 filter-dropdowns" aria-labelledby="dropdownMenuButton2"
                                        style="min-width: 250px;">
                                        <!-- Search input -->
                                        <input type="text" class="form-control mb-2" id="titleSearchInput"
                                            placeholder="Search titles...">

                                        <!-- Select/Deselect All -->
                                        <div class="d-flex justify-content-end px-1 mb-1" id="titleToggleContainer">
                                            <a href="#"
                                                class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".title-filter" data-exclude="[data-title-id='']">Select
                                                All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".title-filter" data-exclude="[data-title-id='']"
                                                style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="titleList">
                                            <!-- <div class="form-check">
                                                    <input class="form-check-input title-filter" type="checkbox" value=""
                                                        id="all-titles" data-title-id="">
                                                    <label class="form-check-label" for="all-titles">All Titles</label>
                                                </div> -->
                                            @foreach ($jobTitles as $title)
                                                <div class="form-check">
                                                    <input class="form-check-input title-filter" type="checkbox"
                                                        value="{{ $title->id }}" id="title_{{ $title->id }}"
                                                        data-title-id="{{ $title->id }}">
                                                    <label class="form-check-label"
                                                        for="title_{{ $title->id }}">{{ ucwords($title->name) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <!-- cv limit Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton7" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterCvLimit">All Count</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton7">
                                        <a class="dropdown-item cv-limit-filter" href="#">All Count</a>
                                        <a class="dropdown-item cv-limit-filter" href="#">Zero</a>
                                        <a class="dropdown-item cv-limit-filter" href="#">Not Max</a>
                                        <a class="dropdown-item cv-limit-filter" href="#">Max</a>
                                    </div>
                                </div>
                                <!-- Button Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton3" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-download-line me-1"></i> <span class="btn-text">Export</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                        <a class="dropdown-item export-btn"
                                            href="{{ route('salesExport', ['type' => 'allClose']) }}">Export All Data</a>
                                        <a class="dropdown-item export-btn"
                                            href="{{ route('salesExport', ['type' => 'emailsClose']) }}">Export Emails</a>
                                    </div>
                                </div>
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
                        <table id="sales_table" class="table align-middle mb-3">
                            <thead class="bg-light-subtle">
                                <tr>
                                    <th>#</th>
                                    <th>Created Date</th>
                                    <th>Updated Date</th>
                                    <th>Closed Date</th>
                                    <th>Agent</th>
                                    <th>Head Office</th>
                                    <th>Unit Name</th>
                                    <th width="8%">PostCode</th>
                                    <th>Position Type</th>
                                    <th>Title</th>
                                    <th>Category</th>
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

    <!-- Experience Modal -->
    <div class="modal fade" id="experienceModal" tabindex="-1" aria-labelledby="experienceModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="experienceModalLabel">Sale Experience</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="experienceModalBody">
                    <!-- Experience will be injected here -->
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

    <!-- Add daterangepicker CSS/JS (place before your custom script section) -->
    <link rel="stylesheet" href="{{ asset('css/daterangepicker.css') }}" />
    <script src="{{ asset('js/daterangepicker.min.js') }}"></script>

    <script>
        $(document).ready(function() {
            // Store the current filter in a variable
            var currentFilter = '';
            var currentTypeFilter = '';
            var currentDateRangeFilter = '';
            var currentCategoryFilters = [];
            var currentUserFilters = [];
            var currentTitleFilters = [];
            var currentOfficeFilters = [];
            var currentCVLimitFilter = '';

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
                    url: @json(route('getClosedSales')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        // Add the current filter to the request parameters
                        d.status_filter = currentFilter; // Send the current filter value as a parameter
                        d.type_filter =
                        currentTypeFilter; // Send the current filter value as a parameter
                        d.date_range_filter =
                        currentDateRangeFilter; // Send the current filter value as a parameter
                        d.category_filter =
                        currentCategoryFilters; // Send the current filter value as a parameter
                        d.title_filter =
                        currentTitleFilters; // Send the current filter value as a parameter
                        d.office_filter =
                        currentOfficeFilters; // Send the current filter value as a parameter
                        d.user_filter =
                        currentUserFilters; // Send the current filter value as a parameter
                        d.cv_limit_filter =
                        currentCVLimitFilter; // Send the current filter value as a parameter
                    },
                    beforeSend: function() {
                        showLoader(); // Show loader before AJAX request starts
                    },
                    error: function(xhr) {
                        console.error('DataTable AJAX error:', xhr.status, xhr.responseJSON);
                        $('#sales_table tbody').empty().html(
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
                        data: 'created_at',
                        name: 'sales.created_at'
                    },
                    {
                        data: 'updated_at',
                        name: 'sales.updated_at'
                    },
                    {
                        data: 'closed_date',
                        name: 'closed_date'
                    },
                    {
                        data: 'user_name',
                        name: 'users.name'
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
                        data: 'sale_postcode',
                        name: 'sales.sale_postcode'
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
                columnDefs: [{
                        targets: 7, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    },
                    {
                        targets: 14, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    },
                    {
                        targets: 16, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    },
                    {
                        targets: 17, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    }
                ],
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
            // Type filter dropdown handler
            $('.type-filter').on('click', function() {
                currentTypeFilter = $(this).text().toLowerCase();

                // Capitalize each word
                const formattedText = currentTypeFilter
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');

                $('#showFilterType').html(formattedText);
                table.ajax.reload(); // Reload with updated type filter
            });
            // cv limit filter dropdown handler
            $('.cv-limit-filter').on('click', function() {
                currentCVLimitFilter = $(this).text().toLowerCase();

                // Capitalize each word
                const formattedText = currentCVLimitFilter
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');

                $('#showFilterCvLimit').html(formattedText);
                table.ajax.reload(); // Reload with updated status filter
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
            // Status filter dropdown handler
            $('.date-range-filter').on('click', function() {
                // Get the clicked text and convert to lowercase
                currentDateRangeFilter = $(this).text().toLowerCase().replace(/\s+/g, '-');

                // Format text for display: capitalize each word (using the original string)
                const formattedText = $(this).text()
                    .toLowerCase()
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');

                // Update the dropdown display label
                $('#showDateRange').html(formattedText);

                // Optionally, log or use currentDateRangeFilter with hyphens
                console.log('Selected filter:', currentDateRangeFilter);

                // Reload table (assuming it uses currentDateRangeFilter somehow)
                table.ajax.reload();
            });
            /*** Category filter handler ***/
            $('.category-filter').on('change', function() {
                const id = $(this).data('category-id');
                // Handle "All"
                if (id === '' || id === undefined) {
                    currentCategoryFilters = [];
                    $('.category-filter').not(this).prop('checked', false);
                } else {
                    if (this.checked) {
                        currentCategoryFilters.push(id);
                        $('.category-filter[data-category-id=""]').prop('checked', false);
                    } else {
                        currentCategoryFilters = currentCategoryFilters.filter(x => x !== id);
                    }
                }

                // Update dropdown display text and toggle visibility
                const total = $('.category-filter').not('[data-category-id=""]').length;
                const checked = $('.category-filter:checked').not('[data-category-id=""]').length;

                $('#showFilterCategory').text(checked > 0 ? 'Selected Categories (' + checked +
                    ')' : 'All Category');

                const container = $('#categoryToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

                // Trigger DataTable reload with the selected filters
                table.ajax.reload();
            });
            /*** Title Filter Handler ***/
            $('.title-filter').on('change', function() {
                const id = $(this).data('title-id');

                // Handle "All Titles"
                if (id === '' || id === undefined) {
                    currentTitleFilters = [];
                    $('.title-filter').not(this).prop('checked', false);
                } else {
                    // Remove or add to array
                    if (this.checked) {
                        currentTitleFilters.push(id);
                        // Uncheck "All Titles"
                        $('.title-filter[data-title-id=""]').prop('checked', false);
                    } else {
                        currentTitleFilters = currentTitleFilters.filter(x => x !== id);
                    }
                }

                // Update dropdown display text and toggle visibility
                const total = $('.title-filter').not('[data-title-id=""]').length;
                const checked = $('.title-filter:checked').not('[data-title-id=""]').length;

                $('#showFilterTitle').text(checked > 0 ? 'Selected Titles (' + checked +
                    ')' : 'All Titles');

                const container = $('#titleToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

                // Trigger DataTable reload with the selected filters
                table.ajax.reload();
            });
            /*** User Filter Handler ***/
            $('.user-filter').on('change', function() {
                const id = $(this).data('user-id');

                // Handle "All Titles"
                if (id === '' || id === undefined) {
                    currentUserFilters = [];
                    $('.user-filter').not(this).prop('checked', false);
                } else {
                    // Remove or add to array
                    if (this.checked) {
                        currentUserFilters.push(id);
                        // Uncheck "All Titles"
                        $('.user-filter[data-user-id=""]').prop('checked', false);
                    } else {
                        currentUserFilters = currentUserFilters.filter(x => x !== id);
                    }
                }

                // Update dropdown display text and toggle visibility
                const total = $('.user-filter').not('[data-user-id=""]').length;
                const checked = $('.user-filter:checked').not('[data-user-id=""]').length;

                $('#showFilterUser').text(checked > 0 ? 'Selected Users (' + checked +
                    ')' : 'All Users');

                const container = $('#userToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

                // Trigger DataTable reload with the selected filters
                table.ajax.reload();
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

                $('#showFilterOffice').text(checked > 0 ? 'Selected Offices (' + checked +
                    ')' : 'All Head Office');

                const container = $('#officeToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

                // Trigger DataTable reload with the selected filters
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
        });

        document.getElementById('categorySearchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const checkboxes = document.querySelectorAll('#categoryList .form-check');

            checkboxes.forEach(function(item) {
                const label = item.querySelector('label').innerText.toLowerCase();
                item.style.display = label.includes(searchValue) ? '' : 'none';
            });
        });

        document.getElementById('titleSearchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const checkboxes = document.querySelectorAll('#titleList .form-check');

            checkboxes.forEach(function(item) {
                const label = item.querySelector('label').innerText.toLowerCase();
                item.style.display = label.includes(searchValue) ? '' : 'none';
            });
        });

        document.getElementById('userSearchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const checkboxes = document.querySelectorAll('#usersList .form-check');

            checkboxes.forEach(function(item) {
                const label = item.querySelector('label').innerText.toLowerCase();
                item.style.display = label.includes(searchValue) ? '' : 'none';
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

        // Function to show the notes modal
        function showNotesModal(saleId, notes, officeName, unitName, unitPostcode) {
            const modalId = `showNotesModal_${saleId}`;

            // Check and append modal only once
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Sale Notes</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body text-center">
                                    <div class="spinner-border text-primary my-4" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            } else {
                // Reset body content with loader if it already exists
                $(`#${modalId} .modal-body`).html(`
                    <div class="spinner-border text-primary my-4" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                `);
            }

            // Show modal
            $(`#${modalId}`).modal('show');

            // Set content after short delay (simulate loading)
            setTimeout(() => {
                $(`#${modalId} .modal-body`).html(`
                    <div class="text-start">
                        <p class="mb-1"><strong>Head Office Name:</strong> ${officeName}</p>
                        <p class="mb-1"><strong>Unit Name:</strong> ${unitName}</p>
                        <p class="mb-1"><strong>Postcode:</strong> ${unitPostcode}</p>
                        <p><strong>Notes Detail:</strong><br>${notes.replace(/\n/g, '<br>')}</p>
                    </div>
                `);
            }, 300); // adjust delay if needed
        }

        // Function to show the notes modal
        function addNotesModal(saleID) {
            const modalId = `notesModal_${saleID}`;
            const formId = `notesForm_${saleID}`;
            const textareaId = `detailsTextarea_${saleID}`;
            const saveBtnId = `saveNotesButton_${saleID}`;

            // Append modal HTML only once
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Add Notes</h5>
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
                                    <button type="button" class="btn btn-success" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Show modal
            $(`#${modalId}`).modal('show');

            // Reset form fields on open
            $(`#${modalId}`).on('shown.bs.modal', function() {
                $(`#${formId}`)[0].reset();
                $(`#${textareaId}`).removeClass('is-invalid is-valid');
                $(`#${textareaId}`).next('.invalid-feedback').remove();
            });

            // Save button logic
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

                $.ajax({
                    url: '{{ route('storeSaleNotes') }}',
                    type: 'POST',
                    data: {
                        sale_id: saleID,
                        details: notes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Notes saved successfully!');

                        $(`#${modalId}`).modal('hide');
                        $(`#${formId}`)[0].reset();
                        $(`#${textareaId}`).removeClass('is-valid');

                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        alert('An error occurred while saving notes.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        // Function to change sale status modal
        function changeSaleStatusModal(saleID, currentStatus) {
            const modalId = `changeSaleStatusModal_${saleID}`;

            // Create modal if it doesn't exist
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Change Sale Status</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="changeSaleStatusForm_${saleID}">
                                        <div class="mb-3">
                                            <label for="detailsTextarea_${saleID}" class="form-label">Details</label>
                                            <textarea class="form-control" id="detailsTextarea_${saleID}" rows="4" required></textarea>
                                        </div>
                                        <div class="mb-3" style="display:none;">
                                            <label for="statusDropdown_${saleID}" class="form-label">Status</label>
                                            <select class="form-select" id="statusDropdown_${saleID}" required>
                                                <option value="" disabled selected>Select Status</option>
                                                <option value="1">Active</option>
                                                <option value="0">Closed</option>
                                                <option value="2">Pending</option>
                                                <option value="3">Reject</option>
                                            </select>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-success" id="saveNotesButton_${saleID}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Show modal and reset form on load
            const modalSelector = `#${modalId}`;
            const formSelector = `#changeSaleStatusForm_${saleID}`;
            const textareaSelector = `#detailsTextarea_${saleID}`;
            const dropdownSelector = `#statusDropdown_${saleID}`;
            const saveButtonSelector = `#saveNotesButton_${saleID}`;

            $(modalSelector).on('shown.bs.modal', function() {
                $(formSelector)[0].reset();
                $(textareaSelector).removeClass('is-invalid is-valid');
                $(dropdownSelector).removeClass('is-invalid is-valid');
                $(textareaSelector).next('.invalid-feedback').remove();
                $(dropdownSelector).next('.invalid-feedback').remove();

                // Pre-select status if passed
                if (currentStatus !== undefined && currentStatus !== null) {
                    $(dropdownSelector).val(currentStatus);
                }
            });

            $(modalSelector).modal('show');

            // Save button handler
            $(saveButtonSelector).off('click').on('click', function() {
                const notes = $(textareaSelector).val();
                const selectedStatus = $(dropdownSelector).val();
                let hasError = false;

                // Notes validation
                if (!notes) {
                    $(textareaSelector).addClass('is-invalid');
                    if ($(textareaSelector).next('.invalid-feedback').length === 0) {
                        $(textareaSelector).after('<div class="invalid-feedback">Please provide details.</div>');
                    }
                    hasError = true;
                }

                // Status validation
                if (!selectedStatus) {
                    $(dropdownSelector).addClass('is-invalid');
                    if ($(dropdownSelector).next('.invalid-feedback').length === 0) {
                        $(dropdownSelector).after('<div class="invalid-feedback">Please select a status.</div>');
                    }
                    hasError = true;
                }

                if (hasError) {
                    $(textareaSelector).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });
                    $(dropdownSelector).on('change', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });
                    return;
                }

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                    );

                // AJAX request
                $.ajax({
                    url: '{{ route('changeSaleStatus') }}',
                    type: 'POST',
                    data: {
                        sale_id: saleID,
                        details: notes,
                        status: selectedStatus,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function() {
                        toastr.success('Sale status changed successfully!');
                        $(modalSelector).modal('hide');
                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        toastr.error('An error occurred while updating the sale status.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        // Function to change on hold status modal
        function changeSaleOnHoldStatusModal(saleID, status) {
            const modalId = `changeSaleOnHoldStatusModal_${saleID}`;
            const formId = `changeSaleOnHoldStatusForm_${saleID}`;
            const textareaId = `detailsTextarea_${saleID}`;
            const saveBtnId = `saveOnHoldNotesButton_${saleID}`;

            // Append modal HTML if it doesn't already exist
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Mark as On Hold Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="${formId}">
                                        <div class="mb-3">
                                            <label for="${textareaId}" class="form-label">Details</label>
                                            <textarea class="form-control" id="${textareaId}" rows="4" required></textarea>
                                        </div>
                                        <input type="hidden" id="status_${saleID}" name="status" value="${status}">
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-success" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Reset form and show modal
            const modalSelector = `#${modalId}`;
            const textareaSelector = `#${textareaId}`;
            const formSelector = `#${formId}`;
            const saveBtnSelector = `#${saveBtnId}`;

            $(modalSelector).on('shown.bs.modal', function() {
                $(formSelector)[0].reset();
                $(textareaSelector).removeClass('is-invalid is-valid');
                $(textareaSelector).next('.invalid-feedback').remove();
            });

            $(modalSelector).modal('show');

            // Save button handler
            $(saveBtnSelector).off('click').on('click', function() {
                const notes = $(textareaSelector).val();
                const selectedStatus = $(`#status_${saleID}`).val();

                let hasError = false;

                if (!notes) {
                    $(textareaSelector).addClass('is-invalid');
                    if ($(textareaSelector).next('.invalid-feedback').length === 0) {
                        $(textareaSelector).after('<div class="invalid-feedback">Please provide details.</div>');
                    }
                    hasError = true;
                }

                if (hasError) {
                    $(textareaSelector).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });
                    return;
                }

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                    );

                // AJAX request (use POST instead of GET)
                $.ajax({
                    url: '{{ route('changeSaleHoldStatus') }}',
                    type: 'POST',
                    data: {
                        id: saleID,
                        details: notes,
                        status: selectedStatus,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Sale marked as On Hold successfully!');
                        $(modalSelector).modal('hide');
                        $(formSelector)[0].reset();
                        $(textareaSelector).removeClass('is-valid is-invalid');
                        $(textareaSelector).next('.invalid-feedback').remove();

                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while updating the On Hold status.');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }

        function showDetailsModal(
            saleId, postedOn, officeName, name, postcode,
            jobCategory, jobTitle, status, timing,
            experience, salary, position, qualification, benefits
        ) {
            const modalId = `showDetailsModal_${saleId}`;

            // Create modal if it doesn't already exist
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-top modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Sale Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body modal-body-text-left">
                                    <div class="spinner-border text-primary my-4" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            } else {
                // Reset content with loader on each call
                $(`#${modalId} .modal-body`).html(`
                    <div class="spinner-border text-primary my-4" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                `);
            }

            // Show the modal
            $(`#${modalId}`).modal('show');

            // Simulate loading delay before filling in data
            setTimeout(() => {
                const tableHTML = `
                    <table class="table table-bordered mb-0">
                        <tr><th>Sale ID</th><td>${saleId}</td></tr>
                        <tr><th>Posted On</th><td>${postedOn}</td></tr>
                        <tr><th>Head Office Name</th><td>${officeName}</td></tr>
                        <tr><th>Unit Name</th><td>${name}</td></tr>
                        <tr><th>Postcode</th><td>${postcode}</td></tr>
                        <tr><th>Job Category</th><td>${jobCategory}</td></tr>
                        <tr><th>Job Title</th><td>${jobTitle}</td></tr>
                        <tr><th>Status</th><td>${status}</td></tr>
                        <tr><th>Timing</th><td>${timing}</td></tr>
                        <tr><th>Qualification</th><td>${qualification}</td></tr>
                        <tr><th>Salary</th><td>${salary}</td></tr>
                        <tr><th>Position</th><td>${position}</td></tr>
                        <tr><th>Experience</th><td>${experience}</td></tr>
                        <tr><th>Benefits</th><td>${benefits}</td></tr>
                    </table>
                `;

                $(`#${modalId} .modal-body`).html(tableHTML);
            }, 300); // Adjust delay to match actual data loading if needed
        }

        // Function to show the notes modal
        function viewSaleDocuments(saleId) {
            const modalId = `viewSaleDocumentsModal_${saleId}`;
            const modalLabelId = `viewSaleDocumentsModalLabel_${saleId}`;

            // Append modal HTML only if it doesn't already exist
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalLabelId}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalLabelId}">Sale Documents</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body text-center">
                                    <div class="spinner-border text-primary my-4" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            } else {
                // Reset with loader if modal exists
                $(`#${modalId} .modal-body`).html(`
                    <div class="spinner-border text-primary my-4" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                `);
            }

            // Show modal
            $(`#${modalId}`).modal('show');

            // Make AJAX request
            $.ajax({
                url: '{{ route('getSaleDocuments') }}',
                type: 'GET',
                data: {
                    id: saleId
                },
                success: function(response) {
                    let contentHtml = '';

                    if (!response.data || response.data.length === 0) {
                        contentHtml = '<p class="text-muted text-center">No record found.</p>';
                    } else {
                        response.data.forEach(doc => {
                            const created = moment(doc.created_at).format('DD MMM YYYY, h:mm A');

                            // ✅ DB already contains folder path relative to public/
                            const filePath = '/' + doc.document_path;

                            const docName = doc.document_name;

                            contentHtml += `
                                <div class="note-entry text-start">
                                    <p><strong>Dated:</strong> ${created}</p>
                                    <p><strong>File:</strong> ${docName}
                                        <br>
                                        <button class="btn btn-sm btn-primary mt-1"
                                            onclick="window.open('${encodeURI(filePath)}', '_blank')">
                                            Open
                                        </button>
                                    </p>
                                </div>
                                <hr>
                            `;
                        });
                    }

                    $(`#${modalId} .modal-body`).html(contentHtml);
                },
                error: function(xhr) {
                    $(`#${modalId} .modal-body`).html(
                        '<p class="text-danger">There was an error retrieving the documents. Please try again later.</p>'
                        );
                }
            });
        }

        // Function to show the notes modal
        function viewNotesHistory(id) {
            const modalId = `viewNotesHistoryModal_${id}`;
            const modalLabelId = `viewNotesHistoryModalLabel_${id}`;

            // Create the modal if it doesn't already exist
            if ($(`#${modalId}`).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalLabelId}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalLabelId}">Sale Notes History</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body text-center">
                                    <div class="spinner-border text-primary my-4" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            } else {
                // Reset modal content with loader if already exists
                $(`#${modalId} .modal-body`).html(`
                    <div class="spinner-border text-primary my-4" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                `);
            }

            // Show the modal
            $(`#${modalId}`).modal('show');

            // AJAX request to fetch notes
            $.ajax({
                url: '{{ route('getModuleNotesHistory') }}',
                type: 'GET',
                data: {
                    id: id,
                    module: 'Sale'
                },
                success: function(response) {
                    let notesHtml = '';

                    if (!response.data || response.data.length === 0) {
                        notesHtml = '<p class="text-muted text-center">No record found.</p>';
                    } else {
                        response.data.forEach(note => {
                            const created = moment(note.created_at).format('DD MMM YYYY, h:mmA');
                            const status = note.status;
                            const statusClass = status == 1 ? 'bg-success' : 'bg-dark';
                            const statusText = status == 1 ? 'Active' : 'Inactive';
                            const notes = note.details.replace(/\n/g, '<br>');

                            notesHtml += `
                                <div class="note-entry text-start">
                                    <p><strong>Dated:</strong> ${created} &nbsp;
                                    <span class="badge ${statusClass}">${statusText}</span></p>
                                    <p><strong>Notes Detail:</strong><br>${notes}</p>
                                </div><hr>
                            `;
                        });
                    }

                    $(`#${modalId} .modal-body`).html(notesHtml);
                },
                error: function(xhr) {
                    $(`#${modalId} .modal-body`).html(
                        '<p class="text-danger">There was an error retrieving the notes. Please try again later.</p>'
                        );
                }
            });
        }

        // Function to show the manager details modal
        function viewManagerDetails(id) {
            const modalID = 'viewManagerDetailsModal-' + id;

            // Create modal if it doesn't exist
            if ($('#' + modalID).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalID}" tabindex="-1" aria-labelledby="viewManagerDetailsModalLabel-${id}">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="viewManagerDetailsModalLabel-${id}">Manager Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body modal-body-text-left">
                                    <div class="text-center py-3">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Show modal immediately with loading state
            $('#' + modalID).modal('show');

            // Make AJAX call
            $.ajax({
                url: '{{ route('getModuleContacts') }}',
                type: 'GET',
                data: {
                    id: id,
                    module: 'Unit'
                },
                success: function(response) {
                    let contactHtml = '';

                    if (response.data.length === 0) {

                        contactHtml = '<p>' + response.message + '</p>';
                    } else {
                        response.data.forEach(function(contact) {
                            const name = contact.contact_name;
                            const email = contact.contact_email;
                            const phone = contact.contact_phone;
                            const landline = contact.contact_landline || '-';
                            const note = contact.contact_note || 'N/A';

                            contactHtml += `
                                <div class="note-entry">
                                    <p><strong>Name:</strong> ${name}</p>
                                    <p><strong>Email:</strong> ${email}</p>
                                    <p><strong>Phone:</strong> ${phone}</p>
                                    <p><strong>Landline:</strong> ${landline}</p>
                                    <p><strong>Notes:</strong> ${note}</p>
                                </div><hr>`;
                        });
                    }

                    $('#' + modalID + ' .modal-body').html(contactHtml);
                },
                error: function(xhr, status, error) {

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

        document.addEventListener('DOMContentLoaded', function() {
            document.body.addEventListener('click', function(e) {
                if (e.target.classList.contains('view-experience')) {
                    e.preventDefault();
                    const experience = e.target.getAttribute('data-experience');
                    document.getElementById('experienceModalBody').innerHTML = experience;
                }
            });
        });

        $(document).on('click', '.export-btn', function(e) {
            e.preventDefault();

            const $link = $(this);
            // Carry the table's current filters and search into the export.
            const url = window.salesExportUrl($link.attr('href'), '#sales_table');
            const $dropdown = $link.closest('.dropdown');
            const $btn = $dropdown.find('button');
            const $icon = $btn.find('i');
            const $text = $btn.find('.btn-text');

            // Disable button + show loader
            $btn.prop('disabled', true);
            $icon.removeClass().addClass('spinner-border spinner-border-sm me-1');
            $text.text('Exporting...');

            $.ajax({
                url: url,
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
    </script>
    @include('sales.partials.export-filters')
@endsection
@endsection
