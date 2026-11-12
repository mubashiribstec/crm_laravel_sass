@extends('layouts.vertical', ['title' => 'Quality Resources List', 'subTitle' => 'Quality'])
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
                                <!-- Button Dropdown -->
                                @if (!empty($qualityResourceTabs))
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButton4" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-filter-line me-1"></i> <span
                                                id="showFilterStatus">{{ $defaultQualityResourceTabLabel }}</span>
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton4">
                                            @foreach ($qualityResourceTabs as $status => $label)
                                                <a class="dropdown-item status-filter" href="#"
                                                    data-status="{{ $status }}">{{ $label }}</a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
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
                                <!-- Transport Type Filter Dropdown (Cleared CVs only) -->
                                <div class="dropdown" id="transportFilterDropdown" style="display:none;">
                                    <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                        id="dropdownMenuButtonTransport" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="ri-filter-line me-1"></i> <span id="showFilterTransport">All Transport</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButtonTransport">
                                        <a class="dropdown-item transport-filter" href="#" data-transport="">All Transport</a>
                                        <a class="dropdown-item transport-filter" href="#" data-transport="Car">Car</a>
                                        <a class="dropdown-item transport-filter" href="#" data-transport="Public Transport">Public Transport</a>
                                        <a class="dropdown-item transport-filter" href="#" data-transport="By Walk">By Walk</a>
                                        <a class="dropdown-item transport-filter" href="#" data-transport="Cycle">Cycle</a>
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
                        <table id="applicants_table" class="table align-middle mb-3">
                            <thead class="bg-light-subtle">
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Sent By</th>
                                    <th>Name (Applicant)</th>
                                    <th>PostCode (Applicant)</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th width="10%">Phone / Landline</th>
                                    <th>Applicant Resume</th>
                                    <th>CRM Resume</th>
                                    <th>Job Details</th>
                                    <th>Head Office</th>
                                    <th>Unit</th>
                                    <th>PostCode (Sale)</th>
                                    <th>Source (Sale)</th>
                                    <th width="10%">Notes</th>
                                    @if ($canShowSynopsisForms)
                                        <th>Synopsis Form</th>
                                    @endif
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
            if (e.target && e.target.classList.contains('copy-quality-resource-notes-btn')) {
                const targetSelector = e.target.getAttribute('data-copy-quality-resource-notes-target');
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

        $(document).ready(function() {
            // Store the current filter in a variable
            var currentTypeFilter = '';
            var currentFilter = @json($defaultQualityResourceTab ?? '');
            var currentCategoryFilters = [];
            var currentTitleFilters = [];
            var currentSourceFilters = [];
            var currentTransportFilter = '';

            // Transport filter only applies to the Cleared CVs tab
            function toggleTransportFilter() {
                const isCleared = currentFilter === 'cleared cvs';
                $('#transportFilterDropdown').css('display', isCleared ? 'inline' : 'none');
                if (!isCleared) {
                    currentTransportFilter = '';
                    $('#showFilterTransport').text('All Transport');
                }
            }
            toggleTransportFilter();

            // Create loader row
            const loadingRow = `<tr><td colspan="100%" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </td></tr>`;

            // Function to show loader
            function showLoader() {
                $('#applicants_table tbody').empty().append(loadingRow);
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
                    title: 'Date',
                    default: true
                },
                {
                    title: 'Sent By',
                    default: true
                },
                {
                    title: 'Name (Applicant)',
                    default: true
                },
                {
                    title: 'PostCode (Applicant)',
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
                    title: 'Phone / Landline',
                    default: false
                },
                {
                    title: 'Applicant Resume',
                    default: true
                },
                {
                    title: 'CRM Resume',
                    default: false
                },
                {
                    title: 'Job Details',
                    default: true
                },
                {
                    title: 'Head Office',
                    default: false
                },
                {
                    title: 'Unit',
                    default: false
                },
                {
                    title: 'PostCode (Sale)',
                    default: false
                },
                {
                    title: 'Source (Sale)',
                    default: true
                },
                {
                    title: 'Notes',
                    default: true
                },
                @if ($canShowSynopsisForms)
                    {
                        title: 'Synopsis Form',
                        default: true
                    },
                @endif
                {
                    title: 'Action',
                    toggleable: false
                },
            ];

            const COLUMN_VISIBILITY_STORAGE_KEY = 'quality_resources_table_column_visibility_v1';

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
            var table = $('#applicants_table').DataTable({
                processing: false, // Disable default processing state
                serverSide: true, // Enables server-side processing
                ajax: {
                    url: @json(route('getResourcesByTypeAjaxRequest')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        d.status_filter = currentFilter; // Send the current filter value as a parameter
                        d.type_filter =
                            currentTypeFilter; // Send the current filter value as a parameter
                        d.category_filter =
                            currentCategoryFilters; // Send the current filter value as a parameter
                        d.title_filter =
                            (typeof window.getVisibleListingTitleIds === 'function' ? window
                                .getVisibleListingTitleIds() : currentTitleFilters
                                ); // Send the current filter value as a parameter
                        d.source_filter =
                            currentSourceFilters; // Send the current filter value as a parameter
                        d.transport_filter = currentTransportFilter;

                        // Clean up search parameter
                        if (d.search && d.search.value) {
                            d.search.value = d.search.value.toString().trim();
                        }
                    },
                    beforeSend: function() {
                        if (!currentFilter) {
                            $('#applicants_table tbody').empty().html(
                                '<tr><td colspan="100%" class="text-center">You do not have permission to view any quality resource tabs.</td></tr>'
                            );
                            return false;
                        }
                        showLoader();
                    },
                    error: function(xhr) {
                        if (xhr.statusText === 'abort') {
                            return;
                        }
                        const message = xhr.status === 403 ?
                            'You do not have permission to view this tab' :
                            'Failed to load data';
                        console.error('DataTable AJAX error:', xhr.status, xhr.responseJSON);
                        $('#applicants_table tbody').empty().html(
                            '<tr><td colspan="100%" class="text-center">' + message + '</td></tr>'
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
                        data: 'notes_created_at',
                        name: 'notes_created_at'
                    },
                    {
                        data: 'user_name',
                        name: 'users.name'
                    },
                    {
                        data: 'applicant_name',
                        name: 'applicants.applicant_name'
                    },
                    {
                        data: 'applicant_postcode',
                        name: 'applicants.applicant_postcode'
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
                        data: 'applicantPhone',
                        name: 'applicantPhone',
                        orderable: false,
                    },
                    {
                        data: 'applicant_resume',
                        name: 'applicants.applicant_cv',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'crm_resume',
                        name: 'applicants.updated_cv',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'job_details',
                        name: 'job_details',
                        orderable: false,
                        searchable: false
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
                        data: 'sale_job_source',
                        name: 'sale_job_sources.name'
                    },
                    {
                        data: 'notes_detail',
                        name: 'notes_detail',
                        orderable: false,
                        searchable: false
                    },
                    @if ($canShowSynopsisForms)
                        {
                            data: 'draft_file',
                            name: 'draft_file',
                            orderable: false,
                            searchable: false
                        },
                    @endif
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false
                    }
                ],
                columnDefs: [{
                        targets: 8, // Column index for 'job_details'
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
                        targets: 10, // Column index for 'job_details'
                        createdCell: function(td, cellData, rowData, row, col) {
                            $(td).css('text-align', 'center'); // Center the text in this column
                        }
                    },
                    {
                        targets: 13, // Column index for 'job_details'
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
                        $('#applicants_table tbody').html(
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

            // Status filter dropdown handler
            $('.status-filter').on('click', function() {
                currentFilter = ($(this).attr('data-status') || $(this).text()).toString().toLowerCase();
                $('#showFilterStatus').html($(this).text());
                toggleTransportFilter();
                table.ajax.reload();
            });

            // Transport type filter dropdown handler
            $('.transport-filter').on('click', function(e) {
                e.preventDefault();
                currentTransportFilter = $(this).data('transport') || '';
                $('#showFilterTransport').text($(this).text());
                table.ajax.reload();
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

            /*** Category filter handler ***/
            $('.category-filter').on('change', function() {
                const id = $(this).data('category-id');
                // Handle "All Titles"
                if (id === '' || id === undefined) {
                    currentCategoryFilters = [];
                    $('.category-filter').not(this).prop('checked', false);
                } else {
                    // Remove or add to array
                    if (this.checked) {
                        currentCategoryFilters.push(id);
                        // Uncheck "All Titles"
                        $('.category-filter[data-category-id=""]').prop('checked', false);
                    } else {
                        currentCategoryFilters = currentCategoryFilters.filter(x => x !== id);
                    }
                }

                // Update dropdown display text and toggle visibility
                const total = $('.category-filter').not('[data-category-id=""]').length;
                const checked = $('.category-filter:checked').not('[data-category-id=""]').length;

                $('#showFilterCategory').text(checked > 0 ? `Selected Categories (${checked})` :
                    'All Categories');

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

                $('#showFilterTitle').text(checked > 0 ? `Selected Titles (${checked})` : 'All Titles');

                const container = $('#titleToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

                // Trigger DataTable reload with the selected filters
                table.ajax.reload();
            });

            /*** Source Filter Handler ***/
            $('.source-filter').on('change', function() {
                const id = $(this).data('source-id');

                if (id === '' || id === undefined) {
                    currentSourceFilters = [];
                    $('.source-filter').not(this).prop('checked', false);
                } else {
                    if (this.checked) {
                        currentSourceFilters.push(id);
                        $('.source-filter[data-source-id=""]').prop('checked', false);
                    } else {
                        currentSourceFilters = currentSourceFilters.filter(x => x !== id);
                    }
                }

                const total = $('.source-filter').not('[data-source-id=""]').length;
                const checked = $('.source-filter:checked').not('[data-source-id=""]').length;

                $('#showFilterSource').text(checked > 0 ? `Selected Sources (${checked})` : 'All Sources');

                const container = $('#sourceToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);

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
                $('#applicants_table').DataTable().page(page - 1).draw('page');
                input.classList.remove('is-invalid');
            } else {
                input.classList.add('is-invalid');
            }
        }

        // Function to move the page forward or backward
        function movePage(page) {
            var table = $('#applicants_table').DataTable();
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

        // Basic HTML sanitizer function (you might want to use a library like DOMPurify in production)
        function sanitizeHtml(html, options) {
            if (!html) return '';

            // Create a temporary div
            const temp = document.createElement('div');
            temp.innerHTML = html;

            // Remove any unwanted tags
            const elements = temp.querySelectorAll('*');
            for (let el of elements) {
                // Remove if tag is not allowed
                if (!options.allowedTags.includes(el.tagName.toLowerCase())) {
                    el.parentNode.removeChild(el);
                }
                // Remove disallowed attributes
                else {
                    const attributes = el.attributes;
                    for (let i = attributes.length - 1; i >= 0; i--) {
                        const attrName = attributes[i].name.toLowerCase();
                        if (!options.allowedAttributes[el.tagName.toLowerCase()] ||
                            !options.allowedAttributes[el.tagName.toLowerCase()].includes(attrName)) {
                            el.removeAttribute(attrName);
                        }
                    }
                }
            }

            return temp.innerHTML;
        }

        // Function to show the notes modal
        function clearCVModal(applicantID, saleID, status, modalName) {
            const modalID = 'clearCVModal' + applicantID + '-' + saleID;
            const formID = 'clearCVForm' + applicantID + '-' + saleID;

            // Add the modal HTML to the page (only once, if not already present)
            if ($('#' + modalID).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalID +
                    '" tabindex="-1" aria-labelledby="clearCVModalLabel" aria-hidden="true">' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="clearCVModalLabel' + applicantID + '-' + saleID + '">' + modalName +
                    '</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="' + formID + '">' +
                    '<div class="mb-3">' +
                    '<label for="detailsTextarea' + applicantID + '-' + saleID +
                    '" class="form-label">Details</label>' +
                    '<textarea class="form-control" id="detailsTextarea' + applicantID + '-' + saleID +
                    '" rows="4" required></textarea>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-success" id="saveClearCVButton' + applicantID + '-' + saleID +
                    '">' +
                    'Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Reset the form whenever modal is opened
            function resetModal() {
                $('#' + formID)[0].reset();
                $('#detailsTextarea' + applicantID + '-' + saleID).removeClass('is-invalid is-valid');
                $('#detailsTextarea' + applicantID + '-' + saleID).next('.invalid-feedback').remove();
            }

            // Set up modal events
            $('#' + modalID)
                .off('show.bs.modal') // Remove previous handlers to avoid duplicates
                .on('show.bs.modal', function() {
                    resetModal();
                    $('#clearCVModalLabel' + applicantID + '-' + saleID).text(
                        modalName); // Update title in case it changed
                })
                .off('hidden.bs.modal') // Remove previous handlers
                .on('hidden.bs.modal', resetModal);

            // Show the modal
            $('#' + modalID).modal('show');

            // Handle the save button click
            $('#saveClearCVButton' + applicantID + '-' + saleID).off('click').on('click', function() {
                const notes = $('#detailsTextarea' + applicantID + '-' + saleID).val();

                if (!notes) {
                    if (!notes) {
                        $('#detailsTextarea' + applicantID + '-' + saleID).addClass('is-invalid');
                        if ($('#detailsTextarea' + applicantID + '-' + saleID).next('.invalid-feedback').length ===
                            0) {
                            $('#detailsTextarea' + applicantID + '-' + saleID).after(
                                '<div class="invalid-feedback">Please provide details.</div>');
                        }
                    }

                    // Add event listeners to remove validation errors dynamically
                    $('#detailsTextarea' + applicantID + '-' + saleID).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Remove validation errors if inputs are valid
                $('#detailsTextarea' + applicantID + '-' + saleID).removeClass('is-invalid').addClass('is-valid');
                $('#detailsTextarea' + applicantID + '-' + saleID).next('.invalid-feedback').remove();

                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
                );

                // Send the data via AJAX
                $.ajax({
                    url: '{{ route('updateApplicantStatusByQuality') }}', // Replace with your endpoint
                    type: 'POST',
                    data: {
                        applicant_id: applicantID,
                        sale_id: saleID,
                        details: notes,
                        status: status,
                        _token: '{{ csrf_token() }}' // Directly include token in data
                    },
                    success: function(response) {
                        toastr.success(response.message);
                        $('#' + modalID).modal('hide'); // Close the modal
                        $('#' + formID)[0].reset(); // Clear the form
                        $('#detailsTextarea' + applicantID + '-' + saleID).removeClass(
                            'is-valid'); // Remove valid class
                        $('#detailsTextarea' + applicantID + '-' + saleID).next('.invalid-feedback')
                            .remove(); // Remove error message

                        $('#applicants_table').DataTable().ajax.reload(); // Reload the DataTable
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
        function viewNotesHistory(applicantID, saleID) {
            const modalId = 'viewNotesHistoryModal-' + applicantID + '-' + saleID;

            // Add the modal only once
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label" aria-hidden="true">' +
                    '<div class="modal-dialog modal-dialog-scrollable modal-dialog-top modal-lg">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Quality Notes History</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body text-center">' +
                    '<div class="spinner-border text-primary" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
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

            // Show the modal and display loader immediately
            $('#' + modalId).modal('show');
            $('#' + modalId + ' .modal-body').html(
                '<div class="text-center py-4">' +
                '<div class="spinner-border text-dark" role="status">' +
                '<span class="visually-hidden">Loading...</span>' +
                '</div>' +
                '</div>'
            );

            // Make an AJAX call to retrieve notes history data
            $.ajax({
                url: '{{ route('getQualityNotesHistory') }}',
                type: 'GET',
                data: {
                    applicant_id: applicantID,
                    sale_id: saleID,
                },
                success: function(response) {
                    let notesHtml = '';

                    if (response.data.length === 0) {
                        notesHtml = '<p>No record found.</p>';
                    } else {
                        response.data.forEach(function(note) {
                            const notes = note.details;
                            const created = moment(note.created_at).format('DD MMM YYYY, h:mmA');
                            const status = note.status;
                            const movedTab = note.moved_tab_to ?
                                note.moved_tab_to.charAt(0).toUpperCase() + note.moved_tab_to.slice(1)
                                .toLowerCase() :
                                'Unknown';

                            const statusClass = (status == 1) ? 'bg-success' : 'bg-dark';
                            const statusText = (status == 1) ? 'Active' : 'Inactive';

                            notesHtml +=
                                '<div class="note-entry text-start">' +
                                '<p><strong>Dated:</strong> ' + created + '&nbsp;&nbsp;' +
                                '<span class="badge ' + statusClass + '">' + statusText +
                                '</span>&nbsp;&nbsp;' +
                                '<span class="badge bg-primary">Moved To: ' + movedTab + '</span></p>' +
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

        /** Function to show the manager details modal */
        function viewManagerDetails(id) {
            const unitId = parseInt(id, 10) || 0;
            if (unitId <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No unit linked',
                    text: 'This sale does not have a unit, so manager details are unavailable.',
                });
                return;
            }

            const modalID = 'viewManagerDetailsModal-' + unitId;
            window.managerDetailsModalID = modalID;

            // Create modal if it doesn't exist
            if ($('#' + modalID).length === 0) {
                $('body').append(`
                    <div class="modal fade" id="${modalID}" tabindex="-1" aria-labelledby="viewManagerDetailsModalLabel-${unitId}">
                        <div class="modal-dialog modal-dialog-scrollable modal-dialog-top modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="viewManagerDetailsModalLabel-${unitId}">Manager Details</h5>
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
            } else {
                // Reset to loading state when reopening an existing modal
                $('#' + modalID + ' .modal-body').html(`
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
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

                    $('#' + modalID + ' .modal-body').html(
                        '<p class="text-danger">' + message + '</p>'
                    );
                }
            });
        }

        // Contact filter radios (All / Kingsburry / Others) are only for users
        // who can see private data — same Gate used by getModuleContacts().
        const canShowPrivateData = @json(auth()->user()?->can('show-private-data') ?? false);

        $(document).on('change', 'input[name="contact_filter"]', function() {
            if (!canShowPrivateData) {
                return;
            }
            renderContacts($(this).val());
        });

        function renderContacts(filterType) {
            var contacts = window.managerContacts || [];
            var modalID = window.managerDetailsModalID;
            if (!modalID) {
                return;
            }

            // Without show-private-data permission, always show the full (already
            // server-filtered) list and never render the contact_filter radios.
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

            $('#' + modalID + ' .modal-body').html(contactHtml);
        }

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
                                    <tr><th>Sale ID #</th><td>${job.sale_id}</td></tr>
                                    <tr><th>Head Office</th><td>${job.office_name}</td></tr>
                                    <tr><th>Unit Name</th><td>${job.unit_name}</td></tr>
                                    <tr><th>Postcode</th><td>${job.postcode}</td></tr>
                                    <tr><th>Job Category</th><td>${job.job_category}</td></tr>
                                    <tr><th>Job Title</th><td>${job.job_title}</td></tr>
                                    <tr><th>Job Source</th><td>${job.job_source}</td></tr>
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

        let applicantId = null; // Store applicant ID

        function triggerCrmFileInput(id) {
            // Store the applicant ID when the button is clicked
            applicantId = id;

            // Trigger the file input click event
            document.getElementById('crmfileInput').click();
        }

        function crmuploadFile() {
            const fileInput = document.getElementById('crmfileInput');
            const file = fileInput.files[0]; // Get the selected file

            if (file && applicantId) {
                // Create a FormData object to send the file along with the applicant ID
                const formData = new FormData();
                formData.append('resume', file);
                formData.append('applicant_id', applicantId); // Append applicant ID

                // Include CSRF token if you're using Laravel or any framework that requires CSRF protection
                formData.append('_token', '{{ csrf_token() }}'); // CSRF token

                // You can send the file to the server using an AJAX request or any method you prefer
                // Example using Fetch API
                fetch('{{ route('applicants.crmuploadCv') }}', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            // If needed, add other headers here (like Authorization headers if you're using token-based auth)
                            //'Authorization': 'Bearer ' + YOUR_TOKEN // Uncomment if needed
                        }
                    })
                    .then(response => response.json()) // Assuming the server returns JSON
                    .then(data => {
                        if (data.success) {
                            toastr.success('File uploaded successfully');
                            $('#applicants_table').DataTable().ajax.reload(); // Reload the DataTable
                        } else {
                            toastr.error('Error:', data.message);
                        }
                    })
                    .catch(error => {
                        toastr.error('Error uploading file:', error);
                    });
            } else {
                toastr.error('No file selected or applicant ID missing.');
            }
        }

        function triggerFileInput(id) {
            // Store the applicant ID when the button is clicked
            applicantId = id;

            // Trigger the file input click event
            document.getElementById('fileInput').click();
        }

        function uploadFile() {
            const fileInput = document.getElementById('fileInput');
            const file = fileInput.files[0]; // Get the selected file

            if (file && applicantId) {
                // Create a FormData object to send the file along with the applicant ID
                const formData = new FormData();
                formData.append('resume', file);
                formData.append('applicant_id', applicantId); // Append applicant ID

                // Include CSRF token if you're using Laravel or any framework that requires CSRF protection
                formData.append('_token', '{{ csrf_token() }}'); // CSRF token

                // You can send the file to the server using an AJAX request or any method you prefer
                // Example using Fetch API
                fetch('{{ route('applicants.uploadCv') }}', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            // If needed, add other headers here (like Authorization headers if you're using token-based auth)
                            //'Authorization': 'Bearer ' + YOUR_TOKEN // Uncomment if needed
                        }
                    })
                    .then(response => response.json()) // Assuming the server returns JSON
                    .then(data => {
                        if (data.success) {
                            toastr.success('File uploaded successfully');
                            $('#applicants_table').DataTable().ajax.reload(); // Reload the DataTable
                        } else {
                            toastr.error('Error:', data.message);
                        }
                    })
                    .catch(error => {
                        toastr.error('Error uploading file:', error);
                    });
            } else {
                toastr.error('No file selected or applicant ID missing.');
            }
        }
    </script>
@endsection
@endsection
