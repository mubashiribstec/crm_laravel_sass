@extends('layouts.vertical', ['title' => 'Quality Sales List', 'subTitle' => 'Home'])
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
                                <!-- Status Filter Dropdown -->
                                <div class="dropdown d-inline">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButton2" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterStatus">Requested
                                            Sales</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                        <a class="dropdown-item status-filter" href="#">Requested Sales</a>
                                        <a class="dropdown-item status-filter" href="#">Cleared Sales</a>
                                        <a class="dropdown-item status-filter" href="#">Rejected Sales</a>
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
                                            <a href="#" class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".office-filter" data-exclude="[data-office-id='']">Select
                                                All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".office-filter" data-exclude="[data-office-id='']"
                                                style="display:none">Deselect All</a>
                                        </div>

                                        <!-- Scrollable checkbox list -->
                                        <div id="officesList">

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
                                            Categories</span>
                                    </button>

                                    <div class="dropdown-menu filter-dropdowns" aria-labelledby="dropdownMenuButton1">
                                        <!-- Search input -->
                                        <input type="text" class="form-control mb-2" id="categorySearchInput"
                                            placeholder="Search category...">

                                        <!-- Select/Deselect All -->
                                        <div class="d-flex justify-content-end px-1 mb-1" id="categoryToggleContainer">
                                            <a href="#" class="filter-select-all text-primary small fw-semibold me-2"
                                                data-target=".category-filter" data-exclude="[data-category-id='']">Select
                                                All</a>
                                            <a href="#" class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".category-filter" data-exclude="[data-category-id='']"
                                                style="display:none">Deselect All</a>
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
                                            @foreach ($jobTitles as $title)
                                                <div class="form-check">
                                                    <input class="form-check-input title-filter" type="checkbox"
                                                        value="{{ $title->id }}" id="title_{{ $title->id }}"
                                                        data-title-id="{{ $title->id }}"
                                                        data-category-id="{{ $title->job_category_id }}"
                                                        data-type="{{ $title->type }}">
                                                    <label class="form-check-label"
                                                        for="title_{{ $title->id }}">{{ ucwords($title->name) }}</label>
                                                </div>
                                            @endforeach
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
                                                data-target=".source-filter" data-exclude="[data-source-id='']">Select
                                                All</a>
                                            <a href="#" id="sourceDeselectAll"
                                                class="filter-deselect-all text-danger small fw-semibold"
                                                data-target=".source-filter" data-exclude="[data-source-id='']"
                                                style="display:none">Deselect All</a>
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
                                <!-- Blacklisted only checkbox -->
                                <label class="btn btn-outline-primary me-1 my-1 mb-1" for="blacklistedOnlyFilter">
                                    <input class="form-check-input mt-0 me-1" type="checkbox" id="blacklistedOnlyFilter" autocomplete="off">
                                    Blacklisted only
                                </label>
                            </div>
                        </div><!-- end col-->
                    </div>
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
                                 flex row as DataTables' own "Show X entries" length control below. -->
                    <div id="columnsToolbar" class="dropdown d-inline">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button"
                            id="dropdownMenuColumns" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="ri-layout-column-line me-1"></i> Columns
                        </button>
                        <div class="dropdown-menu filter-dropdowns p-2" aria-labelledby="dropdownMenuColumns"
                            style="min-width: 230px;">
                            <div class="d-flex justify-content-between align-items-center px-1 mb-2">
                                <a href="#" id="columnsSelectAll" class="text-primary small fw-semibold">Show
                                    All</a>
                                <a href="#" id="columnsResetDefault" class="text-secondary small fw-semibold">Reset
                                    Default</a>
                            </div>
                            <div id="columnsList" style="max-height: 280px; overflow-y: auto;">
                                {{-- Checkbox per toggleable column is injected by JS from columnConfig,
                                     kept in sync with the <thead> below by column index. --}}
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="sales_table" class="table align-middle mb-3">
                            <thead class="bg-light-subtle">
                                <tr>
                                    <th>#</th>
                                    <th>Created Date</th>
                                    <th>Updated Date</th>
                                    <th>Head Office</th>
                                    <th>Unit Name</th>
                                    <th>PostCode</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Details</th>
                                    <th>Experience</th>
                                    <th>Qualification</th>
                                    <th>Salary</th>
                                    <th>CV Limit</th>
                                    <th>Source</th>
                                    <th width="15%">Notes</th>
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
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('copy-quality-sales-notes-btn')) {
                const targetSelector = e.target.getAttribute('data-copy-quality-sales-notes-target');
                const targetEl = document.querySelector(targetSelector);
                if (!targetEl) return;

                const temp = document.createElement('textarea');
                temp.value = targetEl.innerText;
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                document.body.removeChild(temp);

                e.target.innerText = 'Copied!';
                e.target.classList.remove('btn-outline-secondary');
                e.target.classList.add('btn-success');

                setTimeout(() => {
                    e.target.innerText = 'Copy Notes';
                    e.target.classList.remove('btn-success');
                    e.target.classList.add('btn-outline-secondary');
                }, 1500);
            }
        });
    </script>
    <script>
        $(document).ready(function() {
            // Store the current filter in a variable
            var currentFilter = '';
            var currentTypeFilter = '';
            var currentCategoryFilters = [];
            var currentTitleFilters = [];
            var currentOfficeFilters = [];
            var currentSourceFilters = [];
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

            // ---------------------------------------------------------------
            // Column visibility (show/hide columns)
            // ---------------------------------------------------------------
            // Index here MUST line up with both the <thead> markup above and
            // the `columns:` array passed to DataTable() below. `toggleable:
        // false` marks columns that are always shown and excluded from
            // the "Columns" dropdown (row index + action menu).
            const columnConfig = [{
                    title: '#',
                    toggleable: false
                },
                {
                    title: 'Created Date',
                    default: true
                },
                {
                    title: 'Updated Date',
                    default: false
                },
                {
                    title: 'Head Office',
                    default: true
                },
                {
                    title: 'Unit Name',
                    default: false
                },
                {
                    title: 'PostCode',
                    default: true
                },
                {
                    title: 'Title',
                    default: true
                },
                {
                    title: 'Category',
                    default: true
                },
                
                {
                    title: 'Details',
                    default: true
                },
                {
                    title: 'Experience',
                    default: false
                },
                {
                    title: 'Qualification',
                    default: false
                },
                {
                    title: 'Salary',
                    default: false
                },
                {
                    title: 'CV Limit',
                    default: true
                },
                {
                    title: 'Source',
                    default: true
                },
                {
                    title: 'Notes',
                    default: true
                },
                {
                    title: 'Status',
                    default: true
                },
                {
                    title: 'Action',
                    toggleable: false
                },
            ];

            const COLUMN_VISIBILITY_STORAGE_KEY = 'quality_sales_table_column_visibility_v1';

            // Reads the saved per-column show/hide choices from localStorage
            // (falling back to each column's `default`) so the layout the
            // user picked survives page reloads/navigation.
            function loadColumnVisibility() {
                let stored = {};
                try {
                    stored = JSON.parse(localStorage.getItem(COLUMN_VISIBILITY_STORAGE_KEY)) || {};
                } catch (e) {
                    stored = {};
                }

                return columnConfig.map(function(col, index) {
                    if (col.toggleable === false) {
                        return true;
                    }
                    return stored.hasOwnProperty(index) ? !!stored[index] : !!col.default;
                });
            }

            function saveColumnVisibility(visibilityByIndex) {
                const toStore = {};
                columnConfig.forEach(function(col, index) {
                    if (col.toggleable !== false) {
                        toStore[index] = !!visibilityByIndex[index];
                    }
                });
                localStorage.setItem(COLUMN_VISIBILITY_STORAGE_KEY, JSON.stringify(toStore));
            }

            let columnVisibility = loadColumnVisibility();

            // Build the checkbox list inside the "Columns" dropdown from
            // columnConfig, reflecting the currently active visibility state.
            function renderColumnsDropdown() {
                const $list = $('#columnsList');
                $list.empty();

                columnConfig.forEach(function(col, index) {
                    if (col.toggleable === false) {
                        return;
                    }

                    const checked = columnVisibility[index] ? 'checked' : '';
                    $list.append(`
                        <div class="form-check">
                            <input class="form-check-input column-toggle" type="checkbox"
                                id="column_${index}" data-column-index="${index}" ${checked}>
                            <label class="form-check-label" for="column_${index}">${col.title}</label>
                        </div>
                    `);
                });
            }

            renderColumnsDropdown();

            // Column indices currently hidden, used as a `columnDefs` target
            // so DataTable() renders with the right columns hidden from the
            // very first draw (no flash of columns that then disappear).
            function getHiddenColumnIndices() {
                return columnVisibility
                    .map(function(visible, index) {
                        return visible ? null : index;
                    })
                    .filter(function(index) {
                        return index !== null;
                    });
            }

            // Initialize DataTable with server-side processing
            var table = $('#sales_table').DataTable({
                processing: false, // Disable default processing state
                serverSide: true, // Enables server-side processing
                ajax: {
                    url: @json(route('getSalesByTypeAjaxRequest')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        // Add the current filter to the request parameters
                        d.status_filter = currentFilter; // Send the current filter value as a parameter
                        d.type_filter =
                            currentTypeFilter; // Send the current filter value as a parameter
                        d.category_filter =
                            currentCategoryFilters; // Send the current filter value as a parameter
                        d.title_filter = (typeof window.getVisibleListingTitleIds === 'function' ?
                            window.getVisibleListingTitleIds() : currentTitleFilters
                        ); // Send the current filter value as a parameter
                        d.office_filter =
                            currentOfficeFilters; // Send the current filter value as a parameter
                        d.source_filter =
                            currentSourceFilters; // Send the current filter value as a parameter
                        d.blacklist_filter = $('#blacklistedOnlyFilter').is(':checked') ? 1 : 0;
                        d.cv_limit_filter =
                            currentFilterCvLimit; // Send the current filter value as a parameter
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
                        data: 'job_title',
                        name: 'job_titles.name'
                    },
                    {
                        data: 'job_category',
                        name: 'job_categories.name'
                    },
                   
                    {
                        data: 'job_details',
                        name: 'job_details',
                        orderable: false
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
                        targets: 13, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    },
                    {
                        targets: 9, // Column index for 'job_details'
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
                        // Applies the saved/default column visibility (see columnConfig
                        // above) on the very first draw, so nothing "flashes" visible
                        // before being hidden.
                        targets: getHiddenColumnIndices(),
                        visible: false
                    }
                ],
                rowId: function(data) {
                    return 'row_' + data
                        .id; // Assign a unique ID to each row using the 'id' field from the data
                },
                // 'l' (length control) wrapped in its own flex row so the "Columns" button
                // (moved here in initComplete below) lines up beside it instead of stacking
                // on its own line.
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

            // ---------------------------------------------------------------
            // Column visibility dropdown handlers
            // ---------------------------------------------------------------

            // Individual column checkbox toggle
            $(document).on('change', '.column-toggle', function() {
                const index = parseInt($(this).data('column-index'), 10);
                const visible = $(this).is(':checked');

                columnVisibility[index] = visible;
                table.column(index).visible(visible);
                saveColumnVisibility(columnVisibility);
            });

            // "Show All" — makes every toggleable column visible
            $('#columnsSelectAll').on('click', function(e) {
                e.preventDefault();

                columnConfig.forEach(function(col, index) {
                    if (col.toggleable !== false) {
                        columnVisibility[index] = true;
                    }
                });

                table.columns().every(function() {
                    const index = this.index();
                    if (columnConfig[index].toggleable !== false) {
                        this.visible(true);
                    }
                });

                saveColumnVisibility(columnVisibility);
                renderColumnsDropdown();
            });

            // "Reset Default" — restores each column to its columnConfig default
            $('#columnsResetDefault').on('click', function(e) {
                e.preventDefault();

                columnConfig.forEach(function(col, index) {
                    if (col.toggleable !== false) {
                        columnVisibility[index] = !!col.default;
                    }
                });

                table.columns().every(function() {
                    const index = this.index();
                    if (columnConfig[index].toggleable !== false) {
                        this.visible(columnVisibility[index]);
                    }
                });

                saveColumnVisibility(columnVisibility);
                renderColumnsDropdown();
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
            // Blacklisted only checkbox
            $('#blacklistedOnlyFilter').on('change', function() {
                table.ajax.reload();
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

            function syncCheckboxFilterUI(options) {
                const {
                    filterClass,
                    excludeAttr,
                    labelSelector,
                    emptyLabel,
                    selectedLabel,
                    toggleContainer,
                    getSelectedIds
                } = options;

                const $items = $(filterClass).not(excludeAttr);
                const total = $items.length;
                const checked = $items.filter(':checked').length;

                $(labelSelector).text(checked > 0 ? `${selectedLabel} (${checked})` : emptyLabel);

                const $container = $(toggleContainer);
                $container.find('.filter-select-all').toggle(checked < total);
                $container.find('.filter-deselect-all').toggle(checked > 0);

                return getSelectedIds();
            }

            function getCheckboxFilterConfig(filterClass) {
                const configs = {
                    '.office-filter': {
                        filterClass: '.office-filter',
                        idData: 'office-id',
                        excludeAttr: '[data-office-id=""]',
                        labelSelector: '#showFilterOffice',
                        emptyLabel: 'All Head Office',
                        selectedLabel: 'Selected Office',
                        toggleContainer: '#officeToggleContainer',
                        getSelected: () => currentOfficeFilters,
                        setSelected: (ids) => {
                            currentOfficeFilters = ids;
                        }
                    },
                    '.category-filter': {
                        filterClass: '.category-filter',
                        idData: 'category-id',
                        excludeAttr: '[data-category-id=""]',
                        labelSelector: '#showFilterCategory',
                        emptyLabel: 'All Categories',
                        selectedLabel: 'Selected Categories',
                        toggleContainer: '#categoryToggleContainer',
                        getSelected: () => currentCategoryFilters,
                        setSelected: (ids) => {
                            currentCategoryFilters = ids;
                        }
                    },
                    '.title-filter': {
                        filterClass: '.title-filter',
                        idData: 'title-id',
                        excludeAttr: '[data-title-id=""]',
                        labelSelector: '#showFilterTitle',
                        emptyLabel: 'All Titles',
                        selectedLabel: 'Selected Titles',
                        toggleContainer: '#titleToggleContainer',
                        getSelected: () => currentTitleFilters,
                        setSelected: (ids) => {
                            currentTitleFilters = ids;
                        }
                    },
                    '.source-filter': {
                        filterClass: '.source-filter',
                        idData: 'source-id',
                        excludeAttr: '[data-source-id=""]',
                        labelSelector: '#showFilterSource',
                        emptyLabel: 'All Sources',
                        selectedLabel: 'Selected Sources',
                        toggleContainer: '#sourceToggleContainer',
                        getSelected: () => currentSourceFilters,
                        setSelected: (ids) => {
                            currentSourceFilters = ids;
                        }
                    }
                };

                return configs[filterClass] || null;
            }

            function applyCheckboxFilterChange($checkbox, config) {
                if (!config) {
                    return;
                }

                const id = $checkbox.data(config.idData);

                if (id === '' || id === undefined) {
                    config.setSelected([]);
                    $(config.filterClass).not($checkbox).prop('checked', false);
                } else if ($checkbox.prop('checked')) {
                    const selected = config.getSelected();
                    if (!selected.includes(id)) {
                        selected.push(id);
                    }
                    config.setSelected(selected);
                    $(config.filterClass + config.excludeAttr).prop('checked', false);
                } else {
                    config.setSelected(config.getSelected().filter(x => x !== id));
                }

                config.setSelected(syncCheckboxFilterUI({
                    filterClass: config.filterClass,
                    excludeAttr: config.excludeAttr,
                    labelSelector: config.labelSelector,
                    emptyLabel: config.emptyLabel,
                    selectedLabel: config.selectedLabel,
                    toggleContainer: config.toggleContainer,
                    getSelectedIds: () => $(config.filterClass + ':checked')
                        .not(config.excludeAttr)
                        .map(function() {
                            return $(this).data(config.idData);
                        })
                        .get()
                }));

                table.ajax.reload();
            }

            /*** Category filter handler ***/
            $('.category-filter').on('change', function() {
                applyCheckboxFilterChange($(this), getCheckboxFilterConfig('.category-filter'));
            });

            /*** Title Filter Handler ***/
            $('.title-filter').on('change', function() {
                applyCheckboxFilterChange($(this), getCheckboxFilterConfig('.title-filter'));
            });

            /*** Office Filter Handler ***/
            $('.office-filter').on('change', function() {
                applyCheckboxFilterChange($(this), getCheckboxFilterConfig('.office-filter'));
            });

            /*** Source Filter Handler ***/
            $('.source-filter').on('change', function() {
                applyCheckboxFilterChange($(this), getCheckboxFilterConfig('.source-filter'));
            });

            /*** Dropdown Select All Action ***/
            $(document).on('click', '.filter-select-all', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const filterClass = $(this).attr('data-target');
                const excludeAttr = $(this).attr('data-exclude');
                const config = getCheckboxFilterConfig(filterClass);
                if (!config) {
                    return;
                }

                const $items = $(filterClass).not(excludeAttr);

                $(filterClass + excludeAttr).prop('checked', false);
                $items.prop('checked', true);

                config.setSelected($items.map(function() {
                    return $(this).data(config.idData);
                }).get());
                syncCheckboxFilterUI({
                    filterClass: filterClass,
                    excludeAttr: config.excludeAttr,
                    labelSelector: config.labelSelector,
                    emptyLabel: config.emptyLabel,
                    selectedLabel: config.selectedLabel,
                    toggleContainer: config.toggleContainer,
                    getSelectedIds: () => config.getSelected()
                });

                table.ajax.reload();
            });

            /*** Dropdown Deselect All Action ***/
            $(document).on('click', '.filter-deselect-all', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const filterClass = $(this).attr('data-target');
                const excludeAttr = $(this).attr('data-exclude');
                const config = getCheckboxFilterConfig(filterClass);
                if (!config) {
                    return;
                }

                $(filterClass).not(excludeAttr).prop('checked', false);
                $(filterClass + excludeAttr).prop('checked', false);

                config.setSelected([]);
                syncCheckboxFilterUI({
                    filterClass: filterClass,
                    excludeAttr: config.excludeAttr,
                    labelSelector: config.labelSelector,
                    emptyLabel: config.emptyLabel,
                    selectedLabel: config.selectedLabel,
                    toggleContainer: config.toggleContainer,
                    getSelectedIds: () => config.getSelected()
                });

                table.ajax.reload();
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

        document.getElementById('officeSearchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const checkboxes = document.querySelectorAll('#officesList .form-check');

            checkboxes.forEach(function(item) {
                const label = item.querySelector('label').innerText.toLowerCase();
                item.style.display = label.includes(searchValue) ? '' : 'none';
            });
        });

        document.getElementById('sourceSearchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const checkboxes = document.querySelectorAll('#sourceList .form-check');

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
        // function showNotesModal(saleID, notes, unitName, unitPostcode) {
        //     const modalId = 'showNotesModal' + saleID;

        //     // Add the modal HTML only once if not already present
        //     if ($('#' + modalId).length === 0) {
        //         $('body').append(
        //             '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId + 'Label">' +
        //                 '<div class="modal-dialog modal-dialog-top">' +
        //                     '<div class="modal-content">' +
        //                         '<div class="modal-header">' +
        //                             '<h5 class="modal-title" id="' + modalId + 'Label">Sale Notes</h5>' +
        //                             '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
        //                         '</div>' +
        //                         '<div class="modal-body">' +
        //                             '<div class="text-center">' +
        //                                 '<div class="spinner-border text-primary" role="status">' +
        //                                     '<span class="visually-hidden">Loading...</span>' +
        //                                 '</div>' +
        //                             '</div>' +
        //                         '</div>' +
        //                         '<div class="modal-footer">' +
        //                             '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
        //                         '</div>' +
        //                     '</div>' +
        //                 '</div>' +
        //             '</div>'
        //         );
        //     }

        //     // Show the modal immediately with loader
        //     $('#' + modalId).modal('show');

        //     // Populate content after slight delay (for realism / UX polish)
        //     setTimeout(() => {
        //         const notesContent = notes
        //             ? `<p><strong>Unit Name:</strong> ${unitName}</p>
    //             <p><strong>Postcode:</strong> ${unitPostcode}</p>
    //             <p><strong>Notes Detail:</strong><br>${notes}</p>`
        //             : '<p>No notes available for this sale.</p>';

        //         $('#' + modalId + ' .modal-body').html(notesContent);
        //     }, 300); // simulate a loading experience
        // }

        document.addEventListener('click', function(e) {

            const link = e.target.closest('.job-details');
            if (!link) return;

            e.preventDefault();

            let job;
            try {
                job = JSON.parse(link.dataset.job);
            } catch (err) {
                console.error('Invalid job data', err);
                return;
            }

            showDetailsModal(job);
        });

        function showDetailsModal(job) {
            const modalId = `job-modal-${job.sale_id}`;
            document.getElementById(modalId)?.remove();

            document.body.insertAdjacentHTML('beforeend', `
                <div class="modal fade" id="${modalId}" tabindex="-1">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Job Details</h5>
                                <button class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <table class="table table-bordered">
                                    <tr><th>Sale ID</th><td>${job.sale_id}</td></tr>
                                    <tr><th>Posted Date</th><td>${job.posted_date}</td></tr>
                                    <tr><th>Head Office</th><td>${job.office_name}</td></tr>
                                    <tr><th>Unit Name</th><td>${job.unit_name}</td></tr>
                                    <tr><th>Postcode</th><td>${job.postcode}</td></tr>
                                    <tr><th>Job Category</th><td>${job.job_category}</td></tr>
                                    <tr><th>Job Title</th><td>${job.job_title}</td></tr>
                                    <tr><th>Status</th><td>${job.status}</td></tr>
                                    <tr><th>Timing</th><td>${job.timing}</td></tr>
                                    <tr><th>Experience</th><td>${job.experience}</td></tr>
                                    <tr><th>Salary</th><td>${job.salary}</td></tr>
                                    <tr><th>Position</th><td>${job.position}</td></tr>
                                    <tr><th>Qualification</th><td>${job.qualification}</td></tr>
                                    <tr><th>Benefits</th><td>${job.benefits}</td></tr>
                                </table>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `);

            new bootstrap.Modal(document.getElementById(modalId)).show();
        }

        // Function to show the notes modal
        function viewNotesHistory(id) {
            const modalId = 'viewNotesHistoryModal';
            const modalSelector = '#' + modalId;

            // Create modal HTML only once
            if ($(modalSelector).length === 0) {
                $('body').append(
                    `<div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Sale Notes History</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="text-center">
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
                    </div>`
                );
            } else {
                // Reset to loader before new data loads
                $(`${modalSelector} .modal-body`).html(`
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                `);
            }

            // Show modal immediately with loader
            $(modalSelector).modal('show');

            // Perform AJAX call
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
                        notesHtml = '<p>No record found.</p>';
                    } else {
                        response.data.forEach(note => {
                            const created = moment(note.created_at).format('DD MMM YYYY, h:mmA');
                            const statusText = note.status == 1 ? 'Active' : 'Inactive';
                            const statusClass = note.status == 1 ? 'bg-success' : 'bg-dark';
                            const notes = note.details || 'N/A';

                            notesHtml += `
                                <div class="note-entry">
                                    <p><strong>Dated:</strong> ${created}
                                    &nbsp;&nbsp;<span class="badge ${statusClass}">${statusText}</span></p>
                                    <p><strong>Notes Detail:</strong><br>${notes}</p>
                                </div><hr>
                            `;
                        });
                    }

                    $(`${modalSelector} .modal-body`).html(notesHtml);
                },
                error: function(xhr, status, error) {
                    console.error("Error fetching notes history:", error);
                    $(`${modalSelector} .modal-body`).html(
                        '<p>There was an error retrieving the notes. Please try again later.</p>');
                }
            });
        }

        // Function to show the notes modal
        function viewManagerDetails(id) {
            const modalID = 'viewManagerDetailsModal-' + id;

            // Create modal if it doesn't exist
            if ($('#' + modalID).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalID}" tabindex="-1" aria-labelledby="viewManagerDetailsModalLabel-${id}">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top modal-lg">
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

        // Function to show the notes modal
        function viewSaleDocuments(id) {
            const modalId = 'viewSaleDocumentsModal' + id;

            // Append modal only once if not present
            if ($('#' + modalId).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="${modalId}Label">Sale Documents</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body ">
                                    <div class="text-center">
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
            } else {
                // Reset body content with loader if modal already exists
                $('#' + modalId + ' .modal-body').html(`
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                `);
            }

            // Show modal immediately with loader
            $('#' + modalId).modal('show');

            // Fetch documents via AJAX
            $.ajax({
                url: '{{ route('getSaleDocuments') }}',
                type: 'GET',
                data: {
                    id: id
                },
                success: function(response) {
                    let notesHtml = '';

                    if (!response.data || response.data.length === 0) {
                        notesHtml = '<p>No record found.</p>';
                    } else {
                        response.data.forEach(doc => {
                            const created = moment(doc.created_at).format('DD MMM YYYY, h:mm A');

                            // ✅ DB already contains folder path relative to public/
                            const filePath = '/' + doc.document_path;

                            const docName = doc.document_name;

                            notesHtml += `
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

                    $('#' + modalId + ' .modal-body').html(notesHtml);
                },
                error: function(xhr, status, error) {
                    console.error("Error fetching sale documents:", error);
                    $('#' + modalId + ' .modal-body').html(
                        '<p>There was an error retrieving the documents. Please try again later.</p>');
                }
            });
        }

        // Function to show the notes modal
        function changeSaleStatus(saleID, currentStatus) {
            const modalId = 'changeSaleStatusModal-' + saleID;

            // Remove any existing modal with the same ID
            $('#' + modalId).remove();

            // Modal HTML with unique ID
            const modalHtml = `
                <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-top">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="${modalId}Label">Mark Sale As ${currentStatus}</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="changeSaleStatusForm-${saleID}">
                                    <div class="mb-3">
                                        <label for="detailsTextarea-${saleID}" class="form-label">Details</label>
                                        <textarea class="form-control" id="detailsTextarea-${saleID}" rows="4" required></textarea>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-success" id="saveChangeSaleStatusButton-${saleID}">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Append the modal to body
            $('body').append(modalHtml);

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById(modalId));
            modal.show();

            // Reset the form fields each time it's opened
            $(`#changeSaleStatusForm-${saleID}`)[0].reset();

            // Remove validation classes and feedback
            $(`#detailsTextarea-${saleID}`).removeClass('is-valid is-invalid').next('.invalid-feedback').remove();

            // Handle Save button
            $(`#saveChangeSaleStatusButton-${saleID}`).off('click').on('click', function() {
                const notes = $(`#detailsTextarea-${saleID}`).val().trim();

                let valid = true;

                if (!notes) {
                    $(`#detailsTextarea-${saleID}`).addClass('is-invalid');
                    if ($(`#detailsTextarea-${saleID}`).next('.invalid-feedback').length === 0) {
                        $(`#detailsTextarea-${saleID}`).after(
                            '<div class="invalid-feedback">Please provide details.</div>');
                    }
                    valid = false;
                }

                // Remove validation on input/change
                $(`#detailsTextarea-${saleID}`).on('input', function() {
                    if ($(this).val()) {
                        $(this).removeClass('is-invalid').addClass('is-valid');
                        $(this).next('.invalid-feedback').remove();
                    }
                });

                if (!valid) return;

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                // Send data via AJAX
                $.ajax({
                    url: '{{ route('clear_reject_Sale') }}',
                    type: 'POST',
                    data: {
                        sale_id: saleID,
                        details: notes,
                        status: currentStatus,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Status changed saved successfully!');
                        modal.hide();
                        $(`#changeSaleStatusForm-${saleID}`)[0].reset();
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

            // Optional cleanup when modal is hidden
            $(`#${modalId}`).on('hidden.bs.modal', function() {
                $(this).remove(); // removes the modal from DOM
            });
        }
    </script>
@endsection
@endsection
