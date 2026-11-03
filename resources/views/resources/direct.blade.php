@extends('layouts.vertical', ['title' => 'Direct Sales Resources List', 'subTitle' => 'Resources'])
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
                                <!-- Date Range filter -->
                                <div class="d-inline">
                                    <input type="text" id="dateRangePicker" class="form-control d-inline-block"
                                        style="width: 220px; display: inline-block;" placeholder="Select date range"
                                        readonly />
                                    <button class="btn btn-outline-primary my-1" type="button" id="clearDateRange"
                                        title="Clear Date Range">
                                        <i class="ri-close-line"></i>
                                    </button>
                                </div>
                                <!-- user Filter Dropdown -->
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

                                        <!-- Scrollable checkbox list -->
                                        <div id="officesList">
                                            <div class="form-check">
                                                <input class="form-check-input office-filter" type="checkbox" value=""
                                                    id="all-offices" data-title-id="">
                                                <label class="form-check-label" for="all-offices">All Head Office</label>
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
                                        <div class="d-flex justify-content-end px-1 mb-1">
                                            <a href="#" class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".category-filter">Select All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".category-filter" style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="categoryList">
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
                                        <!-- Select/Deselect All (applies to the titles currently listed) -->
                                        <div class="d-flex justify-content-end px-1 mb-1">
                                            <a href="#"
                                                class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".title-filter">Select All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".title-filter" style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list: narrowed to the selected categories and type -->
                                        <div id="titleList">
                                            @foreach ($jobTitles as $title)
                                                <div class="form-check"
                                                    data-title-category="{{ $title->job_category_id }}"
                                                    data-title-type="{{ strtolower((string) $title->type) }}">
                                                    <input class="form-check-input title-filter" type="checkbox"
                                                        value="{{ $title->id }}" id="title_{{ $title->id }}"
                                                        data-title-id="{{ $title->id }}">
                                                    <label class="form-check-label"
                                                        for="title_{{ $title->id }}">{{ ucwords($title->name) }}</label>
                                                </div>
                                            @endforeach
                                            <div class="text-muted small px-1 d-none" id="titleListEmpty">
                                                No titles for the selected category and type.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Sources Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton10" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterSource">All Sources</span>
                                    </button>

                                    <div class="dropdown-menu filter-dropdowns" aria-labelledby="dropdownMenuButton10">
                                        <!-- Search input -->
                                        <input type="text" class="form-control mb-2" id="sourceSearchInput"
                                            placeholder="Search Source...">
                                        <!-- Select/Deselect All -->
                                        <div class="d-flex justify-content-end px-1 mb-1" id="sourceToggleContainer">
                                            <a href="#" id="sourceSelectAll"
                                                class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".source-filter">Select All</a>
                                            <a href="#" id="sourceDeselectAll"
                                                class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".source-filter" style="display:none">Deselect All</a>
                                        </div>
                                        <!-- Scrollable checkbox list -->
                                        <div id="sourceList">
                                            @foreach ($jobSources as $source)
                                                <div class="form-check">
                                                    <input class="form-check-input source-filter" type="checkbox"
                                                        value="{{ $source->id }}" id="source_{{ $source->id }}"
                                                        data-source-id="{{ $source->id }}">
                                                    <label class="form-check-label"
                                                        for="source_{{ $source->id }}">{{ ucwords($source->name) }}</label>
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
                            </div>
                        </div><!-- end col-->
                    </div>
                    <!-- Custom Search Bar -->
                    <div class="row justify-content-start">
                        <div class="col-lg-3">
                            <div class="text-md-start mt-3 pt-1">
                                <div class="input-group">
                                    <!-- Use padding-right to prevent text from overlapping the clear icon -->
                                    <input type="text" id="customSearchInput" class="form-control"
                                        placeholder="Search ..." style="padding-right: 30px;">
                                    <!-- Absolutely positioned over the input field -->
                                    <span class="position-absolute d-none" id="customClearBtn" title="Clear"
                                        style="right: 105px; top: 50%; transform: translateY(-50%); z-index: 10; cursor: pointer;">
                                        <i class="ri-close-line text-primary"
                                            style="font-size: 20px; font-weight: 900;"></i>
                                    </span>
                                    <button class="btn btn-primary z-3" id="customSearchBtn" type="button"><i
                                            class="ri-search-line"></i> Search</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body p-3">
                    <!-- Columns Visibility Dropdown — moved via JS (initComplete) into the same
                         flex row as DataTables' own "Show X entries" length control below
                         (same as sales/list). -->
                    <div id="columnsToolbar" class="dropdown d-inline">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button"
                            id="dropdownMenuColumns" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            aria-expanded="false">
                            <i class="ri-layout-column-line me-1"></i> Columns
                        </button>
                        <div class="dropdown-menu filter-dropdowns p-2" aria-labelledby="dropdownMenuColumns"
                            style="min-width: 230px;">
                            <div class="d-flex justify-content-end align-items-center px-1 mb-2">
                                <a href="#" id="showAllColumns" class="text-primary small fw-semibold">Show All</a>
                            </div>
                            <div id="columnToggleList" style="max-height: 280px; overflow-y: auto;">
                                {{-- One checkbox per column is added by JS from the table's columns. --}}
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="sales_table" class="table align-middle mb-3">
                            <thead class="bg-light-subtle">
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Head Office</th>
                                    <th>Unit Name</th>
                                    <th>PostCode</th>
                                    <th>Position Type</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Experience</th>
                                    <th>Qualification</th>
                                    <th>Salary</th>
                                    <th>CV Limit</th>
                                    <th>Source</th>
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

    <!-- Add daterangepicker -->
    <link rel="stylesheet" href="{{ asset('css/daterangepicker.css') }}" />
    <script src="{{ asset('js/daterangepicker.min.js') }}"></script>

    <script>
        $(function() {
            // Initialize the date range picker
            $('#dateRangePicker').daterangepicker({
                autoUpdateInput: false,
                locale: {
                    cancelLabel: 'Clear'
                }
            });

            // When a date range is selected
            $('#dateRangePicker').on('apply.daterangepicker', function(ev, picker) {
                $(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format(
                    'YYYY-MM-DD'));
                // Set the filter variable and reload DataTable
                window.currentDateRangeFilter = picker.startDate.format('YYYY-MM-DD') + '|' + picker.endDate
                    .format('YYYY-MM-DD');
                $('#showDateRange').html($(this).val());
                $('#sales_table').DataTable().ajax.reload();
            });

            // When the date range is cleared
            $('#dateRangePicker').on('cancel.daterangepicker', function(ev, picker) {
                $(this).val('');
                window.currentDateRangeFilter = '';
                $('#showDateRange').html('All Data');
                $('#sales_table').DataTable().ajax.reload();
            });

            // Clear button
            $('#clearDateRange').on('click', function() {
                $('#dateRangePicker').val('');
                window.currentDateRangeFilter = '';
                $('#showDateRange').html('All Data');
                $('#sales_table').DataTable().ajax.reload();
            });
        });

        $(document).ready(function() {
            // Store the current filter in a variable
            var currentTypeFilter = '';
            var currentCategoryFilters = [];
            var currentTitleFilters = [];
            var currentSourceFilters = [];
            var currentOfficeFilters = [];
            var currentFilterCvLimit = '';

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
                    url: @json(route('getResourcesDirectSales')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        // Add the current filter to the request parameters
                        d.type_filter =
                            currentTypeFilter; // Send the current filter value as a parameter
                        d.date_range_filter = window
                            .currentDateRangeFilter; // Send the current filter value as a parameter
                        d.category_filter =
                            currentCategoryFilters; // Send the current filter value as a parameter
                        d.title_filter =
                            currentTitleFilters; // Send the current filter value as a parameter
                        d.source_filter =
                            currentSourceFilters; // Send the current filter value as a parameter
                        d.office_filter =
                            currentOfficeFilters; // Send the current filter value as a parameter
                        d.cv_limit_filter =
                            currentFilterCvLimit; // Send the current filter value as a parameter
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
                        data: 'created_at',
                        name: 'sales.created_at'
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
                        name: 'sales.position_type'
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
                        data: 'job_source',
                        name: 'job_sources.name'
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false
                    }
                ],
                rowId: function(data) {
                    return 'row_' + data
                        .id; // Assign a unique ID to each row using the 'id' field from the data
                },
                // 'l' (length control) wrapped in its own flex row so the "Columns" button
                // (moved here in initComplete below) lines up beside it, as on sales/list.
                dom: '<"d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"l>rtip',
                initComplete: function() {
                    const api = this.api();
                    $(api.table().container())
                        .find('.dataTables_length')
                        .after($('#columnsToolbar'));
                },
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

            /*** Show / hide table columns (remembered in this browser) ***/
            const COLUMN_STORAGE_KEY = 'resources.direct.hiddenColumns';
            const fixedColumns = ['DT_RowIndex', 'action']; // always shown

            function loadHiddenColumns() {
                try {
                    const saved = JSON.parse(localStorage.getItem(COLUMN_STORAGE_KEY));
                    return Array.isArray(saved) ? saved : [];
                } catch (e) {
                    return [];
                }
            }

            function saveHiddenColumns() {
                const hidden = [];
                table.columns().every(function() {
                    if (!this.visible()) {
                        hidden.push(this.dataSrc());
                    }
                });
                try {
                    localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(hidden));
                } catch (e) {
                    // Storage unavailable (private window, blocked): the choice just isn't remembered.
                }
            }

            function buildColumnToggleList() {
                const $list = $('#columnToggleList').empty();

                table.columns().every(function(index) {
                    if (fixedColumns.includes(this.dataSrc())) {
                        return;
                    }

                    const id = 'column_toggle_' + index;
                    $list.append(
                        $('<div class="form-check">').append(
                            $('<input type="checkbox" class="form-check-input column-toggle">')
                                .attr('id', id)
                                .attr('data-column', index)
                                .prop('checked', this.visible()),
                            $('<label class="form-check-label">')
                                .attr('for', id)
                                .text($(this.header()).text().trim() || this.dataSrc())
                        )
                    );
                });
            }

            // Re-apply the columns this user hid last time (stored by data key, not position).
            const savedHiddenColumns = loadHiddenColumns();
            table.columns().every(function() {
                if (savedHiddenColumns.includes(this.dataSrc()) && !fixedColumns.includes(this.dataSrc())) {
                    this.visible(false, false);
                }
            });
            table.columns.adjust();
            buildColumnToggleList();

            $(document).on('change', '.column-toggle', function() {
                table.column($(this).data('column')).visible(this.checked);
                saveHiddenColumns();
            });

            $('#showAllColumns').on('click', function(e) {
                e.preventDefault();
                table.columns().visible(true);
                saveHiddenColumns();
                buildColumnToggleList();
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
                    e.preventDefault();
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

                // Titles follow the selected type (and categories).
                refreshTitleOptions();

                table.ajax.reload(); // Reload with updated type filter
            });
            // cv limit filter dropdown handler
            $('.cv-limit-filter').on('click', function() {
                currentFilterCvLimit = $(this).text().toLowerCase();

                // Capitalize each word
                const formattedText = currentFilterCvLimit
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
            /*** Category / Title / Source filters ***/
            // Ids of the checked boxes for a filter class, e.g. '.category-filter' + 'category-id'.
            function checkedIds(selector, dataKey) {
                return $(selector + ':checked').map(function() {
                    return $(this).data(dataKey);
                }).get();
            }

            function setFilterLabel(labelSelector, count, noun) {
                $(labelSelector).text(count ? 'Selected ' + noun + ' (' + count + ')' : 'All ' + noun);
            }

            // Show "Select All" while some listed box is unchecked, "Deselect All" while any is checked.
            function updateSelectToggles(target) {
                const $visible = $(target).filter(function() {
                    return $(this).closest('.form-check').css('display') !== 'none';
                });
                const $menu = $(target).first().closest('.dropdown-menu');
                $menu.find('.filter-select-all').toggle($visible.not(':checked').length > 0);
                $menu.find('.filter-deselect-all').toggle($(target + ':checked').length > 0);
            }

            function syncCategories() {
                currentCategoryFilters = checkedIds('.category-filter', 'category-id');
                setFilterLabel('#showFilterCategory', currentCategoryFilters.length, 'Categories');
                updateSelectToggles('.category-filter');
                refreshTitleOptions();
            }

            function syncTitles() {
                currentTitleFilters = checkedIds('.title-filter', 'title-id');
                setFilterLabel('#showFilterTitle', currentTitleFilters.length, 'Titles');
                updateSelectToggles('.title-filter');
            }

            function syncSources() {
                currentSourceFilters = checkedIds('.source-filter', 'source-id');
                setFilterLabel('#showFilterSource', currentSourceFilters.length, 'Sources');
                updateSelectToggles('.source-filter');
            }

            /**
             * List only the titles that belong to the selected categories (all, if none
             * selected) and the selected type (all, for "All Types"), also matching the
             * title search box. Titles that drop out of the list are unchecked.
             */
            window.refreshTitleOptions = function() {
                const categories = currentCategoryFilters.map(String);
                const type = (currentTypeFilter || '').toLowerCase();
                const search = ($('#titleSearchInput').val() || '').toLowerCase();
                let shown = 0;

                $('#titleList .form-check').each(function() {
                    const $item = $(this);
                    const matchesCategory = categories.length === 0 ||
                        categories.includes(String($item.data('title-category')));
                    const matchesType = type === '' || type === 'all types' ||
                        String($item.data('title-type')) === type;
                    const matchesSearch = $item.find('label').text().toLowerCase().includes(search);
                    const available = matchesCategory && matchesType;

                    if (!available) {
                        $item.find('.title-filter').prop('checked', false);
                    }

                    $item.toggle(available && matchesSearch);
                    if (available && matchesSearch) {
                        shown++;
                    }
                });

                $('#titleListEmpty').toggleClass('d-none', shown > 0);
                syncTitles();
            };

            $('.category-filter').on('change', function() {
                syncCategories();
                table.ajax.reload();
            });

            $('.title-filter').on('change', function() {
                syncTitles();
                table.ajax.reload();
            });

            $('.source-filter').on('change', function() {
                syncSources();
                table.ajax.reload();
            });

            // Select All / Deselect All: only the boxes currently listed (not hidden by search,
            // category or type), then one table reload.
            const filterSyncs = {
                '.category-filter': syncCategories,
                '.title-filter': syncTitles,
                '.source-filter': syncSources,
            };

            $(document).on('click', '.filter-select-all, .filter-deselect-all', function(e) {
                e.preventDefault();
                e.stopPropagation(); // keep the dropdown open

                const target = $(this).data('target');
                if (!filterSyncs[target]) {
                    return;
                }

                const selectAll = $(this).hasClass('filter-select-all');
                $(target).each(function() {
                    if ($(this).closest('.form-check').css('display') !== 'none') {
                        $(this).prop('checked', selectAll);
                    }
                });

                filterSyncs[target]();
                table.ajax.reload();
            });

            $('#titleSearchInput').on('keyup', refreshTitleOptions);

            $('#sourceSearchInput').on('keyup', function() {
                const searchValue = this.value.toLowerCase();
                $('#sourceList .form-check').each(function() {
                    $(this).toggle($(this).find('label').text().toLowerCase().includes(
                        searchValue));
                });
                updateSelectToggles('.source-filter');
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

                // Update dropdown display text
                const selectedLabels = $('.user-filter:checked')
                    .map(function() {
                        return $(this).next('label').text().trim();
                    }).get();

                $('#showFilterUser').text(selectedLabels.length ? 'Selected Users (' + selectedLabels
                    .length +
                    ')' : 'All Users');

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

                // Update dropdown display text
                const selectedLabels = $('.office-filter:checked')
                    .map(function() {
                        return $(this).next('label').text().trim();
                    }).get();

                $('#showFilterOffice').text(selectedLabels.length ? 'Selected Offices (' + selectedLabels
                    .length +
                    ')' : 'All Offices');

                // Trigger DataTable reload with the selected filters
                table.ajax.reload();
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
        function showNotesModal(notes, unitName, unitPostcode) {
            // Set the notes content in the modal with proper line breaks using HTML
            $('#showNotesModal .modal-body').html(
                'Unit Name: <strong>' + unitName + '</strong><br>' +
                'Postcode: <strong>' + unitPostcode + '</strong><br>' +
                'Notes Detail: <p>' + notes + '</p>'
            );

            // Show the modal
            $('#showNotesModal').modal('show');

            // Add the modal HTML to the page (only once, if not already present)
            if ($('#showNotesModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="showNotesModal" tabindex="-1" aria-labelledby="showNotesModalLabel" >' +
                    '<div class="modal-dialog modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="showNotesModalLabel">Sale Notes</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<!-- Notes content will be dynamically inserted here -->' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }
        }

        // Function to show the notes modal
        function addNotesModal(saleID) {
            // Add the modal HTML to the page (only once, if not already present)
            if ($('#notesModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="notesModal" tabindex="-1" aria-labelledby="notesModalLabel" >' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="notesModalLabel">Add Notes</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="notesForm">' +
                    '<div class="mb-3">' +
                    '<label for="detailsTextarea" class="form-label">Details</label>' +
                    '<textarea class="form-control" id="detailsTextarea" rows="4" required></textarea>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-primary" id="saveNotesButton">' +
                    'Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Show the modal
            $('#notesModal').modal('show');

            // Handle the save button click
            $('#saveNotesButton').off('click').on('click', function() {
                const notes = $('#detailsTextarea').val();

                if (!notes) {
                    if (!notes) {
                        $('#detailsTextarea').addClass('is-invalid');
                        if ($('#detailsTextarea').next('.invalid-feedback').length === 0) {
                            $('#detailsTextarea').after(
                                '<div class="invalid-feedback">Please provide details.</div>');
                        }
                    }

                    // Add event listeners to remove validation errors dynamically
                    $('#detailsTextarea').on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Remove validation errors if inputs are valid
                $('#detailsTextarea').removeClass('is-invalid').addClass('is-valid');
                $('#detailsTextarea').next('.invalid-feedback').remove();

                // Send the data via AJAX
                $.ajax({
                    url: '{{ route('storeSaleNotes') }}', // Replace with your endpoint
                    type: 'POST',
                    data: {
                        sale_id: saleID,
                        details: notes,
                        _token: '{{ csrf_token() }}' // Directly include token in data
                    },
                    success: function(response) {
                        toastr.success('Notes saved successfully!');

                        $('#notesModal').modal('hide'); // Close the modal
                        $('#notesForm')[0].reset(); // Clear the form
                        $('#detailsTextarea').removeClass('is-valid'); // Remove valid class
                        $('#detailsTextarea').next('.invalid-feedback')
                            .remove(); // Remove error message

                        $('#sales_table').DataTable().ajax.reload(); // Reload the DataTable
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while saving notes.');
                    }
                });
            });
        }

        // Function to change sale status modal
        function changeSaleStatusModal(saleID, status) {
            // Add the modal HTML to the page (only once, if not already present)
            if ($('#changeSaleStatusModal').length === 0) {
                $('body').append(
                    `<div class="modal fade" id="changeSaleStatusModal" tabindex="-1" aria-labelledby="changeSaleStatusModalLabel" >
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="changeSaleStatusModalLabel">Change Sale Status</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="changeSaleStatusForm">
                                        <div class="mb-3">
                                            <label for="detailsTextarea" class="form-label">Details</label>
                                            <textarea class="form-control" id="detailsTextarea" rows="4" required></textarea>
                                        </div>
                                       <input type="hidden" id="statusDropdown" name="status" value="${status}">
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-success" id="saveNotesButton">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>`
                );
            }

            // Show the modal
            $('#changeSaleStatusModal').modal('show');

            // Handle the save button click
            $('#saveNotesButton').off('click').on('click', function() {
                const notes = $('#detailsTextarea').val();
                const selectedStatus = $('#statusDropdown').val();

                let hasError = false;

                if (!notes) {
                    $('#detailsTextarea').addClass('is-invalid');
                    if ($('#detailsTextarea').next('.invalid-feedback').length === 0) {
                        $('#detailsTextarea').after('<div class="invalid-feedback">Please provide details.</div>');
                    }
                    hasError = true;
                }

                if (hasError) {
                    $('#detailsTextarea').on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });
                    return;
                }

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
                    success: function(response) {
                        toastr.success('Sale status changed successfully!');
                        $('#changeSaleStatusModal').modal('hide');
                        $('#changeSaleStatusForm')[0].reset();
                        $('#detailsTextarea, #statusDropdown').removeClass('is-valid is-invalid');
                        $('#detailsTextarea, #statusDropdown').next('.invalid-feedback').remove();

                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while saving sale status changed.');
                    }
                });
            });
        }

        // Function to change on hold status modal
        function changeSaleOnHoldStatusModal(saleID, status) {
            // Add the modal HTML to the page (only once, if not already present)
            if ($('#changeSaleOnHoldStatusModal').length === 0) {
                $('body').append(
                    `<div class="modal fade" id="changeSaleOnHoldStatusModal" tabindex="-1" aria-labelledby="changeSaleOnHoldStatusModalLabel" >
                        <div class="modal-dialog modal-lg modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="changeSaleOnHoldStatusModalLabel">Mark as On Hold Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="changeSaleOnHoldStatusForm">
                                        <div class="mb-3">
                                            <label for="detailsTextarea" class="form-label">Details</label>
                                            <textarea class="form-control" id="detailsTextarea" rows="4" required></textarea>
                                        </div>
                                        <input type="hidden" id="status" name="status" value="${status}">
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-success" id="saveOnHoldNotesButton">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>`
                );
            }

            // Show the modal
            $('#changeSaleOnHoldStatusModal').modal('show');

            // Handle the save button click
            $('#saveOnHoldNotesButton').off('click').on('click', function() {
                const notes = $('#detailsTextarea').val();
                const selectedStatus = $('#status').val();

                let hasError = false;

                if (!notes) {
                    $('#detailsTextarea').addClass('is-invalid');
                    if ($('#detailsTextarea').next('.invalid-feedback').length === 0) {
                        $('#detailsTextarea').after('<div class="invalid-feedback">Please provide details.</div>');
                    }
                    hasError = true;
                }

                if (hasError) {
                    $('#detailsTextarea').on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // AJAX request
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
                        toastr.success('Sale status changed successfully!');
                        $('#changeSaleOnHoldStatusModal').modal('hide');
                        $('#changeSaleOnHoldStatusForm')[0].reset();
                        $('#detailsTextarea, #statusDropdown').removeClass('is-valid is-invalid');
                        $('#detailsTextarea, #statusDropdown').next('.invalid-feedback').remove();

                        $('#sales_table').DataTable().ajax.reload();
                    },
                    error: function(xhr) {
                        toastr.error('An error occurred while saving sale status changed.');
                    }
                });
            });
        }

        function showDetailsModal(saleId, officeName, name, postcode,
            jobCategory, jobTitle, status, timing, experience, salary,
            position, qualification, benefits
        ) {
            // Set the notes content in the modal as a table
            $('#showDetailsModal .modal-body').html(
                '<table class="table table-bordered">' +
                '<tr>' +
                '<th>Sale ID</th>' +
                '<td>' + saleId + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Head Office Name</th>' +
                '<td>' + officeName + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Unit Name</th>' +
                '<td>' + name + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Postcode</th>' +
                '<td>' + postcode + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Job Category</th>' +
                '<td>' + jobCategory + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Job Title</th>' +
                '<td>' + jobTitle + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Status</th>' +
                '<td>' + status + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Timing</th>' +
                '<td>' + timing + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Qualification</th>' +
                '<td>' + qualification + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Salary</th>' +
                '<td>' + salary + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Position</th>' +
                '<td>' + position + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Experience</th>' +
                '<td>' + experience + '</td>' +
                '</tr>' +
                '<tr>' +
                '<th>Benefits</th>' +
                '<td>' + benefits + '</td>' +
                '</tr>' +
                '</table>'
            );

            // Show the modal
            $('#showDetailsModal').modal('show');

            // Add the modal HTML to the page (only once, if not already present)
            if ($('#showDetailsModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="showDetailsModal" tabindex="-1" aria-labelledby="showDetailsModalLabel" >' +
                    '<div class="modal-dialog modal-lg modal-dialog-top modal-dialog-scrollable">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="showDetailsModalLabel">Sale Details</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<!-- Notes content will be dynamically inserted here -->' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }
        }

        // Function to show the notes modal
        function viewNotesHistory(id) {
            // Make an AJAX call to retrieve notes history data
            $.ajax({
                url: '{{ route('getModuleNotesHistory') }}', // Your backend URL to fetch notes history, replace it with your actual URL
                type: 'GET',
                data: {
                    id: id,
                    module: 'Sale'

                }, // Pass the id to your server to fetch the corresponding applicant's notes
                success: function(response) {
                    var notesHtml = ''; // This will hold the combined HTML for all notes

                    // Check if the response data is empty
                    if (response.data.length === 0) {
                        notesHtml = '<p>No record found.</p>';
                    } else {
                        // Loop through the response array (assuming it's an array of notes)
                        response.data.forEach(function(note) {
                            var notes = note.details;
                            var created = moment(note.created_at).format('DD MMM YYYY, h:mmA');
                            var status = note.status;

                            // Determine the badge class based on the status value
                            var statusClass = (status == 1) ? 'bg-success' :
                                'bg-dark'; // 'bg-success' for active, 'bg-dark' for inactive
                            var statusText = (status == 1) ? 'Active' : 'Inactive';

                            // Append each note's details to the notesHtml string
                            notesHtml +=
                                '<div class="note-entry">' +
                                '<p><strong>Dated:</strong> ' + created +
                                '&nbsp;&nbsp;<span class="badge ' + statusClass + '">' + statusText +
                                '</span></p>' +
                                '<p><strong>Notes Detail:</strong> <br>' + notes + '</p>' +
                                '</div><hr>'; // Add a separator between notes
                        });
                    }

                    // Set the combined notes content in the modal
                    $('#viewNotesHistoryModal .modal-body').html(notesHtml);

                    // Show the modal
                    $('#viewNotesHistoryModal').modal('show');
                },
                error: function(xhr, status, error) {
                    console.log("Error fetching notes history: " + error);
                    // Optionally, you can display an error message in the modal
                    $('#viewNotesHistoryModal .modal-body').html(
                        '<p>There was an error retrieving the notes. Please try again later.</p>');
                    $('#viewNotesHistoryModal').modal('show');
                }
            });

            // Add the modal HTML to the page (only once, if not already present)
            if ($('#viewNotesHistoryModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="viewNotesHistoryModal" tabindex="-1" aria-labelledby="viewNotesHistoryModalLabel" >' +
                    '<div class="modal-dialog modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="viewNotesHistoryModalLabel">Sale Notes History</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<!-- Notes content will be dynamically inserted here -->' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }
        }

        // Function to show the notes modal
        function viewManagerDetails(id) {
            // Make an AJAX call to retrieve notes history data
            $.ajax({
                url: '{{ route('getModuleContacts') }}', // Your backend URL to fetch notes history, replace it with your actual URL
                type: 'GET',
                data: {
                    id: id,
                    module: 'Unit'

                }, // Pass the id to your server to fetch the corresponding applicant's notes
                success: function(response) {
                    var contactHtml = ''; // This will hold the combined HTML for all notes

                    // Check if the response data is empty
                    if (response.data.length === 0) {

                        contactHtml = '<p>' + response.message + '</p>';
                    } else {
                        // Loop through the response array (assuming it's an array of notes)
                        response.data.forEach(function(contact) {
                            var name = contact.contact_name;
                            var email = contact.contact_email;
                            var phone = contact.contact_phone;
                            var landline = contact.contact_landline ? contact.contact_landline : '-';
                            var note = contact.contact_note ? contact.contact_note : 'N/A';

                            // Append each note's details to the notesHtml string
                            contactHtml +=
                                '<div class="note-entry">' +
                                '<p><strong>Name:</strong> ' + name + '</p>' +
                                '<p><strong>Email:</strong> ' + email + '</p>' +
                                '<p><strong>Phone:</strong> ' + phone + '</p>' +
                                '<p><strong>Landline:</strong> ' + landline + '</p>' +
                                '<p><strong>Notes:</strong> ' + note + '</p>' +
                                '</div><hr>'; // Add a separator between notes
                        });
                    }

                    // Set the combined notes content in the modal
                    $('#viewManagerDetailsModal .modal-body').html(contactHtml);

                    // Show the modal
                    $('#viewManagerDetailsModal').modal('show');
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

            // Add the modal HTML to the page (only once, if not already present)
            if ($('#viewManagerDetailsModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="viewManagerDetailsModal" tabindex="-1" aria-labelledby="viewManagerDetailsModalLabel" >' +
                    '<div class="modal-dialog modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="viewManagerDetailsModalLabel">Manager Details</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<!-- Notes content will be dynamically inserted here -->' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }
        }
    </script>
@endsection
@endsection
