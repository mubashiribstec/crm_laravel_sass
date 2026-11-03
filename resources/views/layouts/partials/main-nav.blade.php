<div class="main-nav">
    <!-- Sidebar Logo -->
    <div class="logo-box">
        <a href="{{ route('dashboard.index') }}" class="logo-dark">
            <img src="/images/KingsburyPersonnel_logo_icon-removebg-preview.png" class="logo-sm" alt="Kingsbury Personnel">
            <img src="/images/KingsburyPersonnel_logo_row.webp" class="logo-lg" alt="Kingsbury Personnel">
        </a>

        <a href="{{ route('dashboard.index') }}" class="logo-light">
            <img src="/images/KingsburyPersonnel_logo_icon-removebg-preview.png" class="logo-sm"
                alt="Kingsbury Personnel">
            <img src="/images/KingsburyPersonnel_logo_row.webp" class="logo-lg" alt="Kingsbury Personnel" height="80"
                width="">
        </a>
    </div>

    <!-- Menu Toggle Button (sm-hover) -->
    <button type="button" class="button-sm-hover" aria-label="Show Full Sidebar">
        <i class="ri-menu-2-line fs-24 button-sm-hover-icon"></i>
    </button>

    <div class="scrollbar" data-simplebar>
        <ul class="navbar-nav" id="navbar-nav">
            <li class="menu-title">Menu</li>
            @canany(['dashboard'])
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('dashboard.index') }}">
                        <span class="nav-icon">
                            <i class="ri-dashboard-2-line"></i>
                        </span>
                        <span class="nav-text"> Dashboard </span>
                    </a>
                </li>
            @endcanany

            @canany(['xplogent-agent-call'])
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('xplogent.agent-call') }}">
                        <span class="nav-icon">
                            <i class="ri-robot-2-line"></i>
                        </span>
                        <span class="nav-text"> Agent Call (AI) </span>
                    </a>
                </li>
            @endcanany

            <!-- applicants Menu -->
            @canany(['applicant-index', 'applicant-create'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarApplicants" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarApplicants">
                        <span class="nav-icon">
                            <i class="ri-graduation-cap-line"></i>
                        </span>
                        <span class="nav-text"> Applicants</span>
                    </a>
                    <div class="collapse" id="sidebarApplicants">
                        <ul class="nav sub-navbar-nav">
                            @canany(['applicant-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('applicants.list') }}">List View</a>
                                </li>
                            @endcanany
                            @canany(['applicant-create'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('applicants.create') }}">Create Applicant</a>
                                </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end applicants Menu -->

            <!-- head office Menu -->
            @canany(['office-index', 'office-create'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarHeadOffices" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarHeadOffices">
                        <span class="nav-icon">
                            <i class="ri-building-line"></i>
                        </span>
                        <span class="nav-text"> Head Offices </span>
                    </a>
                    <div class="collapse" id="sidebarHeadOffices">
                        <ul class="nav sub-navbar-nav">
                            @canany(['office-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('head-offices.list') }}">List View</a>
                                </li>
                            @endcanany
                            @canany(['office-create'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('head-offices.create') }}">Create Head Office</a>
                                </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end head office Menu -->

            <!-- units Menu -->
            @canany(['unit-index', 'unit-create'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarUnits" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarUnits">
                        <span class="nav-icon">
                            <i class="ri-box-3-line"></i>
                        </span>
                        <span class="nav-text"> Units </span>
                    </a>
                    <div class="collapse" id="sidebarUnits">
                        <ul class="nav sub-navbar-nav">
                            @canany(['unit-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('units.list') }}">List View</a>
                                </li>
                            @endcanany
                            @canany(['unit-create'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('units.create') }}">Create Unit</a>
                                </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end units Menu -->

            <!-- sales Menu -->
            @canany(['sale-index', 'sale-create', 'sale-direct-index', 'sale-open-index', 'sale-closed-index',
                'sale-hold-index', 'sale-pending-hold-index'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarSales" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarSales">
                        <span class="nav-icon">
                            <i class="ri-line-chart-line"></i>
                        </span>
                        <span class="nav-text"> Sales </span>
                    </a>
                    <div class="collapse" id="sidebarSales">
                        <ul class="nav sub-navbar-nav">
                            @canany(['sale-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.list') }}">List View</a>
                                </li>
                            @endcanany
                            @canany(['sale-create'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.create') }}">Create Sale</a>
                                </li>
                            @endcanany
                            {{-- @canany(['sale-direct-index'])
                                   <li class="sub-nav-item">
                                        <a class="sub-nav-link" href="{{ route('sales.direct')}}">Direct Sales</a>
                                   </li>
                                   @endcanany --}}
                            @canany(['sale-open-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.open') }}">Open Sales</a>
                                </li>
                            @endcanany
                            @canany(['sale-closed-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.closed') }}">Closed Sales</a>
                                </li>
                            @endcanany
                            @canany(['sale-rejected-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.rejected') }}">Rejected Sales</a>
                                </li>
                            @endcanany
                            @canany(['sale-hold-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.on-hold') }}">On Hold Sales</a>
                                </li>
                            @endcanany
                            @canany(['sale-pending-hold-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('sales.pending-on-hold') }}">Pending On Hold</a>
                                </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end sales Menu -->

            <!-- resources Menu -->
            @canany(['resource-direct-index', 'resource-indirect-index', 'resource-category-index',
                'resource-rejected-index', 'resource-blocked-index', 'resource-crm-paid-index', 'resource-no-job-index', 'resource-not-interested-index'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarResources" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarResources">
                        <span class="nav-icon">
                            <i class="ri-file-list-3-line"></i>
                        </span>
                        <span class="nav-text"> Resources </span>
                    </a>
                    <div class="collapse" id="sidebarResources">
                        <ul class="nav sub-navbar-nav">
                            @canany(['resource-direct-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('resources.directIndex') }}">Direct Sales
                                        Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-indirect-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('resources.indirectIndex') }}">Indirect
                                        Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-category-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link"
                                        href="{{ route('resources.categoryWiseApplicantIndex') }}">Category Wise Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-rejected-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('resources.rejectedIndex') }}">Rejected
                                        Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-blocked-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('resources.blockedApplicantsIndex') }}">Blocked
                                        Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-crm-paid-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('resources.crmPaidIndex') }}">CRM Paid
                                        Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-no-job-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('resources.noJobIndex') }}">No Job Resources</a>
                                </li>
                            @endcanany
                            @canany(['resource-not-interested-index'])
                            <li class="sub-nav-item">
                                <a class="sub-nav-link" href="{{ route('resources.notInterestedIndex') }}">Not Interested
                                    Resources</a>
                            </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end resources Menu -->

            <!-- quality Menu -->
            @canany(['quality-assurance-resource-index', 'quality-assurance-sale-index',
                'quality-assurance-resources-tab-requested-cvs', 'quality-assurance-resources-tab-open-cvs',
                'quality-assurance-resources-tab-no-job-cvs', 'quality-assurance-resources-tab-rejected-cvs',
                'quality-assurance-resources-tab-cleared-cvs'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarQuality" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarQuality">
                        <span class="nav-icon">
                            <i class="ri-medal-line"></i>
                        </span>
                        <span class="nav-text"> Quality </span>
                    </a>
                    <div class="collapse" id="sidebarQuality">
                        <ul class="nav sub-navbar-nav">
                            @canany(['quality-assurance-resource-index', 'quality-assurance-resources-tab-requested-cvs',
                                'quality-assurance-resources-tab-open-cvs', 'quality-assurance-resources-tab-no-job-cvs',
                                'quality-assurance-resources-tab-rejected-cvs', 'quality-assurance-resources-tab-cleared-cvs'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('quality.resources') }}">Resources</a>
                                </li>
                            @endcanany
                            @canany(['quality-assurance-sale-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('quality.sales') }}">Sales</a>
                                </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end quality Menu -->

            {{-- Regions --}}
            @canany(['region-resource-index', 'region-sale-index'])
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarRegions" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarQuality">
                        <span class="nav-icon">
                            <i class="ri-earth-line"></i>
                        </span>
                        <span class="nav-text"> Regions </span>
                    </a>
                    <div class="collapse" id="sidebarRegions">
                        <ul class="nav sub-navbar-nav">
                            @canany(['region-resource-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('regions.resources') }}">Resources</a>
                                </li>
                            @endcanany
                            @canany(['region-sale-index'])
                                <li class="sub-nav-item">
                                    <a class="sub-nav-link" href="{{ route('regions.sales') }}">Sales</a>
                                </li>
                            @endcanany
                        </ul>
                    </div>
                </li>
            @endcanany
            {{-- Regions --}}

            @canany(['crm-index'])
                <li class="menu-title">CRM</li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('crm.list') }}">
                        <span class="nav-icon">
                            <i class="ri-bar-chart-line"></i>
                        </span>
                        <span class="nav-text">CRM</span>
                    </a>
                </li>
            @endcanany

            @canany(['postcode-index'])
                <li class="menu-title">Finder</li>
                <!-- postcode finder Menu -->
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('postcode-finder.index') }}">
                        <span class="nav-icon">
                            <i class="ri-search-line"></i>
                        </span>
                        <span class="nav-text">PostCode Finder</span>
                    </a>
                </li>
                <!-- end postcode finder Menu -->
            @endcanany

            @canany(['message-index', 'email-index', 'sent-email-index'])
                <li class="menu-title">Communication</li>
                @canany(['message-index'])
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('messages.write') }}">
                            <span class="nav-icon">
                                <i class="ri-edit-line"></i>
                            </span>
                            <span class="nav-text">Write Message</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('messages.index') }}">
                            <span class="nav-icon">
                                <i class="ri-chat-3-line"></i>
                            </span>
                            <span class="nav-text">Message Chats</span>
                        </a>
                    </li>
                @endcanany
                @canany(['email-index'])
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('emails.inbox') }}">
                            <span class="nav-icon">
                                <i class="ri-edit-line"></i>
                            </span>
                            <span class="nav-text">Compose Email</span>
                        </a>
                    </li>
                @endcanany
                @canany(['sent-email-index'])
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('emails.sent_emails') }}">
                            <span class="nav-icon">
                                <i class="ri-inbox-line"></i>
                            </span>
                            <span class="nav-text">Sent Emails</span>
                        </a>
                    </li>
                @endcanany
            @endcanany

            @canany(['report-user-login'])
                <li class="menu-title">Reports</li>
                <!-- users Menu -->
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('reports.usersLoginReport') }}">
                        <span class="nav-icon">
                            <i class="ri-pages-line"></i>
                        </span>
                        <span class="nav-text">Users Login Report</span>
                    </a>
                </li>
                <!-- end users Menu -->
            @endcanany

            <!-- scrap data Menu -->
            @canany(['scrap-index'])
                <li class="menu-title">Scrapped Data</li>
                <li class="nav-item">
                    <a class="nav-link menu-arrow" href="#sidebarScrap" data-bs-toggle="collapse" role="button"
                        aria-expanded="false" aria-controls="sidebarScrap">
                        <span class="nav-icon">
                            <i class="ri-filter-3-line"></i>
                        </span>
                        <span class="nav-text"> Scrap </span>
                    </a>
                    <div class="collapse" id="sidebarScrap">
                        <ul class="nav sub-navbar-nav">
                            <li class="sub-nav-item">
                                <a class="sub-nav-link" href="{{ route('scrap.office.list') }}">Head Offices List</a>
                            </li>
                            <li class="sub-nav-item">
                                <a class="sub-nav-link" href="{{ route('scrap.unit.list') }}">Units List</a>
                            </li>
                            <li class="sub-nav-item">
                                <a class="sub-nav-link" href="{{ route('scrap.sales.list') }}">Sales List</a>
                            </li>
                        </ul>
                    </div>
                </li>
            @endcanany
            <!-- end scrap data Menu -->

            @canany(['administrator-user-index', 'administrator-role-index', 'administrator-permission-index',
                'administrator-ip-address-index', 'administrator-job-category-index', 'administrator-job-title-index',
                'administrator-job-source-index', 'administrator-email-template-index', 'administrator-sms-template-index',
                'administrator-setting-index'])
                <li class="menu-title">Administrator</li>
                @canany(['administrator-user-index'])
                    <!-- users Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('users.list') }}">
                            <span class="nav-icon">
                                <i class="ri-group-line"></i>
                            </span>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <!-- end users Menu -->
                @endcanany
                @canany(['administrator-role-index', 'administrator-role-create', 'administrator-permission-index'])
                    <!-- roles Menu -->
                    <li class="nav-item">
                        <a class="nav-link menu-arrow" href="#sidebarRoles" data-bs-toggle="collapse" role="button"
                            aria-expanded="false" aria-controls="sidebarRoles">
                            <span class="nav-icon">
                                <i class="ri-shield-user-line"></i>
                            </span>
                            <span class="nav-text"> Roles & Permissions </span>
                        </a>
                        <div class="collapse" id="sidebarRoles">
                            <ul class="nav sub-navbar-nav">
                                @canany(['administrator-role-index'])
                                    <li class="sub-nav-item">
                                        <a class="sub-nav-link" href="{{ route('roles.list') }}">List View</a>
                                    </li>
                                @endcanany
                                @canany(['administrator-role-create'])
                                    <li class="sub-nav-item">
                                        <a class="sub-nav-link" href="{{ route('roles.create') }}">Create Role</a>
                                    </li>
                                @endcanany
                                @canany(['administrator-permission-index'])
                                    <li class="sub-nav-item">
                                        <a class="sub-nav-link" href="{{ route('permissions.list') }}">Permissions</a>
                                    </li>
                                @endcanany
                            </ul>
                        </div>
                    </li>
                    <!-- end roles Menu -->
                @endcanany
                @canany(['administrator-ip-address-index'])
                    <!-- ip address Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('ip-address.list') }}">
                            <span class="nav-icon">
                                <i class="ri-global-line"></i>
                            </span>
                            <span class="nav-text">IP Address</span>
                        </a>
                    </li>
                    <!-- end ip address Menu -->
                @endcanany
                @canany(['administrator-job-category-index'])
                    <!-- job category Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('job-categories.list') }}">
                            <span class="nav-icon">
                                <i class="ri-briefcase-line"></i>
                            </span>
                            <span class="nav-text">Job Categories</span>
                        </a>
                    </li>
                    <!-- end job category Menu -->
                @endcanany
                @canany(['administrator-job-title-index'])
                    <!-- job title Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('job-titles.list') }}">
                            <span class="nav-icon">
                                <i class="ri-id-card-line"></i>
                            </span>
                            <span class="nav-text">Job Titles</span>
                        </a>
                    </li>
                    <!-- end job title Menu -->
                @endcanany
                @canany(['administrator-job-source-index'])
                    <!-- job source Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('job-sources.list') }}">
                            <span class="nav-icon">
                                <i class="ri-links-line"></i>
                            </span>
                            <span class="nav-text">Job Source</span>
                        </a>
                    </li>
                    <!-- end job source Menu -->
                @endcanany
                @canany(['administrator-email-template-index'])
                    <!-- Email Templates -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('settings.email-templates') }}">
                            <span class="nav-icon">
                                <i class="ri-inbox-line"></i>
                            </span>
                            <span class="nav-text">Email Templates</span>
                        </a>
                    </li>
                    <!-- end Email Templates -->
                @endcanany
                @canany(['administrator-sms-template-index'])
                    <!-- SMS Templates -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('settings.sms-templates') }}">
                            <span class="nav-icon">
                                <i class="ri-discuss-line"></i>
                            </span>
                            <span class="nav-text">SMS Templates</span>
                        </a>
                    </li>
                    <!-- end SMS Templates -->
                @endcanany
                @canany(['administrator-setting-index'])
                    <!-- settings Menu -->
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('settings.list') }}">
                            <span class="nav-icon">
                                <i class="ri-tools-line"></i>
                            </span>
                            <span class="nav-text">Settings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('import.index') }}">
                            <span class="nav-icon">
                                <i class="ri-upload-line"></i>
                            </span>
                            <span class="nav-text">Import</span>
                        </a>
                    </li>
                    <!-- end settings Menu -->
                @endcanany
            @endcanany
        </ul>
    </div>
</div>
