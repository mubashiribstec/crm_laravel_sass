@extends('layouts.vertical', ['title' => 'Dashboard', 'subTitle' => 'Dashboard'])

@section('content')
    <!-- start page title -->
    <style>
        .logo-box {
            padding-top: 21px;
            padding-bottom: 19px;
        }

        .card {
            margin-bottom: 1.5625rem;
            box-shadow: 0 0.5rem 3.25rem rgba(0, 0, 0, 0.05);
        }

        .collapse {
            visibility: visible;
        }

        /* ApexCharts legend in 4 columns */
        #statisticsChart .apexcharts-legend {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px 12px;
            justify-items: start;
        }

        /* Each legend item */
        #statisticsChart .apexcharts-legend-series {
            margin: 0 !important;
            padding: 2px 0;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            #statisticsChart .apexcharts-legend {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            #statisticsChart .apexcharts-legend {
                grid-template-columns: 1fr;
            }
        }

        .stat-box:hover,
        .clickable:hover {
            cursor: pointer;
        }

        .stat-box span:hover {
            color: var(--bs-info) !important;
        }

        #statusDetailsLabel {
            font-size: 18px;
            font-weight: 500;
        }

        .offcanvas {
            transition: transform .3s ease-in-out;
        }

        .offcanvas.show {
            z-index: 1080;
        }

        .offcanvas-backdrop {
            z-index: 1075;
        }
    </style>
    @canany(['dashboard-top-stats'])
        <div class="row">
            <div class="col-md-3 col-xl-3">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium">Total Applicants</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="applicantsCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:user-plus-broken"
                                        class="fs-32 text-primary avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-xl-3">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium">Total Head Offices</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="officesCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-warning bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:buildings-2-bold-duotone"
                                        class="fs-32 text-warning avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-xl-3">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium d-flex align-items-center gap-2">Total Units</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="unitsCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-success bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:graph-new-broken"
                                        class="fs-32 text-success avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-xl-3">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium">Total Open Sales</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="salesCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-info bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:chart-2-broken"
                                        class="fs-32 text-info avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 col-xl-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium">Last 7 Days Applicants</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="last7DaysCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-info bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:calendar-date-broken"
                                        class="fs-32 text-info avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-xl-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium">Last 21 Days Applicants</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="last21DaysCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:calendar-date-broken"
                                        class="fs-32 text-primary avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-xl-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-2 fs-15 fw-medium d-flex align-items-center gap-2">Last 3 Months Applicants</p>
                                <h3 class="text-dark fw-bold d-flex align-items-center gap-2 mb-0 fs-22" id="last3MonthsCount">
                                    <span class="skeleton-loader w-16 h-8 rounded animate-pulse bg-gray-200"></span>
                                </h3>
                            </div>
                            <div>
                                <div class="avatar-md bg-success bg-opacity-10 rounded">
                                    <iconify-icon icon="solar:calendar-date-broken"
                                        class="fs-32 text-success avatar-title"></iconify-icon>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcanany
    @canany(['dashboard-top-stats'])
        <div class="row">
            <div class="col-xl-7 col-lg-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center pb-1">
                        <div>
                            <h4 class="card-title mb-0">Statistics</h4>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <!-- Stats Range Filter -->
                            <div class="dropdown">
                                <a href="#" id="statsRangeBtn"
                                    class="dropdown-toggle btn btn-sm btn-outline-light rounded" data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                    Daily
                                </a>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a href="#!" class="dropdown-item stats-filter active" data-range="daily">Daily</a>
                                    <a href="#!" class="dropdown-item stats-filter" data-range="weekly">Weekly</a>
                                    <a href="#!" class="dropdown-item stats-filter" data-range="monthly">Monthly</a>
                                    <a href="#!" class="dropdown-item stats-filter" data-range="yearly">Yearly</a>
                                    <a href="#!" class="dropdown-item stats-filter" data-range="aggregate">Aggregate</a>
                                </div>
                            </div>

                            <!-- Adaptive Calendar -->
                            <input type="text" id="statsDateRange" class="form-control form-control-sm"
                                placeholder="Select date" style="width: 220px;" />
                        </div>
                    </div>
                    <div class="card-body p-2 mx-3">
                        <!-- Applicants Statistics Created -->
                        <div class="row">
                            <h6 class="mt-3 mb-1">Applicants Statistics (Created)</h6>
                            <hr>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:stethoscope-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center stat-box" data-type="nurses"
                                        data-filter-type="nurses-created">
                                        <span class="fs-4 fw-bold text-primary stats-nurses-created"></span>
                                        <small class="text-muted">Nurses</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:user-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center stat-box" data-type="non_nurses"
                                        data-filter-type="non-nurses-created">
                                        <span class="fs-4 fw-bold text-primary stats-non-nurses-created"></span>
                                        <small class="text-muted">Non Nurses</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:history-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="callbacks">
                                        <span class="fs-4 fw-bold text-primary stats-callbacks-created"></span>
                                        <small class="text-muted">Callbacks</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:forbidden-circle-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="not_interested">
                                        <span class="fs-4 fw-bold text-primary stats-not-interested-created"></span>
                                        <small class="text-muted">Not Interested</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Applicants Statistics Updated -->
                        <div class="row">
                            <h6 class="mt-3 mb-1">Applicants Statistics (Updated)</h6>
                            <hr>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:stethoscope-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center stat-box" data-type="nurses"
                                        data-filter-type="nurses-updated">
                                        <span class="fs-4 fw-bold text-primary stats-nurses-updated"></span>
                                        <small class="text-muted">Nurses</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:user-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center stat-box" data-type="non_nurses"
                                        data-filter-type="non-nurses-updated">
                                        <span class="fs-4 fw-bold text-primary stats-non-nurses-updated"></span>
                                        <small class="text-muted">Non Nurses</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:history-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="callbacks">
                                        <span class="fs-4 fw-bold text-primary stats-callbacks-updated"></span>
                                        <small class="text-muted">Callbacks</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:forbidden-circle-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="not_interested">
                                        <span class="fs-4 fw-bold text-primary stats-not-interested-updated"></span>
                                        <small class="text-muted">Not Interested</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Sales Statistics Created -->
                        <div class="row">
                            <h6 class="mt-2 mb-1">Sales Statistics (Created)</h6>
                            <hr>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:bag-line-duotone" class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="open_sale">
                                        <span class="fs-4 fw-bold text-primary stats-open-created"></span>
                                        <small class="text-muted">Open</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:bag-cross-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="close_sale">
                                        <span class="fs-4 fw-bold text-primary stats-close-created"></span>
                                        <small class="text-muted">Close</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:hourglass-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="pending_sale">
                                        <span class="fs-4 fw-bold text-primary stats-pending-created"></span>
                                        <small class="text-muted">Pending</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:shield-cross-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="rejected_sale">
                                        <span class="fs-4 fw-bold text-primary stats-rejected-created"></span>
                                        <small class="text-muted">Rejected</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Sales Statistics Updated -->
                        <div class="row">
                            <h6 class="mt-2 mb-1">Sales Statistics (Updated)</h6>
                            <hr>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center rounded p-3 h-100 bg-light-primary border-0">
                                    <iconify-icon icon="solar:bag-line-duotone" class="fs-1 text-primary me-3"></iconify-icon>

                                    <div class="d-flex w-100">
                                        <div class="d-flex flex-column justify-content-center border-end pe-3"
                                            data-type="open_sale_updated" style="flex: 1;">
                                            <span class="fs-4 fw-bold text-primary stats-open-updated">0</span>
                                            <small class="text-muted fw-semibold">Open</small>
                                        </div>

                                        <div class="d-flex flex-column justify-content-center ps-3"
                                            data-type="reopen_sale_updated" style="flex: 1;">
                                            <span class="fs-4 fw-bold text-primary stats-reopen-updated">0</span>
                                            <small class="text-muted fw-semibold">ReOpen</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:bag-cross-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="close_sale">
                                        <span class="fs-4 fw-bold text-primary stats-close-updated"></span>
                                        <small class="text-muted">Close</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:hourglass-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="pending_sale">
                                        <span class="fs-4 fw-bold text-primary stats-pending-updated"></span>
                                        <small class="text-muted">Pending</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:shield-cross-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="rejected_sale">
                                        <span class="fs-4 fw-bold text-primary stats-rejected-updated"></span>
                                        <small class="text-muted">Rejected</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Quality Statistics -->
                        <div class="row">
                            <h6 class="mt-2 mb-1">Quality Statistics</h6>
                            <hr>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:clipboard-check-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="requested_cvs">
                                        <span class="fs-4 fw-bold text-primary stats-requested-cvs"></span>
                                        <small class="text-muted">Requested CVs</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:folder-open-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="open_cvs">
                                        <span class="fs-4 fw-bold text-primary stats-open-cvs"></span>
                                        <small class="text-muted">Open CVs</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:shield-cross-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="rejected_cvs">
                                        <span class="fs-4 fw-bold text-primary stats-rejected-cvs"></span>
                                        <small class="text-muted">Rejected CVs</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center  rounded p-3 h-100">
                                    <iconify-icon icon="solar:shield-check-line-duotone"
                                        class="fs-1 text-primary me-3"></iconify-icon>
                                    <div class="d-flex flex-column justify-content-center" data-type="cleared_sale">
                                        <span class="fs-4 fw-bold text-primary stats-cleared-cvs"></span>
                                        <small class="text-muted">Cleared CVs</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5 col-lg-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center py-3">
                        <div class="pb-1">
                            <h4 class="card-title mb-0">CRM Statistics Chart</h4>
                        </div>
                    </div>
                    <div class="card-body d-flex py-4"> <!-- fixed min-height -->
                        <div id="statisticsChart" style="flex: 1; min-width: 600px;"></div>
                    </div>
                </div>
            </div>
        </div>
    @endcanany
    @canany(['dashboard-sales-analytics-chart', 'dashboard-sales-weekly-analytics'])
        <div class="row">
            @canany(['dashboard-sales-analytics-chart'])
                <div class="col-xl-9 col-lg-8">
                    <div class="card overflow-hidden">
                        <div class="card-header d-flex justify-content-between align-items-center pb-1">
                            <div>
                                <h4 class="card-title mb-0">Sales Analytics</h4>
                            </div>
                            <div class="dropdown">
                                <a href="#" class="dropdown-sale-toggle btn btn-sm btn-outline-light rounded"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                    This Month
                                </a>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a href="#!" class="dropdown-item chart-sale-filter" data-sale-range="month">Month</a>
                                    <a href="#!" class="dropdown-item chart-sale-filter" data-sale-range="year">Year</a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-2" style="height: 420px;">
                            <div id="sales_analytic" class="w-100 h-100"></div>
                        </div>
                    </div>
                </div>
            @endcanany
            @canany(['dashboard-sales-weekly-analytics'])
                <div class="col-xl-3 col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Weekly New Sales</h4>
                        </div>
                        <div class="card-body" style="min-height: 360px;">
                            <div id="sales_chart" class="apex-charts"></div>
                        </div>
                        <div class="card-footer border-top d-flex align-items-center justify-content-between">
                            <p id="weeklySalesText" class="text-muted fw-medium fs-15 mb-0">
                                <span class="text-dark me-1">Total Sales :</span> <span id="weeklySalesCount">0</span>
                            </p>
                            <div>
                                <a href="#!" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                                    data-bs-target="#weeklySalesModal">View More</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endcanany
        </div>

        <!-- Modal -->
        <div class="modal fade" id="weeklySalesModal" tabindex="-1" aria-labelledby="weeklySalesModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Weekly Sales Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="weeklySalesDetails">
                        <p>Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    @endcanany
    @canany(['dashboard-users'])
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="card-title">All Users</h4>
                        </div>
                        <!-- Date Range filter -->
                        <div class="d-inline">
                            <input type="text" id="dateRangePicker" class="form-control d-inline-block"
                                style="width: 220px; display: inline-block;" placeholder="Select date range" readonly />
                            <button class="btn btn-outline-primary my-1" type="button" id="clearDateRange"
                                title="Clear Date Range">
                                <i class="ri-close-line"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body px-3">
                        <div class="table-responsive">
                            <table id="users_table" class="table align-middle text-nowrap table-hover table-centered mb-3">
                                <thead class="bg-light-subtle">
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Created At</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="100%" class="text-center py-4">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcanany

    <!-- Modal -->
    <div class="modal fade" id="applicantDetailsModal" tabindex="-1" aria-labelledby="applicantDetailsLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-top">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="applicantDetailsLabel">Applicants Detail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Job Type Summary -->
                    <div class="row mb-4 text-center">
                        <div class="col-md-6">
                            <h6>Regular</h6>
                            <h3 id="regularCount">0</h3>
                        </div>
                        <div class="col-md-6">
                            <h6>Specialist</h6>
                            <h3 id="specialistCount">0</h3>
                        </div>
                    </div>

                    <!-- Job Source Breakdown -->
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Job Source</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody id="jobSourceBreakdown">
                            <tr>
                                <td colspan="2" class="text-center">No data available</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-top">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="statusDetailsLabel" data-crm-status=""></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row text-center g-3">
                        <div class="col-md-3 col-6 mb-3">
                            <h6>Nurses (Regular)</h6>
                            <span id="nursesRegularCount" class="fs-3 fw-bold text-primary clickable"
                                data-category="nurses" data-type="regular">0</span>
                        </div>

                        <div class="col-md-3 col-6 mb-3">
                            <h6>Nurses (Specialist)</h6>
                            <span id="nursesSpecialistCount" class="fs-3 fw-bold text-primary clickable"
                                data-category="nurses" data-type="specialist">0</span>
                        </div>

                        <div class="col-md-3 col-6 mb-3">
                            <h6>Non Nurses (Regular)</h6>
                            <span id="nonNursesRegularCount" class="fs-3 fw-bold text-secondary clickable"
                                data-category="non_nurses" data-type="regular">0</span>
                        </div>

                        <div class="col-md-3 col-6 mb-3">
                            <h6>Non Nurses (Specialist)</h6>
                            <span id="nonNursesSpecialistCount" class="fs-3 fw-bold text-secondary clickable"
                                data-category="non_nurses" data-type="specialist">0</span>
                        </div>

                    </div>

                    <div id="rejectionReasonsSection" class="d-none">
                        <hr class="my-4">
                        <h6 class="text-left mb-3">Rejection Reasons</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>Reason</th>
                                        <th class="text-center">Nurses (Regular)</th>
                                        <th class="text-center">Nurses (Specialist)</th>
                                        <th class="text-center">Non Nurses (Regular)</th>
                                        <th class="text-center">Non Nurses (Specialist)</th>
                                        <th class="text-center">Total</th>
                                    </tr>
                                </thead>
                                <tbody id="rejectionReasonStats"></tbody>
                                <tfoot id="rejectionReasonTotals" class="table-light fw-bold d-none">
                                    <tr>
                                        <td>Total</td>
                                        <td class="text-center" id="reasonSumNursesRegular">0</td>
                                        <td class="text-center" id="reasonSumNursesSpecialist">0</td>
                                        <td class="text-center" id="reasonSumNonNursesRegular">0</td>
                                        <td class="text-center" id="reasonSumNonNursesSpecialist">0</td>
                                        <td class="text-center" id="reasonSumTotal">0</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-left mb-3">Job Sources</h6>

                    <div class="row g-2" id="jobSourceStats">
                        <!-- Filled dynamically -->
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <!-- jQuery CDN -->
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="{{ asset('css/jquery.dataTables.min.css') }}">

    <!-- DataTables JS -->
    <script src="{{ asset('js/jquery.dataTables.min.js') }}"></script>

    <!-- DataTables Buttons Extension CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

    <!-- DataTables Buttons Extension JS -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>

    <!-- JSZip (for Excel export) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

    <!-- PDFMake (for PDF export) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.min.js"></script>

    <!-- DataTables HTML5 Button Extension (Excel/PDF) -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

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

    <!-- Tailwind CSS CDN (for skeleton loader styling) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Daterangepicker CSS/JS -->
    <link rel="stylesheet" href="{{ asset('css/daterangepicker.css') }}" />
    <script src="{{ asset('js/daterangepicker.min.js') }}"></script>

    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <!-- Month Select -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">

    <!-- Include ApexCharts -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <script>
        $(document).ready(function() {
            // Deferred AJAX for counts
            const fetchCounts = $.ajax({
                url: "{{ route('dashboard.counts') }}",
                method: "GET",
                dataType: "json" // Explicitly expect JSON response
            });

            // Use $.when to handle deferred AJAX requests
            $.when(fetchCounts).done(function(countsResponse) {
                // Log the response for debugging
                console.log('Counts Response:', countsResponse);

                // Handle both array and object responses
                const data = Array.isArray(countsResponse) && countsResponse.length > 0 ? countsResponse[
                    0] : countsResponse;

                // Check if the expected properties exist, default to 0 if undefined
                $('#applicantsCount').html(data.applicantsCount !== undefined ? data.applicantsCount : 0);
                $('#officesCount').html(data.officesCount !== undefined ? data.officesCount : 0);
                $('#unitsCount').html(data.unitsCount !== undefined ? data.unitsCount : 0);
                $('#salesCount').html(data.salesCount !== undefined ? data.salesCount : 0);
                $('#last7DaysCount').html(data.last7DaysCount !== undefined ? data.last7DaysCount : 0);
                $('#last21DaysCount').html(data.last21DaysCount !== undefined ? data.last21DaysCount : 0);
                $('#last3MonthsCount').html(data.last3MonthsCount !== undefined ? data.last3MonthsCount :
                    0);
            }).fail(function(xhr, status, error) {
                console.error('Error fetching counts:', xhr.responseText, status, error);
                $('#applicantsCount, #officesCount, #unitsCount, #salesCount, #last7DaysCount, #last21DaysCount, #last3MonthsCount')
                    .html('<span class="text-danger">Error</span>');
            });
        });

        let currentRange = 'daily';
        let currentDateRange = null;
        let fp = null;

        const dateInput = document.getElementById("statsDateRange");
        const rangeBtn = document.getElementById("statsRangeBtn");

        // Correct syntax for calling:
        loadStatsBoxes('daily');
        loadChartData('daily');
        initFlatpickr("daily");

        /*** statistics data **/
        function loadStatsBoxes(range, dateRange = null) {
            // If dateRange is null, use today's date in 'YYYY-MM-DD' format
            if (!dateRange) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0'); // months are 0-indexed
                const dd = String(today.getDate()).padStart(2, '0');
                dateRange = `${yyyy}-${mm}-${dd}`;
            }

            $.get('/dashboard/statistics-data', {
                range: range,
                date_range: dateRange
            }, function(resp) {

                $('.stats-nurses-created').text(resp.applicants?.nurses.created ?? 0);
                $('.stats-non-nurses-created').text(resp.applicants?.non_nurses.created ?? 0);
                $('.stats-callbacks-created').text(resp.applicants?.callbacks.created ?? 0);
                $('.stats-not-interested-created').text(resp.applicants?.not_interested.created ?? 0);

                $('.stats-nurses-updated').text(resp.applicants?.nurses.updated ?? 0);
                $('.stats-non-nurses-updated').text(resp.applicants?.non_nurses.updated ?? 0);
                $('.stats-callbacks-updated').text(resp.applicants?.callbacks.updated ?? 0);
                $('.stats-not-interested-updated').text(resp.applicants?.not_interested.updated ?? 0);

                $('.stats-open-created').text(resp.sales?.open.created ?? 0);
                $('.stats-close-created').text(resp.sales?.close.created ?? 0);
                $('.stats-pending-created').text(resp.sales?.pending.created ?? 0);
                $('.stats-rejected-created').text(resp.sales?.rejected.created ?? 0);

                $('.stats-open-updated').text(resp.sales?.open.updated ?? 0);
                $('.stats-reopen-updated').text(resp.sales?.reopen ?? 0);
                $('.stats-close-updated').text(resp.sales?.close.updated ?? 0);
                $('.stats-pending-updated').text(resp.sales?.pending.updated ?? 0);
                $('.stats-rejected-updated').text(resp.sales?.rejected.updated ?? 0);

                $('.stats-requested-cvs').text(resp.quality?.requested_cvs ?? 0);
                $('.stats-open-cvs').text(resp.quality?.open_cvs ?? 0);
                $('.stats-rejected-cvs').text(resp.quality?.rejected_cvs ?? 0);
                $('.stats-cleared-cvs').text(resp.quality?.cleared_cvs ?? 0);
            });
        }

        // When any stats box is clicked
        $(document).on('click', '.stat-box', function() {
            const type = $(this).data('filter-type');
            if (!type) {
                console.warn('⚠️ Missing data-filter-type on this .stat-box element.');
                return;
            }

            $.ajax({
                url: '/dashboard/statistics-details',
                method: 'GET',
                data: {
                    type: type,
                    range: currentRange,
                    date_range: currentDateRange
                },
                success: function(resp) {
                    // Set modal title
                    $('#applicantDetailsLabel').text(resp.title);

                    // Set counts
                    $('#regularCount').text(resp.job_types.regular ?? 0);
                    $('#specialistCount').text(resp.job_types.specialist ?? 0);

                    // Fill Job Source Breakdown
                    const tbody = $('#jobSourceBreakdown');
                    tbody.empty();

                    if (resp.sources && Object.keys(resp.sources).length > 0) {
                        $.each(resp.sources, function(name, count) {
                            tbody.append(`<tr><td>${name}</td><td>${count}</td></tr>`);
                        });
                    } else {
                        tbody.html(
                            '<tr><td colspan="2" class="text-center">No data available</td></tr>');
                    }

                    // Show modal
                    $('#applicantDetailsModal').modal('show');
                },
                error: function(xhr) {
                    console.error('Error:', xhr.responseText);
                }
            });
        });

        let chart;

        function loadChartData(range = 'daily', dateRange = null) {
            // ✅ If no date selected, use today (d-m-Y)
            if (!dateRange) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0'); // months are 0-indexed
                const dd = String(today.getDate()).padStart(2, '0');
                dateRange = `${yyyy}-${mm}-${dd}`;
            }

            $.get('/statistics/chart-data', {
                range: range,
                date_range: dateRange
            }, function(response) {
                if (response.series?.length) {
                    chart.updateOptions({
                        labels: response.labels
                    });
                    chart.updateSeries(response.series);
                } else {
                    chart.updateSeries([]);
                }
            });
        }

        function todayYmd() {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd}`;
        }

        document.addEventListener("DOMContentLoaded", function() {
            // Default UI
            currentRange = 'daily';
            currentDateRange = currentDateRange || todayYmd();
            rangeBtn.innerText = 'Daily';

            document.querySelectorAll('.stats-filter').forEach(el => el.classList.remove('active'));
            document.querySelector('[data-range="daily"]').classList.add('active');

            // Init calendar
            initFlatpickr('daily');

            const chartStatusMap = {
                0: 'crm_sent_cvs',
                1: 'crm_open_cvs',
                2: 'crm_rejected',
                3: 'crm_requested',
                4: 'crm_request_rejected',
                5: 'crm_confirmed',
                6: 'crm_rebook',
                7: 'crm_prestart_attended',
                8: 'crm_declined',
                9: 'crm_not_attended',
                10: 'crm_date_started',
                11: 'crm_start_date_hold',
                12: 'crm_invoiced',
                13: 'crm_disputed',
                14: 'crm_paid',
                15: 'crm_revert',
                16: 'quality_revert'
            };

            // Init chart
            chart = new ApexCharts(
                document.querySelector("#statisticsChart"), {
                    chart: {
                        type: 'donut',
                        height: 680,
                        events: {
                            dataPointSelection: function(event, chartContext, config) {
                                const index = config.dataPointIndex;
                                const statusKey = chartStatusMap[index];

                                if (!statusKey) return;

                                loadStatusDetails(statusKey);
                            }
                        }
                    },

                    series: [],
                    labels: [],

                    // 🎨 Your custom colors
                    colors: [
                        "#4B70E2",
                        "#E57373",
                        "#81C784",
                        "#FFD54F",
                        "#BA68C8",
                        "#4DD0E1",
                        "#F06292",
                        "#9575CD",
                        "#4DB6AC",
                        "#7986CB",
                        "#A1887F",
                        "#64B5F6",
                        "#E0A96D",
                        "#90A4AE",
                        "#FFB74D",
                        "#AED581",
                        "#FBC02D"
                    ],

                    noData: {
                        text: 'Loading chart data...'
                    },
                    dataLabels: {
                        enabled: false
                    },

                    legend: {
                        position: 'bottom',
                        fontSize: '13px',
                        markers: {
                            width: 12,
                            height: 12,
                            radius: 12
                        },
                        formatter: function(val, opts) {
                            const value = opts.w.globals.series[opts.seriesIndex] || 0;
                            return `${val} - ${value}`;
                        }
                    }
                }
            );

            chart.render();

            // Load data with TODAY
            loadStatsBoxes(currentRange, currentDateRange);
            loadChartData(currentRange, currentDateRange);
        });

        function loadStatusDetails(statusKey) {
            $.get('/statistics/status-details', {
                status: statusKey,
                range: currentRange,
                date_range: currentDateRange || todayYmd()
            }, function(resp) {
                $('#statusDetailsLabel')
                    .text(resp.title)
                    .attr('data-crm-status', resp.crm_status);

                $('#nursesRegularCount').text(resp.nurses_regular ?? 0);
                $('#nursesSpecialistCount').text(resp.nurses_specialist ?? 0);
                $('#nonNursesRegularCount').text(resp.non_nurses_regular ?? 0);
                $('#nonNursesSpecialistCount').text(resp.non_nurses_specialist ?? 0);

                const reasons = resp.rejection_reasons || [];
                if (resp.crm_status === 'crm_rejected') {
                    if (reasons.length) {
                        const sums = {
                            nurses_regular: 0,
                            nurses_specialist: 0,
                            non_nurses_regular: 0,
                            non_nurses_specialist: 0,
                            total: 0
                        };
                        const rows = reasons.map(function(src) {
                            const nursesRegular = Number(src.nurses_regular ?? 0);
                            const nursesSpecialist = Number(src.nurses_specialist ?? 0);
                            const nonNursesRegular = Number(src.non_nurses_regular ?? 0);
                            const nonNursesSpecialist = Number(src.non_nurses_specialist ?? 0);
                            const rowTotal = Number(src.total ?? 0);
                            sums.nurses_regular += nursesRegular;
                            sums.nurses_specialist += nursesSpecialist;
                            sums.non_nurses_regular += nonNursesRegular;
                            sums.non_nurses_specialist += nonNursesSpecialist;
                            sums.total += rowTotal;

                            const label = $('<div>').text(src.label || src.reason || 'Unknown').html();
                            return `<tr>
                                <td>${label}</td>
                                <td class="text-center">${nursesRegular}</td>
                                <td class="text-center">${nursesSpecialist}</td>
                                <td class="text-center">${nonNursesRegular}</td>
                                <td class="text-center">${nonNursesSpecialist}</td>
                                <td class="text-center fw-bold">${rowTotal}</td>
                            </tr>`;
                        }).join('');
                        $('#rejectionReasonStats').html(rows);
                        $('#reasonSumNursesRegular').text(sums.nurses_regular);
                        $('#reasonSumNursesSpecialist').text(sums.nurses_specialist);
                        $('#reasonSumNonNursesRegular').text(sums.non_nurses_regular);
                        $('#reasonSumNonNursesSpecialist').text(sums.non_nurses_specialist);
                        $('#reasonSumTotal').text(sums.total);
                        $('#rejectionReasonTotals').removeClass('d-none');
                    } else {
                        $('#rejectionReasonStats').html(
                            '<tr><td colspan="6" class="text-center text-muted">No rejection reason data</td></tr>'
                        );
                        $('#rejectionReasonTotals').addClass('d-none');
                    }
                    $('#rejectionReasonsSection').removeClass('d-none');
                } else {
                    $('#rejectionReasonStats').empty();
                    $('#rejectionReasonTotals').addClass('d-none');
                    $('#rejectionReasonsSection').addClass('d-none');
                }

                let jobSourceHtml = '';

                if (resp.job_sources && resp.job_sources.length) {
                    jobSourceHtml += `
                            <div class="row text-center">
                                ${resp.job_sources.map(src => `
                                                        <div class="col-md-3 col-6 mb-2">
                                                            <small class="text-muted d-block">${src.name}</small>
                                                            <span class="fw-bold fs-5">${src.total}</span>
                                                        </div>
                                                    `).join('')}
                            </div>
                        `;
                } else {
                    jobSourceHtml = `
                            <div class="col-12 text-center text-muted">
                                No job source data
                            </div>
                        `;
                }

                $('#jobSourceStats').html(jobSourceHtml);

                const modal = new bootstrap.Modal(document.getElementById('statusDetailsModal'));
                modal.show();
            }).fail(function(xhr) {
                console.error('Error loading status details:', xhr.responseText);
                if (typeof toastr !== 'undefined') {
                    toastr.error('Unable to load CRM status details.');
                }
            });
        }

        // Listen to clicks on counts
        $(document).on('click', '.clickable', function() {
            const category = $(this).data('category'); // nurses / non_nurses
            const type = $(this).data('type'); // regular / specialist
            const status = $('#statusDetailsLabel').attr('data-crm-status'); // current status
            const range = currentRange;
            const date_range = currentDateRange || todayYmd();

            // Create a form dynamically
            const form = $('<form>', {
                action: '/dashboard/statistics-report',
                method: 'POST',
                target: '_blank'
            });

            // Add CSRF token
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            form.append(`<input type="hidden" name="_token" value="${csrfToken}">`);

            // Add filters as hidden inputs
            form.append(`<input type="hidden" name="category" value="${category}">`);
            form.append(`<input type="hidden" name="type" value="${type}">`);
            form.append(`<input type="hidden" name="status" value="${status}">`);
            form.append(`<input type="hidden" name="range" value="${range}">`);
            form.append(`<input type="hidden" name="date_range" value="${date_range}">`);

            // Append form to body, submit, then remove it
            $('body').append(form);
            form.submit();
            form.remove();
        });

        function initFlatpickr(mode) {
            if (fp) fp.destroy();

            const today = new Date();

            let options = {
                allowInput: false,
                defaultDate: today,
                onChange: function(selectedDates, dateStr) {
                    if (!dateStr) return;

                    currentDateRange = dateStr;
                    loadStatsBoxes(currentRange, currentDateRange);
                    loadChartData(currentRange, currentDateRange);
                }
            };

            if (mode === "daily") {
                options.dateFormat = "Y-m-d";
            } else if (mode === "weekly") {
                options.mode = "range";
                options.dateFormat = "Y-m-d";
            } else if (mode === "monthly") {
                options.plugins = [new monthSelectPlugin({
                    shorthand: true,
                    dateFormat: "Y-m",
                    altFormat: "F Y"
                })];
            } else if (mode === "yearly") {
                options.plugins = [new flatpickr.plugins.yearSelect({
                    shorthand: true,
                    dateFormat: "Y"
                })];
            } else if (mode === "aggregate") {
                options.mode = "range";
                options.dateFormat = "Y-m-d";
            }

            fp = flatpickr(dateInput, options);
            currentDateRange = fp.input.value; // today
        }

        // Dropdown filter click
        document.querySelectorAll(".stats-filter").forEach(item => {
            item.addEventListener("click", function(e) {
                e.preventDefault();

                document.querySelectorAll('.stats-filter').forEach(el => el.classList.remove('active'));
                this.classList.add('active');

                currentRange = this.dataset.range;
                rangeBtn.innerText = this.innerText;

                initFlatpickr(currentRange);

                loadStatsBoxes(currentRange, currentDateRange);
                loadChartData(currentRange, currentDateRange);
            });
        });

        function showDetailsModal(id, name, email, role, status) {
            const modalId = `showDetailsModal-${id}`;
            const modalSelector = `#${modalId}`;

            if ($(modalSelector).length === 0) {
                $('body').append(`
                        <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label">
                            <div class="modal-dialog modal-lg modal-dialog-top">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="${modalId}Label">User Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <div class="text-center my-3">
                                            <div class="spinner-border text-primary my-3" role="status">
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

            $(modalSelector).modal('show');

            setTimeout(() => {
                $(modalSelector + ' .modal-body').html(`
                        <table class="table table-bordered mb-0">
                            <tr><th>User ID</th><td>${id}</td></tr>
                            <tr><th>User Name</th><td>${name}</td></tr>
                            <tr><th>Email</th><td>${email}</td></tr>
                            <tr><th>Role</th><td>${role}</td></tr>
                            <tr><th>Status</th><td>${status}</td></tr>
                        </table>
                    `);
            }, 500);
        }

        $(function() {
            const today = moment().format('YYYY-MM-DD');
            $('#dateRangePicker').val(today + ' to ' + today);
            window.userStatisticsDateRange = today + '|' + today;

            $('#dateRangePicker').daterangepicker({
                startDate: moment(),
                endDate: moment(),
                maxDate: moment(), // ✅ disables all future dates
                autoUpdateInput: true,
                locale: {
                    cancelLabel: 'Clear',
                    format: 'YYYY-MM-DD'
                }
            });

            $('#showDateRange').html(today + ' to ' + today);

            $('#dateRangePicker').on('apply.daterangepicker', function(ev, picker) {
                const formatted = picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format(
                    'YYYY-MM-DD');
                $(this).val(formatted);
                window.userStatisticsDateRange = picker.startDate.format('YYYY-MM-DD') + '|' + picker
                    .endDate.format('YYYY-MM-DD');
                $('#showDateRange').html(formatted);
                $('#sales_table').DataTable().ajax.reload();
            });

            $('#dateRangePicker').on('cancel.daterangepicker', function(ev, picker) {
                $(this).val('');
                window.userStatisticsDateRange = '';
                $('#showDateRange').html('All Data');
                $('#sales_table').DataTable().ajax.reload();
            });

            $('#clearDateRange').on('click', function() {
                $('#dateRangePicker').val('');
                window.userStatisticsDateRange = '';
                $('#showDateRange').html('All Data');
                // $('#sales_table').DataTable().ajax.reload();
            });
        });

        function showStatisticsModal(id) {
            const modalId = 'showStatisticsModal-' + id;
            $('#' + modalId).remove();

            const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable modal-xl modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${modalId}Label">User Statistics</h5>
                            <button type="button" class="btn-close btn-close-dark" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-center">
                            <div class="spinner-border text-primary mb-3" role="status" id="${modalId}-loader">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <div id="${modalId}-content" class="note-history-content d-none text-start"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>`;

            $('body').append(modalHtml);
            const modal = new bootstrap.Modal(document.getElementById(modalId));
            modal.show();

            $.ajax({
                url: '{{ route('getUserStatistics') }}',
                type: 'POST',
                data: {
                    _token: "{{ csrf_token() }}",
                    user_key: id,
                    date_range_filter: window.userStatisticsDateRange
                },
                success: function(response) {

                    const currentIcons = {
                        open_sales: 'bag-line-duotone',
                        reopen_sales: 'bag-line-duotone',
                        updated_sales: 'bag-line-duotone',
                        onhold_sales: 'bag-check-line-duotone',
                        pending_sales: 'hourglass-line-duotone',
                        rejected_sales: 'shield-cross-line-duotone',
                        close_sales: 'bag-cross-line-duotone',
                        cvs_requested: 'file-send-broken',
                        cvs_cleared: 'shield-check-line-duotone',
                        cvs_rejected: 'shield-cross-line-duotone',
                        cvs_opened: 'file-search-line-duotone',
                        CRM_sent_cvs: 'plain-line-duotone',
                        CRM_rejected_cv: 'adhesive-plaster-line-duotone',
                        CRM_request: 'question-circle-line-duotone',
                        CRM_rejected_by_request: 'user-cross-line-duotone',
                        CRM_confirmation: 'check-circle-line-duotone',
                        CRM_rebook: 'refresh-line-duotone',
                        CRM_attended: 'calendar-add-line-duotone',
                        CRM_not_attended: 'calendar-minimalistic-line-duotone',
                        CRM_start_date: 'calendar-line-duotone',
                        CRM_start_date_hold: 'calendar-search-line-duotone',
                        CRM_declined: 'quit-full-screen-line-duotone',
                        CRM_invoice: 'file-text-line-duotone',
                        CRM_dispute: 'shield-warning-line-duotone',
                        CRM_paid: 'wallet-line-duotone',
                        applicants_created: 'user-line-duotone',
                        applicants_updated: 'user-line-duotone',
                        cvs_cleared_reverted: 'restart-line-duotone',
                        current_total_sales: 'case-round-line-duotone',
                        current_active_sales: 'check-circle-line-duotone',
                        current_reopen_sales: 'restart-line-duotone',
                        current_onhold_sales: 'pause-circle-line-duotone',
                        current_pending_onhold_sales: 'stopwatch-line-duotone',
                        current_pending_sales: 'hourglass-line-duotone',
                        current_rejected_sales: 'close-circle-line-duotone',
                        current_closed_sales: 'lock-keyhole-line-duotone',
                        current_blacklisted_sales: 'forbidden-circle-line-duotone',
                    };

                    const currentSalesColors = {
                        current_total_sales: 'primary',
                        current_active_sales: 'success',
                        current_reopen_sales: 'dark',
                        current_onhold_sales: 'warning',
                        current_pending_onhold_sales: 'warning',
                        current_pending_sales: 'info',
                        current_rejected_sales: 'danger',
                        current_closed_sales: 'secondary',
                        current_blacklisted_sales: 'danger',
                    };

                    const prevIcons = {
                        start_date: 'calendar-line-duotone',
                        invoice: 'file-text-line-duotone',
                        paid: 'wallet-line-duotone'
                    };

                    const labelOverrides = {
                        cvs_cleared_reverted: 'Cleared Reverted',
                        current_total_sales: 'Total Sales Handled',
                        current_active_sales: 'Live & Active',
                        current_reopen_sales: 'Re-Opened',
                        current_onhold_sales: 'On Hold',
                        current_pending_onhold_sales: 'Awaiting On-Hold Approval',
                        current_pending_sales: 'Pending Approval',
                        current_rejected_sales: 'Rejected',
                        current_closed_sales: 'Closed',
                        current_blacklisted_sales: 'Blacklisted',
                    };

                    const CARD_COL = 'col-12 col-sm-6 col-md-4 col-lg-3';

                    // ── Unified card renderer ─────────────────────────────────────
                    function renderStatBlock(data, icons, defaultClass, colors = {}) {
                        let html = `<div class="row g-3">`;
                        Object.entries(data).forEach(([key, value]) => {
                            const label = labelOverrides[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                            const icon = icons[key] || 'dot-line-duotone';
                            const badgeClass = colors[key] || defaultClass;
                            html += `
                                <div class="${CARD_COL}">
                                    <div class="d-flex align-items-center border rounded p-3 h-100 stat-card-clickable"
                                        style="cursor:pointer;"
                                        data-stat-key="${key}"
                                        data-user-key="${id}"
                                        data-label="${label}">
                                        <iconify-icon
                                            icon="solar:${icon}"
                                            class="fs-1 text-${badgeClass} me-3 flex-shrink-0">
                                        </iconify-icon>
                                        <div class="d-flex flex-column justify-content-center text-truncate">
                                            <span class="fs-4 fw-bold text-${badgeClass}">${value}</span>
                                            <small class="text-muted text-truncate">${label}</small>
                                        </div>
                                    </div>
                                </div>`;
                        });
                        html += `</div>`;
                        return html;
                    }

                    // ── Build modal body ──────────────────────────────────────────
                    let notesHtml = `
                    <div class="row bg-primary text-white rounded px-3 py-2 mb-3 g-2">
                        <div class="col-sm-12 col-md-12 col-lg-4">
                            ${response.user_name ? `<p class="mb-0"><strong>User:</strong> ${response.user_name}</p>` : ''}
                        </div>
                        <div class="col-sm-12 col-md-12 col-lg-4">
                            ${response.start_date ? `<p class="mb-0"><strong>Date Range:</strong> ${response.start_date} to ${response.end_date}</p>` : ''}
                        </div>
                        <div class="col-sm-12 col-md-12 col-lg-4 text-lg-end">
                            ${response.user_role ? `<p class="mb-0"><strong>Role:</strong> ${response.user_role}</p>` : ''}
                        </div>
                    </div>`;

                    const rt = response.user_role_type;

                    if ((rt === 'quality') &&
                        response.quality_agent_stats && Object.keys(response.quality_agent_stats).length > 0) {
                        notesHtml += '<h6 class="mt-3">Quality Statistics</h6>';
                        notesHtml += renderStatBlock(response.quality_agent_stats, currentIcons, 'primary');
                    }

                    if ((rt === 'team_lead' || rt === 'agent' || rt === 'crm') &&
                        response.quality_stats && Object.keys(response.quality_stats).length > 0) {

                        const qs = response.quality_stats;

                        // Calculate processed CVs
                        var cvs_processed =
                            (parseInt(qs.cvs_cleared) || 0) +
                            (parseInt(qs.cvs_rejected) || 0) +
                            (parseInt(qs.cvs_opened) || 0);

                        var remain_in_requested = (cvs_processed - qs.cvs_requested);

                        // Show as processed/requested
                        qs.cvs_requested = `${remain_in_requested}/${parseInt(qs.cvs_requested) || 0}`;

                        notesHtml += '<h6 class="mt-3">Quality Statistics</h6>';
                        notesHtml += renderStatBlock(qs, currentIcons, 'primary');
                    }

                    if ((rt === 'agent' || rt === 'team_lead' || rt === 'crm') &&
                        response.user_stats && Object.keys(response.user_stats).length > 0) {
                        notesHtml += '<h6 class="mt-3">CRM Statistics</h6>';
                        notesHtml += renderStatBlock(response.user_stats, currentIcons, 'primary');
                    }

                    if (rt === 'data_entry' &&
                        response.data_entry_stats && Object.keys(response.data_entry_stats).length > 0) {
                        notesHtml += '<h6 class="mt-3">Applicants Statistics</h6>';
                        notesHtml += renderStatBlock(response.data_entry_stats, currentIcons, 'primary');
                    }

                    if (rt === 'sales' &&
                        response.sales_stats && Object.keys(response.sales_stats).length > 0) {
                        notesHtml += '<h6 class="mt-3">Sales Statistics</h6>';
                        notesHtml += renderStatBlock(response.sales_stats, currentIcons, 'primary');
                    }

                    if (rt === 'sales' &&
                        response.current_sales_stats && Object.keys(response.current_sales_stats).length > 0) {
                        notesHtml += '<h6 class="mt-4 mb-1">Current Statistics</h6>';
                        notesHtml += '<p class="text-muted small mb-2">Where the sales this user added or updated in the selected range stand right now.</p>';
                        notesHtml += renderStatBlock(response.current_sales_stats, currentIcons, 'primary', currentSalesColors);
                    }

                    if ((rt === 'agent' || rt === 'team_lead') &&
                        response.prev_user_stats && Object.keys(response.prev_user_stats).length > 0) {
                        notesHtml += '<h6 class="mt-4">Previous Month Stats</h6>';
                        notesHtml += renderStatBlock(response.prev_user_stats, prevIcons, 'secondary');
                    }

                    $(`#${modalId}-loader`).hide();
                    $(`#${modalId}-content`).removeClass('d-none').html(notesHtml);

                    // ── Card click → open detail modal ────────────────────────────
                    $(`#${modalId}-content`).on('click', '.stat-card-clickable', function() {
                        const statKey = $(this).data('stat-key');
                        const userKey = $(this).data('user-key');
                        const label = $(this).data('label');
                        showStatDetailModal(userKey, statKey, label);
                    });
                },
                error: function(xhr) {
                    $(`#${modalId}-loader`).hide();
                    $(`#${modalId}-content`).removeClass('d-none').html(
                        '<p class="text-danger">Error retrieving statistics. Please try again.</p>'
                    );
                    console.error(xhr.responseText);
                }
            });
        }

        // ── Detail Modal ──────────────────────────────────────────────────────────────
        function showStatDetailModal(userId, statKey, label) {
            const drawerId = 'statDrawer-' + userId + '-' + statKey;

            const existingEl = document.getElementById(drawerId);
            if (existingEl) {
                const existingInstance = bootstrap.Offcanvas.getInstance(existingEl);

                if (existingInstance && existingEl.classList.contains('show')) {
                    existingEl.addEventListener('hidden.bs.offcanvas', function() {
                        const instance = bootstrap.Offcanvas.getInstance(existingEl);
                        if (instance) instance.dispose();
                        existingEl.remove();
                        createDrawer();
                    }, {
                        once: true
                    });

                    existingInstance.hide();
                    return;
                }

                if (existingInstance) existingInstance.dispose();
                existingEl.remove();
            }

            createDrawer();

            function createDrawer() {
                const drawerHtml = `
            <div class="offcanvas offcanvas-end" tabindex="-1" id="${drawerId}"
                style="width:80%; z-index:1080; visibility:hidden;">

                <div class="offcanvas-header bg-primary text-white">
                    <h5 class="offcanvas-title">
                        <iconify-icon icon="solar:list-line-duotone" class="me-2"></iconify-icon>
                        ${label} — Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white"
                            data-bs-dismiss="offcanvas"></button>
                </div>

                <div class="offcanvas-body" style="background-color:#e1e5ec; overflow-y:auto;">
                    <div class="text-center py-4" id="${drawerId}-loader">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2 text-muted">Loading details...</p>
                    </div>
                    <div id="${drawerId}-content" class="d-none"></div>
                </div>

                <div class="border-top p-3 d-flex justify-content-between align-items-center">
                    <span class="text-muted small" id="${drawerId}-total"></span>
                    <button class="btn btn-dark btn-sm" data-bs-dismiss="offcanvas">Close</button>
                </div>
            </div>
        `;

                $('body').append(drawerHtml);

                const drawerEl = document.getElementById(drawerId);
                const tableId = `${drawerId}-table`;

                // Re-adjust table layout once the slide animation has finished
                function adjustTable() {
                    if ($.fn.DataTable.isDataTable(`#${tableId}`)) {
                        const dt = $(`#${tableId}`).DataTable();
                        dt.columns.adjust();
                        if (dt.responsive) dt.responsive.recalc();
                    }
                }

                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        drawerEl.style.visibility = '';

                        const drawer = new bootstrap.Offcanvas(drawerEl, {
                            backdrop: false,
                            scroll: true
                        });

                        // FIX: Bootstrap's focus trap steals focus from the search input.
                        // Deactivate it once the drawer is shown.
                        drawerEl.addEventListener('shown.bs.offcanvas', function() {
                            if (drawer._focustrap && typeof drawer._focustrap.deactivate ===
                                'function') {
                                drawer._focustrap.deactivate();
                            }
                            drawerEl.removeAttribute('tabindex');
                            adjustTable();
                        }, {
                            once: true
                        });

                        drawerEl.addEventListener('hidden.bs.offcanvas', function() {
                            if ($.fn.DataTable.isDataTable(`#${tableId}`)) {
                                $(`#${tableId}`).DataTable().destroy();
                            }
                            const instance = bootstrap.Offcanvas.getInstance(drawerEl);
                            if (instance) instance.dispose();
                            drawerEl.remove();
                        }, {
                            once: true
                        });

                        drawer.show();
                        loadDrawerContent(drawerId);
                    });
                });

                function loadDrawerContent(id) {
                    $.ajax({
                        url: '{{ route('getUserStatisticsDetail') }}',
                        type: 'POST',
                        data: {
                            _token: "{{ csrf_token() }}",
                            user_key: userId,
                            stat_key: statKey,
                            date_range_filter: window.userStatisticsDateRange
                        },
                        success: function(response) {
                            $(`#${id}-loader`).hide();
                            $(`#${id}-total`).text(`Total records: ${response.total}`);

                            if (!response.rows || response.rows.length === 0) {
                                $(`#${id}-content`)
                                    .removeClass('d-none')
                                    .html('<p class="text-muted text-center py-3">No records found.</p>');
                                return;
                            }

                            const tableHtml = `
                        <table id="${tableId}"
                            class="table table-bordered table-hover table-sm align-middle w-100">
                            <thead class="bg-dark">
                                <tr>
                                    ${response.columns.map(col =>
                                        `<th class="text-nowrap text-white">${col}</th>`
                                    ).join('')}
                                </tr>
                            </thead>
                            <tbody>
                                ${response.rows.map(row => `
                                        <tr>
                                            ${row.map(cell => `<td>${cell ?? '—'}</td>`).join('')}
                                        </tr>
                                    `).join('')}
                            </tbody>
                        </table>
                    `;

                            $(`#${id}-content`)
                                .removeClass('d-none')
                                .html(tableHtml);

                            $(`#${tableId}`).DataTable({
                                pageLength: 10,
                                lengthMenu: [
                                    [10, 25, 50, 100, -1],
                                    [10, 25, 50, 100, 'All']
                                ],
                                responsive: true,
                                ordering: true,
                                searching: false,
                                lengthChange: true,
                                destroy: true,
                                dom: '<"d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3"<"stat-table-length"l><"stat-table-buttons"B>>rtip',

                                buttons: [{
                                        extend: 'csvHtml5',
                                        text: '📥 CSV',
                                        className: 'btn btn-success btn-sm',
                                        title: label
                                    },
                                    {
                                        extend: 'pdfHtml5',
                                        text: '📄 PDF',
                                        className: 'btn btn-danger btn-sm',
                                        title: label,
                                        orientation: 'landscape',
                                        pageSize: 'A4'
                                    }
                                ],

                                // Works for both DataTables 1.x (.dataTables_*) and 2.x (.dt-*)
                                initComplete: function() {
                                    const wrapper = $(`#${tableId}_wrapper`);

                                    wrapper.find('.stat-table-length label, .stat-table-length .dt-length')
                                        .addClass('d-flex align-items-center gap-2 mb-0 text-nowrap');

                                    wrapper.find('.stat-table-length select')
                                        .addClass('form-select form-select-sm')
                                        .css({
                                            width: 'auto',
                                            minWidth: '76px'
                                        });

                                    wrapper.find('.stat-table-buttons .dt-buttons')
                                        .addClass('d-flex flex-wrap gap-2 justify-content-sm-end');

                                    // If the drawer is already fully open, fix widths now
                                    if (drawerEl.classList.contains('show')) {
                                        adjustTable();
                                    }
                                }
                            });
                        },
                        error: function() {
                            $(`#${id}-loader`).hide();
                            $(`#${id}-content`)
                                .removeClass('d-none')
                                .html('<p class="text-danger text-center">Error loading details.</p>');
                        }
                    });
                }
            }
        }

        function fetchWeeklySales() {
            return new Promise((resolve, reject) => {
                fetch('/get-weekly-sales')
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('weeklySalesCount').textContent = data.total;
                        updateSalesChart(data.chartData);
                        let html = `<table class="table table-bordered">
                                            <thead>
                                                <tr><th>ID</th><th>Office Name</th><th>Unit Name</th><th>PostCode</th><th>Date</th></tr>
                                            </thead>
                                            <tbody>`;
                        data.details.forEach(sale => {
                            html += `<tr>
                                    <td>${sale.id}</td>
                                    <td>${sale.office?.office_name ?? ''}</td>
                                    <td>${sale.unit?.unit_name ?? ''}</td>
                                    <td>${sale.sale_postcode ?? ''}</td>
                                    <td>${moment(sale.created_at).format('DD-MM-YYYY hh:mm A')}</td>
                                </tr>`;
                        });
                        html += '</tbody></table>';
                        document.getElementById('weeklySalesDetails').innerHTML = html;
                        resolve(data);
                    })
                    .catch(error => {
                        console.error('Error fetching weekly sales:', error);
                        document.getElementById('weeklySalesDetails').innerHTML =
                            '<p class="text-danger">Failed to load data.</p>';
                        reject(error);
                    });
            });
        }

        // Initial call for weekly sales
        $.when(fetchWeeklySales()).done(function() {
            console.log('Weekly sales fetched successfully');
        }).fail(function(error) {
            console.error('Failed to fetch weekly sales:', error);
        });

        setInterval(function() {
            $.when(fetchWeeklySales()).done(function() {
                console.log('Weekly sales updated');
            });
        }, 60000);

        $(document).ready(function() {
            // Create loader row
            const loadingRow = `<tr><td colspan="100%" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </td></tr>`;

            // Function to show loader
            function showLoader() {
                $('#users_table tbody').empty().append(loadingRow);
            }

            // Initialize DataTable with server-side processing
            const table = $('#users_table').DataTable({
                processing: false,
                serverSide: true,
                ajax: {
                    url: @json(route('getUsersForDashboard')),
                    type: 'GET',
                    data: function(d) {
                        d.status_filter = window.currentFilter || '';
                    },
                    beforeSend: function() {
                        showLoader(); // Show loader before AJAX request starts
                    },
                    error: function(xhr) {
                        console.error('DataTable AJAX error:', xhr.status, xhr.responseJSON);
                        $('#users_table tbody').empty().html(
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
                        data: 'name',
                        name: 'users.name'
                    },
                    {
                        data: 'email',
                        name: 'users.email'
                    },
                    {
                        data: 'role_name',
                        name: 'roles.name'
                    },
                    {
                        data: 'created_at',
                        name: 'users.created_at'
                    },
                    {
                        data: 'is_active',
                        name: 'users.is_active',
                        orderable: false
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false
                    }
                ],
                rowId: function(data) {
                    return 'row_' + data.id;
                },
                dom: 'flrtip',
                drawCallback: function(settings) {
                    const api = this.api();
                    const pagination = $(api.table().container()).find('.dataTables_paginate');
                    pagination.empty();

                    const pageInfo = api.page.info();
                    const currentPage = pageInfo.page + 1;
                    const totalPages = pageInfo.pages;

                    if (pageInfo.recordsTotal === 0) {
                        $('#users_table tbody').html(
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

            // Handle DataTable search
            // $('#users_table_filter input').on('keyup', function() {
            //     table.search(this.value).draw();
            // });
        });

        function goToPage(totalPages) {
            const input = document.getElementById('goToPageInput');
            const errorMessage = document.getElementById('goToPageError');
            let page = parseInt(input.value);

            if (!isNaN(page) && page >= 1 && page <= totalPages) {
                $('#users_table').DataTable().page(page - 1).draw('page');
                input.classList.remove('is-invalid');
            } else {
                input.classList.add('is-invalid');
            }
        }

        // Function to move the page forward or backward
        function movePage(page) {
            var table = $('#users_table').DataTable();
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
    </script>
    @vite(['resources/js/pages/dashboard-analytics.js'])
@endsection
