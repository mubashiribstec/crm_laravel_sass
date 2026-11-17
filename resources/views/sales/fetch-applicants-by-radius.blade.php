@extends('layouts.vertical', ['title' => 'Job Details', 'subTitle' => 'Sales'])

@section('content')
    <style>
        .triangle-green {
            position: relative;
        }

        .triangle-green::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            /* thickness of the bar */
            height: 100%;
            /* full row height */
            background-color: #5cc184;
            /* main green bar */
            z-index: 2;
        }

        .triangle-red {
            position: relative;
        }

        .triangle-red::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: #e96767;
            /* main red bar */
            z-index: 2;
        }

        .send-cv-existing-fields,
        .send-cv-existing-fields .form-control,
        .send-cv-existing-fields .form-check-label {
            font-family: inherit;
            font-size: 14px;
        }

        .send-cv-questionnaire-options {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
        }

        .sale-rich-preview {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            line-height: 1.5rem;
            max-height: 4.5rem;
            white-space: normal;
            overflow-wrap: anywhere;
        }
    </style>
    <div class="row">
        <div class="col-lg-12">
            <div class="card card-highlight">
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <ul class="list-unstyled mb-0">
                                        <li>
                                            <strong>PostCode:</strong>
                                            <span style="font-size:18px;">
                                                {{ strtoupper($sale->sale_postcode ?? 'N/A') }}
                                            </span>

                                            <button type="button"
                                                class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode"
                                                data-postcode="{{ strtoupper($sale->sale_postcode ?? 'N/A') }}"
                                                title="Copy Postcode">
                                                <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                            </button>
                                        </li>

                                        <li><strong>Head Office Name:</strong> {{ $office->office_name ?? 'N/A' }}</li>
                                        <li><strong>Unit Name:</strong> {{ $unit->unit_name ?? 'N/A' }}</li>
                                        <li><strong>Category:</strong>
                                            {{ $jobCategory ? ucwords($jobCategory->name) . $jobType : 'N/A' }}</li>
                                        <li><strong>Title:</strong> {{ $jobTitle ? ucwords($jobTitle->name) : 'N/A' }}</li>
                                        @php
                                            $remaining = (int) $sale->cv_limit - $sale_cv_count;
                                            $isFull = $sale_cv_count >= $sale->cv_limit;
                                        @endphp
                                        <li>
                                            <div class="d-flex align-items-center gap-2">
                                                <strong class="text-nowrap">CV Limit:</strong>

                                                <div class="d-flex justify-content-center">
                                                    <span
                                                        class="badge
                                            {{ $isFull ? 'bg-danger' : 'bg-primary' }}
                                            d-flex justify-content-center align-items-center py-2 text-center"
                                                        style="font-size: 90%; min-width: 120px;">
                                                        <!-- adjust min-width if needed -->

                                                        @if ($isFull)
                                                            0 / {{ (int) $sale->cv_limit }} &nbsp; Limit Reached
                                                        @else
                                                            {{ $remaining }} / {{ (int) $sale->cv_limit }} &nbsp; Limit
                                                            Remains
                                                        @endif

                                                    </span>
                                                </div>
                                            </div>
                                        </li>
                                        @php
                                            $saleRichTextPreview = function (?string $html, int $limit = 300): string {
                                                $html = $html ?? '';
                                                $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
                                                $html = preg_replace(
                                                    '/<(span|[^>]+) style="[^"]*"[^>]*>/i',
                                                    '<$1>',
                                                    $html,
                                                );
                                                $html = preg_replace('/<\/?span[^>]*>/i', '', $html);
                                                $withBreaks = preg_replace(
                                                    '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                                                    "\n",
                                                    $html,
                                                );
                                                $plainText = html_entity_decode(strip_tags($withBreaks ?? ''));
                                                $plainText =
                                                    preg_replace(
                                                        '/https?:\/\/[^\s<>"\'\)\],]+/i',
                                                        '[link]',
                                                        $plainText,
                                                    ) ?? $plainText;
                                                $normalizedText = preg_replace("/[\r\n]+/", "\n", $plainText);

                                                return e(Str::limit(trim($normalizedText ?? ''), $limit, '...'));
                                            };

                                            $saleRichTextSafeHtml = function (?string $html): string {
                                                $html = trim($html ?? '');
                                                if ($html === '') {
                                                    return '';
                                                }

                                                $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
                                                $html =
                                                    preg_replace(
                                                        '/\sdata-bs-(toggle|target|dismiss)="[^"]*"/i',
                                                        '',
                                                        $html,
                                                    ) ?? $html;

                                                $previousLibxmlErrorSetting = libxml_use_internal_errors(true);

                                                try {
                                                    $doc = new DOMDocument();
                                                    $loaded = $doc->loadHTML(
                                                        '<?xml encoding="UTF-8"><div id="__sale_html_wrap__">' .
                                                            $html .
                                                            '</div>',
                                                        LIBXML_NONET | LIBXML_HTML_NODEFDTD,
                                                    );
                                                    $wrap = $loaded ? $doc->getElementById('__sale_html_wrap__') : null;

                                                    if (!$wrap) {
                                                        return e(strip_tags($html));
                                                    }

                                                    $allowedTags = [
                                                        'a',
                                                        'b',
                                                        'blockquote',
                                                        'br',
                                                        'div',
                                                        'em',
                                                        'h1',
                                                        'h2',
                                                        'h3',
                                                        'h4',
                                                        'h5',
                                                        'h6',
                                                        'i',
                                                        'li',
                                                        'ol',
                                                        'p',
                                                        'span',
                                                        'strong',
                                                        'table',
                                                        'tbody',
                                                        'td',
                                                        'th',
                                                        'thead',
                                                        'tr',
                                                        'u',
                                                        'ul',
                                                    ];
                                                    $removeWithContents = [
                                                        'embed',
                                                        'iframe',
                                                        'object',
                                                        'script',
                                                        'style',
                                                    ];
                                                    $nodes = iterator_to_array($wrap->getElementsByTagName('*'));

                                                    for ($i = count($nodes) - 1; $i >= 0; $i--) {
                                                        $node = $nodes[$i];
                                                        $tag = strtolower($node->nodeName);

                                                        if (in_array($tag, $removeWithContents, true)) {
                                                            $node->parentNode?->removeChild($node);
                                                            continue;
                                                        }

                                                        if (!in_array($tag, $allowedTags, true)) {
                                                            while ($node->firstChild) {
                                                                $node->parentNode?->insertBefore(
                                                                    $node->firstChild,
                                                                    $node,
                                                                );
                                                            }
                                                            $node->parentNode?->removeChild($node);
                                                            continue;
                                                        }

                                                        $href = null;
                                                        if ($tag === 'a' && $node->hasAttribute('href')) {
                                                            $candidate = trim($node->getAttribute('href'));
                                                            $scheme = parse_url($candidate, PHP_URL_SCHEME);
                                                            if (
                                                                ($scheme === null &&
                                                                    !str_starts_with($candidate, '//')) ||
                                                                in_array(
                                                                    strtolower((string) $scheme),
                                                                    ['http', 'https', 'mailto', 'tel'],
                                                                    true,
                                                                )
                                                            ) {
                                                                $href = $candidate;
                                                            }
                                                        }

                                                        while ($node->attributes->length > 0) {
                                                            $node->removeAttributeNode($node->attributes->item(0));
                                                        }

                                                        if ($href !== null) {
                                                            $node->setAttribute('href', $href);
                                                        }
                                                    }

                                                    $safe = '';
                                                    foreach (iterator_to_array($wrap->childNodes) as $child) {
                                                        $safe .= $doc->saveHTML($child);
                                                    }

                                                    return $safe;
                                                } finally {
                                                    libxml_clear_errors();
                                                    libxml_use_internal_errors($previousLibxmlErrorSetting);
                                                }
                                            };

                                            $saleRichTextUrlButton = function (?string $html): string {
                                                if (
                                                    !preg_match('/https?:\/\/[^\s<>"\'\)\],]+/i', $html ?? '', $matches)
                                                ) {
                                                    return '';
                                                }

                                                $url = rtrim($matches[0], '.,;:!?');

                                                return '<p class="text-center mt-3"><a href="' .
                                                    e($url) .
                                                    '" target="_blank" rel="noopener noreferrer" class="btn btn-info btn-sm rounded-pill px-3">' .
                                                    '<iconify-icon icon="mdi:link-variant" class="me-1"></iconify-icon> Click to Open Link</a></p>';
                                            };

                                            $qualificationPreview = $saleRichTextPreview($sale->qualification);
                                            $benefitsPreview = $saleRichTextPreview($sale->benefits);
                                            $experiencePreview = $saleRichTextPreview($sale->experience);
                                            $qualificationHtml = $saleRichTextSafeHtml($sale->qualification);
                                            $benefitsHtml = $saleRichTextSafeHtml($sale->benefits);
                                            $experienceHtml = $saleRichTextSafeHtml($sale->experience);
                                            $qualificationUrlButton = $saleRichTextUrlButton($sale->qualification);
                                            $benefitsUrlButton = $saleRichTextUrlButton($sale->benefits);
                                            $experienceUrlButton = $saleRichTextUrlButton($sale->experience);
                                            $salaryHtml = $saleRichTextSafeHtml($sale->salary);
                                            $timingHtml = $saleRichTextSafeHtml($sale->timing);
                                        @endphp

                                    </ul>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <input type="hidden" id="sale_id" value="{{ $sale->id }}">
                                    <ul class="list-unstyled mb-0">
                                        <li><strong>Sale ID#:</strong> {{ $sale->id ?? 'N/A' }}</li>
                                        <li><strong>Posted On:</strong>
                                            {{ \Carbon\Carbon::parse($sale->created_at)->format('d M Y, h:i A') }}</li>
                                        <li>
                                            <strong>Position Type:</strong>
                                            @if ($sale->position_type)
                                                <span
                                                    class="badge bg-primary text-white fs-12">{{ ucwords(str_replace('-', ' ', $sale->position_type)) }}</span>
                                            @else
                                                N/A
                                            @endif
                                        </li>
                                        <li><strong>Salary:</strong> {!! $salaryHtml !== '' ? $salaryHtml : 'N/A' !!}</li>
                                        <li><strong>Timing:</strong> {!! $timingHtml !!}</li>
                                        <li><strong>Status:</strong>
                                            @php
                                                $status = $sale->status;
                                                if ($status == '1') {
                                                    $statusClass = '<span class="badge bg-success">Open</span>';
                                                } elseif ($status == '2') {
                                                    $statusClass = '<span class="badge bg-warning">Pending</span>';
                                                } elseif ($status == '3') {
                                                    $statusClass = '<span class="badge bg-danger">Rejected</span>';
                                                } else {
                                                    $statusClass = '<span class="badge bg-danger">Closed</span>';
                                                }
                                            @endphp
                                            {!! $statusClass !!}
                                        </li>

                                    </ul>
                                </div>
                            </div>
                            <div class="row g-3 p-0">
                                <div class="col-12 col-lg-4">
                                    <div class="border rounded p-0 h-100">
                                        <strong class="d-block mb-1">Qualification</strong>
                                        <button type="button"
                                            class="btn btn-link p-0 d-block w-100 text-start text-wrap sale-rich-preview"
                                            data-bs-toggle="modal" data-bs-target="#qualification-{{ $sale->id }}">
                                            {!! $qualificationPreview !== '' ? nl2br($qualificationPreview) : 'No details provided.' !!}
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <div class="border rounded p-0 h-100">
                                        <strong class="d-block mb-1">Benefits</strong>
                                        <button type="button"
                                            class="btn btn-link p-0 d-block w-100 text-start text-wrap sale-rich-preview"
                                            data-bs-toggle="modal" data-bs-target="#benefits-{{ $sale->id }}">
                                            {!! $benefitsPreview !== '' ? nl2br($benefitsPreview) : 'No details provided.' !!}
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <div class="border rounded p-0 h-100">
                                        <strong class="d-block mb-1">Experience</strong>
                                        <button type="button"
                                            class="btn btn-link p-0 d-block w-100 text-start text-wrap sale-rich-preview"
                                            data-bs-toggle="modal" data-bs-target="#experience-{{ $sale->id }}">
                                            {!! $experiencePreview !== '' ? nl2br($experiencePreview) : 'No details provided.' !!}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <!-- Button (Blade) -->
                                <button class="btn btn-info btn-sm my-1" type="button" id="viewJobDescription"
                                    data-description="{{ $sale->job_description }}">
                                    <span class="nav-icon">
                                        <i class="ri-file-text-line fs-16"></i>
                                    </span>
                                    Job Description
                                </button>

                                <button class="btn btn-warning btn-sm my-1" type="button" id="viewDocuments">
                                    <span class="nav-icon">
                                        <i class="ri-file-text-line fs-16"></i>
                                    </span>
                                    View Documents
                                </button>
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
                    <div class="row justify-content-between align-items-center">
                        <h4 class="card-title mb-3">Active Applicants within {{ $radius }}KMs /
                            {{ $radiusInMiles }}Miles</h4>
                    </div>
                    <div class="row justify-content-between align-items-center">
                        <div class="col-xl-3">
                            <div class="text-md-start mb-3">
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
                        <div class="col-xl-9">
                            <div class="text-end mb-3">
                                <div class="d-inline-flex flex-wrap justify-content-end align-items-center">

                                    <!-- Sources Filter Dropdown -->
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButtonSource" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-filter-line me-1"></i> <span id="showFilterSource">All
                                                Sources</span>
                                        </button>
                                        <div class="dropdown-menu filter-dropdowns"
                                            aria-labelledby="dropdownMenuButtonSource">
                                            <input type="text" class="form-control mb-2" id="sourceSearchInput"
                                                placeholder="Search Source...">
                                            <div class="d-flex justify-content-end px-1 mb-1" id="sourceToggleContainer">
                                                <a href="#"
                                                    class="filter-select-all text-primary small fw-semibold me-2"
                                                    data-target=".source-filter" data-exclude="[data-source-id='']">Select
                                                    All</a>
                                                <a href="#"
                                                    class="filter-deselect-all text-danger small fw-semibold"
                                                    data-target=".source-filter" data-exclude="[data-source-id='']"
                                                    style="display:none">Deselect All</a>
                                            </div>
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
                                    <!-- Applicant Status Filter Dropdown -->
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButtonApplicantStatus" data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                            <i class="ri-filter-line me-1"></i> <span id="showFilterApplicantStatus">All
                                                Applicant Status</span>
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButtonApplicantStatus">
                                            <a class="dropdown-item applicant-status-filter" href="#">All Applicant
                                                Status</a>
                                            <a class="dropdown-item applicant-status-filter" href="#">Interested</a>
                                            <a class="dropdown-item applicant-status-filter" href="#">Not
                                                Interested</a>
                                            <a class="dropdown-item applicant-status-filter" href="#">No Job</a>
                                            <a class="dropdown-item applicant-status-filter" href="#">Blocked</a>
                                            <a class="dropdown-item applicant-status-filter" href="#">Callback</a>
                                            <a class="dropdown-item applicant-status-filter" href="#">Have Nursing
                                                Home Experience</a>
                                        </div>
                                    </div>
                                    <!-- CV Status Filter Dropdown -->
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                            id="dropdownMenuButtonCvStatus" data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                            <i class="ri-filter-line me-1"></i> <span id="showFilterCvStatus">All CV
                                                Status</span>
                                        </button>
                                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButtonCvStatus">
                                            <a class="dropdown-item cv-status-filter" href="#">All CV Status</a>
                                            <a class="dropdown-item cv-status-filter" href="#">Open</a>
                                            <a class="dropdown-item cv-status-filter" href="#">Sent</a>
                                            <a class="dropdown-item cv-status-filter" href="#">Paid</a>
                                            <a class="dropdown-item cv-status-filter" href="#">Reject Job</a>
                                            <a class="dropdown-item cv-status-filter" href="#">CRM Active</a>
                                        </div>
                                    </div>
                                    @canany(['applicant-export-from-sale-page'])
                                        <div class="dropdown d-inline" id="exportDropdown">
                                            <button class="btn btn-outline-primary me-1 my-1 dropdown-toggle" type="button"
                                                id="dropdownMenuButton5" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="ri-download-line me-1"></i> <span class="btn-text">Export</span>
                                            </button>
                                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton5">
                                                <a class="dropdown-item export-btn"
                                                    href="{{ route('applicantsExport', ['type' => 'withinRadius', 'radius' => $radius, 'model_type' => 'Horsefly\\Sale', 'model_id' => $sale->id]) }}">Export
                                                    Data</a>
                                            </div>
                                        </div>
                                    @endcanany
                                    <!-- Add Updated Sales Filter Button -->
                                    <button class="btn btn-success my-1 me-1"
                                        title="Mark selected as having nursing home experience" type="button"
                                        id="markNursingHomeBtn">
                                        <span class="nav-icon">
                                            <i class="ri-check-line fs-16"></i>
                                        </span>
                                        Mark as Nursing Home Exp
                                    </button>
                                    <button class="btn btn-danger my-1"
                                        title="Mark selected as having no nursing home experience" type="button"
                                        id="markNoNursingHomeBtn">
                                        <span class="nav-icon">
                                            <i class="ri-close-line fs-16"></i>
                                        </span>
                                        Mark as No Nursing Home Exp
                                    </button>
                                </div>
                                <!-- Button Dropdown -->
                            </div>
                        </div>
                    </div>
                    <div class="row justify-content-between align-items-center">
                        <div class="col-xl-12">
                            <div class="card">
                                <div class="card-body p-3">
                                    <div id="columnsToolbar" class="dropdown d-inline">
                                        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button"
                                            id="dropdownMenuColumns" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-layout-column-line me-1"></i> Columns
                                        </button>
                                        <div class="dropdown-menu filter-dropdowns p-2"
                                            aria-labelledby="dropdownMenuColumns" style="min-width: 230px;">
                                            <div class="d-flex justify-content-between align-items-center px-1 mb-2">
                                                <a href="#" id="columnsSelectAll"
                                                    class="text-primary small fw-semibold">Show All</a>
                                                <a href="#" id="columnsResetDefault"
                                                    class="text-secondary small fw-semibold">Reset Default</a>
                                            </div>
                                            <div id="columnsList" style="max-height: 280px; overflow-y: auto;"></div>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table id="applicants_table" class="table align-middle mb-3">
                                            <thead class="bg-light-subtle">
                                                <tr>
                                                    <th><input type="checkbox" id="master-checkbox"></th>
                                                    <th>Date</th>
                                                    <th>Applicant Name</th>
                                                    <th>Email</th>
                                                    <th>Title</th>
                                                    <th>Category</th>
                                                    <th>PostCode</th>
                                                    <th width="10%">Phone / Landline</th>
                                                    <th>Applicant Resume</th>
                                                    <th>CRM Resume</th>
                                                    <th>Experience</th>
                                                    <th>Source</th>
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

    {{-- Keep sale HTML modals outside the page layout so broken Summernote markup cannot wrap the filter button --}}
    <div class="modal fade" id="qualification-{{ $sale->id }}" tabindex="-1"
        aria-labelledby="qualification-{{ $sale->id }}-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="qualification-{{ $sale->id }}-label">Sale Qualification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {!! $qualificationHtml . $qualificationUrlButton !!}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="benefits-{{ $sale->id }}" tabindex="-1"
        aria-labelledby="benefits-{{ $sale->id }}-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="benefits-{{ $sale->id }}-label">Sale Benefits</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {!! $benefitsHtml . $benefitsUrlButton !!}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="experience-{{ $sale->id }}" tabindex="-1"
        aria-labelledby="experience-{{ $sale->id }}-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="experience-{{ $sale->id }}-label">Sale Experience</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {!! $experienceHtml . $experienceUrlButton !!}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
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
            var currentApplicantStatusFilter = '';
            var currentCvStatusFilter = '';
            var currentSourceFilters = [];

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

            const columnConfig = [{
                    title: 'Select',
                    toggleable: false
                },
                {
                    title: 'Date',
                    default: true
                },
                {
                    title: 'Applicant Name',
                    default: true
                },
                {
                    title: 'Email',
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
                    title: 'PostCode',
                    default: true
                },
                {
                    title: 'Phone / Landline',
                    default: true
                },
                {
                    title: 'Applicant Resume',
                    default: true
                },
                {
                    title: 'CRM Resume',
                    default: true
                },
                {
                    title: 'Experience',
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
                }
            ];
            const columnVisibilityStorageKey = 'sale_radius_applicants_column_visibility_v1';

            function loadColumnVisibility() {
                let stored = {};
                try {
                    stored = JSON.parse(localStorage.getItem(columnVisibilityStorageKey)) || {};
                } catch (error) {
                    stored = {};
                }

                return columnConfig.map(function(column, index) {
                    if (column.toggleable === false) {
                        return true;
                    }
                    return Object.prototype.hasOwnProperty.call(stored, index) ?
                        !!stored[index] :
                        !!column.default;
                });
            }

            function saveColumnVisibility() {
                const stored = {};
                columnConfig.forEach(function(column, index) {
                    if (column.toggleable !== false) {
                        stored[index] = !!columnVisibility[index];
                    }
                });
                localStorage.setItem(columnVisibilityStorageKey, JSON.stringify(stored));
            }

            let columnVisibility = loadColumnVisibility();

            function renderColumnsDropdown() {
                const list = $('#columnsList').empty();
                columnConfig.forEach(function(column, index) {
                    if (column.toggleable === false) {
                        return;
                    }

                    const checked = columnVisibility[index] ? 'checked' : '';
                    list.append(`
                        <div class="form-check">
                            <input class="form-check-input column-toggle" type="checkbox"
                                id="column_${index}" data-column-index="${index}" ${checked}>
                            <label class="form-check-label" for="column_${index}">${column.title}</label>
                        </div>
                    `);
                });
            }

            function getHiddenColumnIndices() {
                return columnVisibility
                    .map(function(visible, index) {
                        return visible ? null : index;
                    })
                    .filter(function(index) {
                        return index !== null;
                    });
            }

            renderColumnsDropdown();

            // Initialize DataTable with server-side processing
            var table = $('#applicants_table').DataTable({
                processing: false, // Disable default processing state
                serverSide: true, // Enables server-side processing
                ajax: {
                    url: @json(route('getApplicantsBySaleRadius')), // Fetch data from the backend
                    type: 'GET',
                    data: function(d) {
                        d.sale_id = {{ $sale->id }};
                        d.radius = {{ $radius }};
                        d.status_filter = currentApplicantStatusFilter;
                        d.cv_status_filter = currentCvStatusFilter;
                        d.title_filter = (typeof window.getVisibleListingTitleIds === 'function' ?
                            window.getVisibleListingTitleIds() : currentTitleFilters);
                        d.source_filter = currentSourceFilters;
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
                        data: 'checkbox',
                        'name': 'checkbox',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'notes_created_at',
                        name: 'notes_created_at'
                    },
                    {
                        data: 'applicant_name',
                        name: 'applicants.applicant_name'
                    },
                    {
                        data: 'applicant_email',
                        name: 'applicants.applicant_email'
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
                        data: 'applicant_postcode',
                        name: 'applicants.applicant_postcode'
                    },
                    {
                        data: 'applicantPhone',
                        name: 'applicantPhone',
                        orderable: false,
                        searchable: true
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
                        data: 'applicant_experience',
                        name: 'applicants.applicant_experience'
                    },
                    {
                        data: 'job_source',
                        name: 'job_sources.name'
                    },
                    {
                        data: 'applicantNotes',
                        name: 'applicantNotes',
                        orderable: false,
                        searchable: true
                    },
                    {
                        data: 'paid_status',
                        name: 'applicants.paid_status',
                        searchable: false
                    },
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
                        targets: getHiddenColumnIndices(),
                        visible: false
                    }
                ],
                createdRow: function(row, data, dataIndex) {
                    const firstCell = $('td:eq(0)', row); // first column only

                    if (data.have_nursing_home_experience == 1) {
                        firstCell.addClass('triangle-green')
                            .attr('title', 'Has Nursing Home Experience');
                    } else if (data.have_nursing_home_experience == 0) {
                        firstCell.addClass('triangle-red')
                            .attr('title', 'No Nursing Home Experience');
                    }
                },
                rowId: function(data) {
                    return 'row_' + data
                        .id; // Assign a unique ID to each row using the 'id' field from the data
                },
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

            $(document).on('change', '.column-toggle', function() {
                const index = parseInt($(this).data('column-index'), 10);
                columnVisibility[index] = $(this).is(':checked');
                table.column(index).visible(columnVisibility[index]);
                saveColumnVisibility();
            });

            $('#columnsSelectAll').on('click', function(event) {
                event.preventDefault();
                columnConfig.forEach(function(column, index) {
                    if (column.toggleable !== false) {
                        columnVisibility[index] = true;
                        table.column(index).visible(true);
                    }
                });
                saveColumnVisibility();
                renderColumnsDropdown();
            });

            $('#columnsResetDefault').on('click', function(event) {
                event.preventDefault();
                columnConfig.forEach(function(column, index) {
                    if (column.toggleable !== false) {
                        columnVisibility[index] = !!column.default;
                        table.column(index).visible(columnVisibility[index]);
                    }
                });
                saveColumnVisibility();
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
            $('.applicant-status-filter').on('click', function() {
                currentApplicantStatusFilter = $(this).text().toLowerCase().replace(/\s+/g, ' ').trim();
                if (currentApplicantStatusFilter === 'all applicant status') {
                    currentApplicantStatusFilter = '';
                }

                // Hide the Export dropdown when "Blocked" is selected
                const isBlocked = currentApplicantStatusFilter === 'blocked';
                $('#exportDropdown')
                    .removeClass('show')
                    .toggleClass('d-none', isBlocked);

                // Also close the inner menu in case it was open
                $('#exportDropdown .dropdown-menu').removeClass('show');

                const formattedText = $(this).text().replace(/\s+/g, ' ').trim();
                $('#showFilterApplicantStatus').html(formattedText);
                table.ajax.reload();
            });

            $('.cv-status-filter').on('click', function() {
                currentCvStatusFilter = $(this).text().toLowerCase().replace(/\s+/g, ' ').trim();
                if (currentCvStatusFilter === 'all cv status') {
                    currentCvStatusFilter = '';
                }

                const formattedText = $(this).text().replace(/\s+/g, ' ').trim();
                $('#showFilterCvStatus').html(formattedText);
                table.ajax.reload();
            });

            function selectedFilterIds(selector, dataKey) {
                const ids = [];
                $(selector + ':checked').each(function() {
                    const id = $(this).data(dataKey);
                    if (id !== '' && id !== null && typeof id !== 'undefined') {
                        ids.push(id);
                    }
                });
                return ids;
            }

            function refreshSourceFilterUi() {
                currentSourceFilters = selectedFilterIds('.source-filter', 'source-id');
                const total = $('.source-filter').not('[data-source-id=""]').length;
                const checked = currentSourceFilters.length;
                $('#showFilterSource').text(checked > 0 ? `Selected Source (${checked})` : 'All Sources');
                const container = $('#sourceToggleContainer');
                container.find('.filter-select-all').toggle(checked < total);
                container.find('.filter-deselect-all').toggle(checked > 0);
            }

            $('.source-filter').on('change', function() {
                const id = $(this).data('source-id');
                if (id === '' || id === undefined) {
                    $('.source-filter').not(this).prop('checked', false);
                } else {
                    $('.source-filter[data-source-id=""]').prop('checked', false);
                }
                refreshSourceFilterUi();
                table.ajax.reload();
            });

            $(document).on('click', '.filter-select-all', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const filterClass = $(this).data('target');
                const excludeAttr = $(this).data('exclude');
                $(filterClass + excludeAttr).prop('checked', false);
                $(filterClass).not(excludeAttr).prop('checked', true);

                refreshSourceFilterUi();

                table.ajax.reload();
            });

            $(document).on('click', '.filter-deselect-all', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const filterClass = $(this).data('target');
                const excludeAttr = $(this).data('exclude');
                $(filterClass).not(excludeAttr).prop('checked', false);

                refreshSourceFilterUi();

                table.ajax.reload();
            });

            $(document).on('click', '.filter-dropdowns', function(e) {
                e.stopPropagation();
            });

            $('#sourceSearchInput').on('keyup', function() {
                const searchValue = this.value.toLowerCase();
                $('#sourceList .form-check').each(function() {
                    const label = $(this).find('label').text().toLowerCase();
                    $(this).toggle(label.includes(searchValue));
                });
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
        // Function to mark as not interested modal
        function markNotInterestedModal(applicantID, saleID) {
            const modalId = `markApplicantNotInterestedModal_${applicantID}`;
            const formId = `markApplicantNotInterestedForm_${applicantID}`;
            const textareaId = `detailsTextareaApplicantNotInterested_${applicantID}`;
            const saveBtnId = `saveApplicantNotInterestedButton_${applicantID}`;

            // Check and append modal if not already present
            if ($(`#${modalId}`).length === 0) {
                $('body').append(
                    `<div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
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
                                    <button type="button" class="btn btn-success" id="${saveBtnId}">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>`
                );
            }

            // Show modal
            $(`#${modalId}`).modal('show');

            // Handle save
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

                // Remove error states
                $(`#${textareaId}`).removeClass('is-invalid').addClass('is-valid');
                $(`#${textareaId}`).next('.invalid-feedback').remove();

                // Submit via AJAX
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
                        $(`#${textareaId}`).removeClass('is-valid');
                        $(`#${textareaId}`).next('.invalid-feedback').remove();
                        $('#applicants_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        toastr.error('An error occurred while saving notes.');
                    }
                });
            });
        }
        // Function to show the notes modal
        function addShortNotesModal(applicantID) {
            const modalId = 'shortNotesModal-' + applicantID;

            // If modal doesn't exist yet, append it
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label" aria-hidden="true">' +
                    '<div class="modal-dialog modal-md modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Add Notes</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="shortNotesForm-' + applicantID + '">' +
                    '<div class="mb-3">' +
                    '<label for="detailsTextarea-' + applicantID + '" class="form-label">Details</label>' +
                    '<textarea class="form-control" id="detailsTextarea-' + applicantID +
                    '" rows="4" required></textarea>' +
                    '</div>' +
                    '<div class="mb-3">' +
                    '<label for="reasonDropdown-' + applicantID + '" class="form-label">Reason</label>' +
                    '<select class="form-select" id="reasonDropdown-' + applicantID + '" required>' +
                    '<option value="" disabled selected>Select Reason</option>' +
                    '<option value="casual">Casual Notes</option>' +
                    '<option value="blocked">Blocked Notes</option>' +
                    '<option value="not_interested">Temp Not Interested Notes</option>' +
                    '</select>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-success saveShortNotesButton" data-applicant-id="' +
                    applicantID + '">Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Reset form fields on open
            $('#shortNotesForm-' + applicantID)[0].reset();
            $('#detailsTextarea-' + applicantID).removeClass('is-valid is-invalid');
            $('#reasonDropdown-' + applicantID).removeClass('is-valid is-invalid');
            $('#detailsTextarea-' + applicantID).next('.invalid-feedback').remove();
            $('#reasonDropdown-' + applicantID).next('.invalid-feedback').remove();

            // Show modal
            $('#' + modalId).modal('show');

            // Handle save button click (unbind previous first)
            $('.saveShortNotesButton').off('click').on('click', function() {
                const id = $(this).data('applicant-id');
                const notes = $('#detailsTextarea-' + id).val();
                const reason = $('#reasonDropdown-' + id).val();

                // Validation
                let isValid = true;

                if (!notes) {
                    $('#detailsTextarea-' + id).addClass('is-invalid');
                    if ($('#detailsTextarea-' + id).next('.invalid-feedback').length === 0) {
                        $('#detailsTextarea-' + id).after(
                            '<div class="invalid-feedback">Please provide details.</div>');
                    }
                    isValid = false;
                }

                if (!reason) {
                    $('#reasonDropdown-' + id).addClass('is-invalid');
                    if ($('#reasonDropdown-' + id).next('.invalid-feedback').length === 0) {
                        $('#reasonDropdown-' + id).after(
                            '<div class="invalid-feedback">Please select a reason.</div>');
                    }
                    isValid = false;
                }

                if (!isValid) {
                    // Attach dynamic error removal
                    $('#detailsTextarea-' + id).on('input', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    $('#reasonDropdown-' + id).on('change', function() {
                        if ($(this).val()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Submit via AJAX
                $.ajax({
                    url: '{{ route('storeShortNotes') }}',
                    type: 'POST',
                    data: {
                        applicant_id: id,
                        details: notes,
                        reason: reason,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        toastr.success('Notes saved successfully!');
                        $('#' + modalId).modal('hide');
                        $('#shortNotesForm-' + id)[0].reset();
                        $('#applicants_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        toastr.error('An error occurred while saving notes.');
                    }
                });
            });
        }
        // Function to show the notes modal
        function showNotesModal(applicantID, notes, applicantName, applicantPostcode) {
            let modalId = 'showNotesModal-' + applicantID;

            // If the modal does not exist yet, create and append it
            if ($('#' + modalId).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId +
                    'Label" aria-hidden="true">' +
                    '<div class="modal-dialog modal-md modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalId + 'Label">Applicant Notes</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<!-- Content will be inserted dynamically -->' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Populate modal content
            $('#' + modalId + ' .modal-body').html(
                'Applicant Name: <strong>' + applicantName + '</strong><br>' +
                'Postcode: <strong>' + applicantPostcode + '</strong><br>' +
                'Notes Detail:<p>' + notes + '</p>'
            );

            // Show the modal
            $('#' + modalId).modal('show');
        }
        // Function to send cv modal
        function sendCVModal(applicantID, saleID, applicantPostcode, applicantNursingHomeExp) {
            const modalID = 'sendCVModal' + applicantID + '-' + saleID;
            const formID = 'sendCV_form' + applicantID + '-' + saleID;
            const updateNurseFields = function() {
                const form = $('#' + formID);
                const category = form.find('[name="category"]:checked').val();
                const isNonNurse = category === 'Non Nurse';
                const isNursery = category === 'Nursery';
                const hideNurseFields = category !== 'Nurse';
                form.find('[data-send-cv-page="2"] [data-send-cv-question] .form-label').each(function() {
                    const label = $(this);
                    if (label.data('original-label') === undefined) {
                        label.data('original-label', label.text());
                    }
                    label.text(label.data('original-label'));
                });
                form.find('[data-nurse-label]').each(function() {
                    const label = $(this);
                    label.text(isNursery
                        ? label.data('nursery-label')
                        : isNonNurse
                            ? label.data('non-nurse-label')
                            : label.data('nurse-label'));
                });
                form.find('[data-nurse-only]').each(function() {
                    const fields = $(this).find(':input');
                    $(this).toggleClass('d-none', hideNurseFields);
                    fields.prop('disabled', hideNurseFields);
                    if (hideNurseFields) {
                        fields.filter(':checkbox').prop('checked', false);
                        fields.filter('textarea').val('');
                    }
                });
                let questionNumber = 5;
                form.find('[data-send-cv-page="2"] [data-send-cv-question]').each(function() {
                    if ($(this).hasClass('d-none')) {
                        return;
                    }
                    const label = $(this).find('.form-label').first();
                    label.text(label.text().replace(/^\d+\.\s*/, questionNumber++ + '. '));
                });
            };

            // Add modal if not exists
            if ($('#' + modalID).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalID +
                    '" tabindex="-1" aria-labelledby="' + modalID + 'Label" aria-hidden="true">' +
                    '<div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalID + 'Label">Fill Out Form To Send CV</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form class="form-horizontal" id="' + formID + '">' +
                    '<input type="hidden" name="applicant_id" value="' + applicantID + '">' +
                    '<input type="hidden" name="sale_id" value="' + saleID + '">' +
                    '<input type="hidden" name="_token" value="{{ csrf_token() }}">' +

                    '<div data-send-cv-page="2" class="d-none">' +
                    '<div class="card border-0 shadow-sm mb-4">' +
                    '<div class="card-header bg-light">' +
                    '<h6 class="mb-1">Applicant Information</h6>' +
                    '<small class="text-muted">Add the applicant’s statement for each question.</small>' +
                    '</div>' +
                    '<div class="card-body">' +
                    '<div class="row g-3">' +
                    '<div class="col-12">' +
                    '<label class="form-label d-block">Category</label>' +
                    '<div class="form-check form-check-inline">' +
                    '<input class="form-check-input" type="radio" name="category" id="category_nurse_' + applicantID +
                    '_' + saleID + '" value="Nurse" checked>' +
                    '<label class="form-check-label" for="category_nurse_' + applicantID + '_' + saleID +
                    '">Nurse</label>' +
                    '</div>' +
                    '<div class="form-check form-check-inline">' +
                    '<input class="form-check-input" type="radio" name="category" id="category_non_nurse_' +
                    applicantID + '_' + saleID + '" value="Non Nurse">' +
                    '<label class="form-check-label" for="category_non_nurse_' + applicantID + '_' + saleID +
                    '">Non Nurse</label>' +
                    '</div>' +
                    '<div class="form-check form-check-inline">' +
                    '<input class="form-check-input" type="radio" name="category" id="category_nursery_' +
                    applicantID + '_' + saleID + '" value="Nursery">' +
                    '<label class="form-check-label" for="category_nursery_' + applicantID + '_' + saleID +
                    '">Nursery</label>' +
                    '</div>' +
                    '<div class="text-danger small d-none" data-category-error>Please select a category.</div>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question data-nurse-only>' +
                    '<label class="form-label" for="nmc_pin_status_' + applicantID + '_' + saleID +
                    '">5. NMC PIN Status</label>' +
                    '<textarea name="nmc_pin_status" id="nmc_pin_status_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the applicant’s NMC PIN status"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="notice_period_' + applicantID + '_' + saleID +
                    '">6. Notice Period</label>' +
                    '<textarea name="notice_period" id="notice_period_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the applicant’s notice period"></textarea>' +
                    '</div>' +
                    '<div class="col-12" data-send-cv-question data-nurse-only>' +
                    '<label class="form-label d-block">7. Clinical Skills</label>' +
                    '<div class="row g-2">' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_wound_care_' +
                    applicantID + '_' + saleID + '" value="Wound Care">' +
                    '<label class="form-check-label" for="clinical_wound_care_' + applicantID + '_' + saleID +
                    '">Wound Care</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_catheterization_' +
                    applicantID + '_' + saleID + '" value="Catheterization">' +
                    '<label class="form-check-label" for="clinical_catheterization_' + applicantID + '_' + saleID +
                    '">Catheterization</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_venepuncture_' +
                    applicantID + '_' + saleID + '" value="Venepuncture">' +
                    '<label class="form-check-label" for="clinical_venepuncture_' + applicantID + '_' + saleID +
                    '">Venepuncture</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_peg_feeding_' +
                    applicantID + '_' + saleID + '" value="PEG Feeding">' +
                    '<label class="form-check-label" for="clinical_peg_feeding_' + applicantID + '_' + saleID +
                    '">PEG Feeding</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_tracheostomy_care_' +
                    applicantID + '_' + saleID + '" value="Tracheostomy Care">' +
                    '<label class="form-check-label" for="clinical_tracheostomy_care_' + applicantID + '_' + saleID +
                    '">Tracheostomy Care</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_suctioning_' +
                    applicantID + '_' + saleID + '" value="Suctioning">' +
                    '<label class="form-check-label" for="clinical_suctioning_' + applicantID + '_' + saleID +
                    '">Suctioning</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_dementia_care_' +
                    applicantID + '_' + saleID + '" value="Dementia Care">' +
                    '<label class="form-check-label" for="clinical_dementia_care_' + applicantID + '_' + saleID +
                    '">Dementia Care</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="clinical_skills[]" id="clinical_palliative_care_' +
                    applicantID + '_' + saleID + '" value="Palliative Care">' +
                    '<label class="form-check-label" for="clinical_palliative_care_' + applicantID + '_' + saleID +
                    '">Palliative Care</label>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="visa_status_' + applicantID + '_' + saleID +
                    '">8. Visa Status</label>' +
                    '<textarea name="visa_status" id="visa_status_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the applicant’s visa status"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="dbs_convictions_' + applicantID + '_' + saleID +
                    '">9. Convictions on DBS</label>' +
                    '<textarea name="dbs_convictions" id="dbs_convictions_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Record the applicant’s statement"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="expected_hourly_rate_' + applicantID + '_' + saleID +
                    '">10. Expected Hourly Rate</label>' +
                    '<textarea name="expected_hourly_rate" id="expected_hourly_rate_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the expected hourly rate"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="holidays_booked_' + applicantID + '_' + saleID +
                    '">11. Holidays Booked</label>' +
                    '<textarea name="holidays_booked" id="holidays_booked_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Record any holidays already booked"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="reasons_for_job_change_' + applicantID + '_' + saleID +
                    '">12. Reasons for Job Change</label>' +
                    '<textarea name="reasons_for_job_change" id="reasons_for_job_change_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Record the applicant’s reasons"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="nursing_experience_years_' + applicantID + '_' + saleID +
                    '" data-nurse-label="13. Years of Experience as a Nurse" data-non-nurse-label="13. Years of Experience as a Carer, care assistant or support worker" data-nursery-label="13. Years of Experience as a Nursery">13. Years of Experience as a Nurse</label>' +
                    '<textarea name="nursing_experience_years" id="nursing_experience_years_' + applicantID + '_' +
                    saleID + '" class="form-control" rows="2" placeholder="Enter years of experience"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="nursing_home_experience_' + applicantID + '_' + saleID +
                    '" data-nurse-label="14. Years of Experience as a Nurse in Nursing Home" data-non-nurse-label="14. Years of Experience as a Carer, care assistant or support worker" data-nursery-label="14. Years of Experience as a nursery day care center">14. Years of Experience as a Nurse in Nursing Home</label>' +
                    '<textarea name="nursing_home_experience" id="nursing_home_experience_' + applicantID + '_' +
                    saleID +
                    '" class="form-control" rows="2" placeholder="Record the applicant’s nursing home experience"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="manager_rota_' + applicantID + '_' + saleID +
                    '">15. Working According to the Manager’s Rota</label>' +
                    '<textarea name="manager_rota" id="manager_rota_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Record the applicant’s response"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="current_dbs_status_' + applicantID + '_' + saleID +
                    '">16. Current DBS Status</label>' +
                    '<textarea name="current_dbs_status" id="current_dbs_status_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the current DBS status"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="expected_salary_' + applicantID + '_' + saleID +
                    '">17. Expected Salary</label>' +
                    '<textarea name="expected_salary" id="expected_salary_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the expected salary"></textarea>' +
                    '</div>' +
                    '<div class="col-12 col-md-6" data-send-cv-question>' +
                    '<label class="form-label" for="qualification_' + applicantID + '_' + saleID +
                    '">18. Qualification</label>' +
                    '<textarea name="qualification" id="qualification_' + applicantID + '_' + saleID +
                    '" class="form-control" rows="2" placeholder="Enter the qualification"></textarea>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div data-send-cv-page="1">' +
                    '<div class="card border-0 shadow-sm mb-4">' +
                    '<div class="card-header bg-light">' +
                    '<h6 class="mb-1">First Information</h6>' +
                    '<small class="text-muted">Add transport, shift, and availability details.</small>' +
                    '</div>' +
                    '<div class="card-body send-cv-existing-fields">' +
                    '<div class="row g-3">' +
                    '<div class="col-12">' +
                    '<label class="form-label d-block">1. Transport Type</label>' +
                    '<div class="send-cv-questionnaire-options">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="transport_type[]" id="by_walk_' +
                    applicantID + '_' + saleID + '" value="By Walk"><label class="form-check-label" for="by_walk_' +
                    applicantID + '_' + saleID + '">By Walk</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="transport_type[]" id="cycle_' + applicantID +
                    '_' + saleID + '" value="Cycle">' +
                    '<label class="form-check-label" for="cycle_' + applicantID + '_' + saleID + '">Cycle</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="transport_type[]" id="car_' + applicantID +
                    '_' + saleID + '" value="Car">' +
                    '<label class="form-check-label" for="car_' + applicantID + '_' + saleID + '">Car</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="transport_type[]" id="public_transport_' +
                    applicantID + '_' + saleID + '" value="Public Transport">' +
                    '<label class="form-check-label" for="public_transport_' + applicantID + '_' + saleID +
                    '">Public Transport</label>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12">' +
                    '<label class="form-label d-block">2. Shift Pattern</label>' +
                    '<div class="send-cv-questionnaire-options">' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="shift_pattern[]" id="day_' + applicantID +
                    '_' + saleID + '" value="Day">' +
                    '<label class="form-check-label" for="day_' + applicantID + '_' + saleID + '">Day</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="shift_pattern[]" id="night_' + applicantID +
                    '_' + saleID + '" value="Night">' +
                    '<label class="form-check-label" for="night_' + applicantID + '_' + saleID + '">Night</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="shift_pattern[]" id="full_time_' +
                    applicantID + '_' + saleID + '" value="Full Time">' +
                    '<label class="form-check-label" for="full_time_' + applicantID + '_' + saleID +
                    '">Full Time</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="shift_pattern[]" id="part_time_' +
                    applicantID + '_' + saleID + '" value="Part Time">' +
                    '<label class="form-check-label" for="part_time_' + applicantID + '_' + saleID +
                    '">Part Time</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="shift_pattern[]" id="twenty_four_hours_' +
                    applicantID + '_' + saleID + '" value="24 hours">' +
                    '<label class="form-check-label" for="twenty_four_hours_' + applicantID + '_' + saleID +
                    '">24 Hours</label>' +
                    '</div>' +
                    '<div class="form-check">' +
                    '<input class="form-check-input" type="checkbox" name="shift_pattern[]" id="day_night_' +
                    applicantID + '_' + saleID + '" value="Day/Night">' +
                    '<label class="form-check-label" for="day_night_' + applicantID + '_' + saleID +
                    '">Day/Night</label>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="form-group row mb-2">' +

                    '<div class="col-12">' +
                    '<div class="row g-3 mt-2">' +
                    '<div class="col-12 col-sm-6">' +
                    '<label class="form-label" for="interview_option_1_' + applicantID + '_' + saleID +
                    '">3. Interview Availability Same Week</label>' +
                    '<input class="form-control" type="datetime-local" name="interview_option_1" id="interview_option_1_' +
                    applicantID + '_' + saleID + '" required>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6">' +
                    '<label class="form-label" for="interview_option_2_' + applicantID + '_' + saleID +
                    '">4. Interview Availability Next Week</label>' +
                    '<input class="form-control" type="datetime-local" name="interview_option_2" id="interview_option_2_' +
                    applicantID + '_' + saleID + '" required>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 my-2">' +
                    '<div class="form-check form-switch">' +
                    '<input class="form-check-input" type="checkbox" name="hangup_call" role="switch" id="hangup_call_checkbox_' +
                    applicantID + '_' + saleID + '" value="1">' +
                    '<label class="form-check-label" for="hangup_call_checkbox_' + applicantID + '_' + saleID +
                    '">Call Hung up/Not Interested</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 my-2">' +
                    '<div class="form-check form-switch">' +
                    '<input class="form-check-input" type="checkbox" role="switch" name="no_job" id="no_job_checkbox_' +
                    applicantID + '_' + saleID + '" value="1">' +
                    '<label class="form-check-label" for="no_job_checkbox_' + applicantID + '_' + saleID +
                    '">No Job</label>' +
                    '</div>' +
                    '</div>' +
                    '<div class="form-group mb-0">' +
                    '<label class="col-form-label col-sm-12" for="note_details_' + applicantID + '_' + saleID +
                    '">Other Details <span class="text-danger" data-details-required-indicator>*</span></label>' +
                    '<div class="col-sm-12">' +
                    '<textarea name="details" id="note_details_' + applicantID + '_' + saleID +
                    '" class="form-control" cols="30" rows="4" placeholder="Type here ..." required></textarea>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-outline-secondary d-none" id="backCVBtn_' + applicantID +
                    '_' + saleID + '">Back</button>' +
                    '<button type="button" class="btn btn-success" id="saveCVBtn_' + applicantID + '_' + saleID +
                    '">Next</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
                const formElement = $('#' + formID);
                const pageOne = formElement.find('[data-send-cv-page="1"]');
                const pageTwo = formElement.find('[data-send-cv-page="2"]');
                const submitButton = $('#saveCVBtn_' + applicantID + '_' + saleID);
                const backButton = $('#backCVBtn_' + applicantID + '_' + saleID);
                const hangupCall = formElement.find('[name="hangup_call"]');
                const noJob = formElement.find('[name="no_job"]');
                const detailsField = formElement.find('[name="details"]');
                let currentPage = 1;
                const isShortcutSubmission = function() {
                    return hangupCall.is(':checked') || noJob.is(':checked');
                };

                const showPageOne = function() {
                    currentPage = 1;
                    pageOne.removeClass('d-none');
                    pageTwo.addClass('d-none');
                    backButton.addClass('d-none');
                    submitButton.text(isShortcutSubmission() ? 'Submit' : 'Next');
                };
                const showPageTwo = function() {
                    currentPage = 2;
                    pageOne.addClass('d-none');
                    pageTwo.removeClass('d-none');
                    backButton.removeClass('d-none');
                    submitButton.text('Submit');
                };
                const updateShortcutNavigation = function() {
                    const isShortcut = isShortcutSubmission();
                    detailsField.prop('required', !isShortcut);
                    formElement.find('[data-details-required-indicator]')
                        .toggleClass('d-none', isShortcut);
                    if (isShortcut) {
                        showPageOne();
                        pageTwo.find('.is-invalid').removeClass('is-invalid');
                        pageTwo.find('.invalid-feedback').remove();
                    } else if (currentPage === 2) {
                        submitButton.text('Submit');
                    } else {
                        submitButton.text('Next');
                    }
                };

                backButton.off('click').on('click', showPageOne);
                formElement.off('change', '[name="category"]').on('change', '[name="category"]', function() {
                    updateNurseFields();
                    formElement.find('[data-category-error]').addClass('d-none');
                });
                formElement.off('change', '[name="no_job"], [name="hangup_call"]').on('change',
                    '[name="no_job"], [name="hangup_call"]',
                    function() {
                        if (this.checked) {
                            formElement.find('[name="no_job"], [name="hangup_call"]').not(this).prop('checked', false);
                        }
                        updateShortcutNavigation();
                    });

                // Handle save button click - using event delegation
                $(document).off('click', '#saveCVBtn_' + applicantID + '_' + saleID).on('click', '#saveCVBtn_' +
                    applicantID + '_' + saleID,
                    function() {
                        const form = $('#' + formID);
                        const isHangupCall = hangupCall.is(':checked');
                        const isShortcut = isShortcutSubmission();
                        if (!isShortcut && currentPage === 1) {
                            showPageTwo();
                            return;
                        }

                        const notes = form.find('[name="details"]').val();
                        let isValid = true;

                        // Reset validation
                        form.find('.is-invalid').removeClass('is-invalid');
                        form.find('.invalid-feedback').remove();

                        if (!isShortcut && !form.find('[name="category"]:checked').length) {
                            form.find('[data-category-error]').removeClass('d-none');
                            isValid = false;
                        }

                        // Validate required fields
                        if (!isShortcut && !notes) {
                            form.find('[name="details"]').addClass('is-invalid')
                                .after('<div class="invalid-feedback">Please enter note details.</div>');
                            isValid = false;
                        }

                        if (!isShortcut) {
                            ['interview_option_1', 'interview_option_2'].forEach(function(fieldName) {
                                const field = form.find('[name="' + fieldName + '"]');
                                if (!field.val()) {
                                    field.addClass('is-invalid')
                                        .after('<div class="invalid-feedback">Please select ' +
                                            (fieldName === 'interview_option_1' ?
                                                'Interview Availability Same Week.' :
                                                'Interview Availability Next Week.') +
                                            '</div>');
                                    isValid = false;
                                }
                            });
                        }

                        // Validate at least one transport type is selected
                        if (!isShortcut && form.find('[name="transport_type[]"]:checked').length === 0) {
                            form.find('[name="transport_type[]"]').first().closest('.col-12')
                                .append(
                                    '<div class="invalid-feedback d-block">Please select at least one transport type.</div>'
                                );
                            isValid = false;
                        }

                        // Validate visa status is provided
                        const visaStatus = form.find('[name="visa_status"]');
                        if (!isShortcut && !visaStatus.val().trim()) {
                            visaStatus.addClass('is-invalid')
                                .after('<div class="invalid-feedback">Please enter visa status.</div>');
                            isValid = false;
                        }

                        if (!isValid) {
                            return false;
                        }

                        // Show loading state
                        const btn = $(this);
                        const originalText = btn.html();
                        btn.prop('disabled', true).html(
                            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...'
                        );

                        // Submit form via AJAX
                        $.ajax({
                            url: isShortcut ? '{{ route('saveSendCvHangupNote') }}' :
                                '{{ route('sendCVtoQuality') }}',
                            type: 'POST',
                            data: form.serialize(),
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
                                    $('#' + modalID).modal('hide');
                                    $('#applicants_table').DataTable().ajax.reload();
                                } else {
                                    toastr.error(response.message || 'Failed to send CV');
                                }
                            },
                            error: function(xhr) {
                                let message = 'An error occurred';
                                if (xhr.responseJSON && xhr.responseJSON.message) {
                                    message = xhr.responseJSON.message;
                                } else if (xhr.statusText) {
                                    message = xhr.statusText;
                                }
                                toastr.error(message);
                            },
                            complete: function() {
                                btn.prop('disabled', false).html(originalText);
                            }
                        });
                    });
            }

            // Reset form when modal shows
            $('#' + modalID).on('show.bs.modal', function() {
                const form = $(this).find('form');
                form[0].reset();
                form.find('[data-send-cv-page="1"]').removeClass('d-none');
                form.find('[data-send-cv-page="2"]').addClass('d-none');
                form.find('[name="details"]').prop('required', true);
                form.find('[data-details-required-indicator]').removeClass('d-none');
                form.find('[data-category-error]').addClass('d-none');
                updateNurseFields();
                $('#backCVBtn_' + applicantID + '_' + saleID).addClass('d-none');
                $('#saveCVBtn_' + applicantID + '_' + saleID).text('Next');
                $(this).find('.is-invalid').removeClass('is-invalid');
                $(this).find('.invalid-feedback').remove();
            });

            // Show modal
            $('#' + modalID).modal('show');
        }
        // Function to mark no nursing home modal
        function markNoNursingHomeModal(applicantID) {
            const modalID = 'markNoNursingHomeModal_' + applicantID;
            const textareaID = 'detailsTextareaMarkNoNursingHome_' + applicantID;
            const formID = 'markNoNursingHomeForm_' + applicantID;
            const saveButtonID = 'saveMarkNoNursingHomeButton_' + applicantID;

            // Add the modal HTML to the page if not already present
            if ($('#' + modalID).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalID + '" tabindex="-1" aria-labelledby="' + modalID +
                    'Label" aria-hidden="true">' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalID + 'Label">Mark As No Nursing Home</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="' + formID + '">' +
                    '<div class="mb-3">' +
                    '<label for="' + textareaID + '" class="form-label">Details</label>' +
                    '<textarea class="form-control" id="' + textareaID + '" rows="4" required></textarea>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-success" id="' + saveButtonID + '">Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Reset the form and validation before showing
            $('#' + formID)[0].reset();
            $('#' + textareaID).removeClass('is-valid is-invalid');
            $('#' + textareaID).next('.invalid-feedback').remove();

            // Show the modal
            $('#' + modalID).modal('show');

            // Handle save button click (unbind previous first)
            $('#' + saveButtonID).off('click').on('click', function() {
                const notes = $('#' + textareaID).val().trim();

                if (!notes) {
                    $('#' + textareaID).addClass('is-invalid');
                    if ($('#' + textareaID).next('.invalid-feedback').length === 0) {
                        $('#' + textareaID).after('<div class="invalid-feedback">Please provide details.</div>');
                    }

                    // Handle live validation
                    $('#' + textareaID).on('input', function() {
                        if ($(this).val().trim()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Clear validation states
                $('#' + textareaID).removeClass('is-invalid').addClass('is-valid');
                $('#' + textareaID).next('.invalid-feedback').remove();

                // Send AJAX request
                $.ajax({
                    url: '{{ route('markApplicantNoNursingHome') }}',
                    type: 'POST',
                    data: {
                        applicant_id: applicantID,
                        details: notes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function() {
                        toastr.success('Marked successfully!');
                        $('#' + modalID).modal('hide');
                        $('#' + formID)[0].reset();
                        $('#' + textareaID).removeClass('is-valid');
                        $('#' + textareaID).next('.invalid-feedback').remove();
                        $('#applicants_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        toastr.error('An error occurred while saving notes.');
                    }
                });
            });
        }
        // Function to mark no nursing home modal
        function markApplicantCallbackModal(applicantID, saleID) {
            const modalID = 'markApplicantCallbackModal_' + applicantID;
            const textareaID = 'detailsTextareaMarkCallback_' + applicantID;
            const formID = 'markCallbackForm_' + applicantID;
            const saveButtonID = 'saveMarkCallbackButton_' + applicantID;

            // Add the modal HTML only once for this applicant
            if ($('#' + modalID).length === 0) {
                $('body').append(
                    '<div class="modal fade" id="' + modalID + '" tabindex="-1" aria-labelledby="' + modalID +
                    'Label" aria-hidden="true">' +
                    '<div class="modal-dialog modal-lg modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="' + modalID + 'Label">Mark As Callback</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                    '<form id="' + formID + '">' +
                    '<div class="mb-3">' +
                    '<label for="' + textareaID + '" class="form-label">Details</label>' +
                    '<textarea class="form-control" id="' + textareaID + '" rows="4" required></textarea>' +
                    '</div>' +
                    '</form>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cancel</button>' +
                    '<button type="button" class="btn btn-success" id="' + saveButtonID + '">Save</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            }

            // Reset and show the modal
            $('#' + formID)[0].reset();
            $('#' + textareaID).removeClass('is-valid is-invalid');
            $('#' + textareaID).next('.invalid-feedback').remove();
            $('#' + modalID).modal('show');

            // Handle Save button click
            $('#' + saveButtonID).off('click').on('click', function() {
                const notes = $('#' + textareaID).val().trim();

                if (!notes) {
                    $('#' + textareaID).addClass('is-invalid');
                    if ($('#' + textareaID).next('.invalid-feedback').length === 0) {
                        $('#' + textareaID).after('<div class="invalid-feedback">Please provide details.</div>');
                    }

                    // Live validation
                    $('#' + textareaID).on('input', function() {
                        if ($(this).val().trim()) {
                            $(this).removeClass('is-invalid').addClass('is-valid');
                            $(this).next('.invalid-feedback').remove();
                        }
                    });

                    return;
                }

                // Clear validation
                $('#' + textareaID).removeClass('is-invalid').addClass('is-valid');
                $('#' + textareaID).next('.invalid-feedback').remove();

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
                    success: function() {
                        toastr.success('Mark callback saved successfully!');
                        $('#' + modalID).modal('hide');
                        $('#' + formID)[0].reset();
                        $('#' + textareaID).removeClass('is-valid');
                        $('#' + textareaID).next('.invalid-feedback').remove();
                        $('#applicants_table').DataTable().ajax.reload();
                    },
                    error: function() {
                        toastr.error('An error occurred while saving notes.');
                    }
                });
            });
        }
        // Disable the Unblock button by default
        $('#markNursingHomeBtn').prop('disabled', true);
        $('#markNoNursingHomeBtn').prop('disabled', true);

        // Enable the Unblock button when any checkbox is checked, disable if none checked
        $(document).on('change', '.applicant_checkbox, #master-checkbox', function() {
            var anyChecked = $('.applicant_checkbox:checked').length > 0;
            $('#markNursingHomeBtn').prop('disabled', !anyChecked);
            $('#markNoNursingHomeBtn').prop('disabled', !anyChecked);
        });

        $('#master-checkbox').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('.applicant_checkbox').prop('checked', isChecked);

            // Manually toggle the DataTables selected class
            $('.applicant_checkbox').each(function() {
                var $row = $(this).closest('tr');
                if (isChecked) {
                    $row.addClass('selected');
                } else {
                    $row.removeClass('selected');
                }
            });
        });

        // Add a listener to individual checkboxes to update the master checkbox state
        $(document).on('change', '.applicant_checkbox', function() {
            var allCheckboxesChecked = $('.applicant_checkbox:checked').length === $('.applicant_checkbox').length;
            $('#master-checkbox').prop('checked', allCheckboxesChecked);

            // Manually toggle the DataTables selected class
            var $row = $(this).closest('tr');
            if ($(this).prop('checked')) {
                $row.addClass('selected');
            } else {
                $row.removeClass('selected');
            }
        });

        // Handle the button click to send an AJAX request
        $('#markNursingHomeBtn').on('click', function() {
            // Get all the selected checkboxes
            var selectedCheckboxes = [];
            $('.applicant_checkbox:checked').each(function() {
                selectedCheckboxes.push($(this)
                    .val()); // Push the value of the checked checkboxes to the array
            });

            const btn = $(this);
            const originalText = btn.html();
            btn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
            );

            if (selectedCheckboxes.length > 0) {
                // Send the selected values in an AJAX request
                $.ajax({
                    url: "{{ route('markAsNursingHomeExp') }}",
                    method: 'POST',
                    data: {
                        selectedCheckboxes: selectedCheckboxes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        // Hide the rows that were successfully updated
                        selectedCheckboxes.forEach(function(rowId) {
                            $('#' + rowId).fadeOut(500); // 500ms = half-second fade

                            // 2. Uncheck the checkbox based on matching value
                            $('input.applicant_checkbox[value="' + rowId + '"]').prop('checked',
                                false);
                        });

                        toastr.success('Marked nursing home experience successfully!');

                        $('#applicants_table').DataTable().ajax.reload();

                        console.log(response); // You can log the server response for debugging
                    },
                    error: function(error) {
                        // Handle the error response here
                        toastr.error('Something went wrong. Please try again.');
                        console.error(error);
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                        $('#markNoNursingHomeBtn').prop('disabled', true);
                    }
                });
            } else {
                toastr.error('Please select at least one checkbox.');
            }
        });

        // Handle the button click to send an AJAX request
        $('#markNoNursingHomeBtn').on('click', function() {
            // Get all the selected checkboxes
            var selectedCheckboxes = [];
            $('.applicant_checkbox:checked').each(function() {
                selectedCheckboxes.push($(this)
                    .val()); // Push the value of the checked checkboxes to the array
            });

            const btn = $(this);
            const originalText = btn.html();
            btn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...'
            );

            if (selectedCheckboxes.length > 0) {
                // Send the selected values in an AJAX request
                $.ajax({
                    url: "{{ route('markAsNoNursingHomeExp') }}",
                    method: 'POST',
                    data: {
                        selectedCheckboxes: selectedCheckboxes,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        // Hide the rows that were successfully updated
                        selectedCheckboxes.forEach(function(rowId) {
                            $('#' + rowId).addClass('marked-no-nursing-home');

                            // 2. Uncheck the checkbox based on matching value
                            $('input.applicant_checkbox[value="' + rowId + '"]').prop('checked',
                                false);
                        });

                        toastr.success('Marked no nursing home experience successfully!');

                        $('#applicants_table').DataTable().ajax.reload();
                        console.log(response); // You can log the server response for debugging
                    },
                    error: function(error) {
                        // Handle the error response here
                        toastr.error('Something went wrong. Please try again.');
                        console.error(error);
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                        $('#markNursingHomeBtn').prop('disabled', true);
                    }
                });
            } else {
                toastr.error('Please select at least one checkbox.');
            }
        });

        // Function to show the job description modal
        $('#viewJobDescription').on('click', function() {
            var description = $(this).data('description');

            // Append modal HTML only once
            if ($('#viewSaleDescriptionModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="viewSaleDescriptionModal" tabindex="-1" aria-labelledby="viewSaleDescriptionModalLabel">' +
                    '<div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="viewSaleDescriptionModalLabel">Sale Description</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body" style="white-space: pre-wrap; word-wrap: break-word;">' +
                    description +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
            } else {
                // Update modal content if already exists
                $('#viewSaleDescriptionModal .modal-body').html(description);
            }

            // Show the modal
            $('#viewSaleDescriptionModal').modal('show');
        });

        $('#viewDocuments').on('click', function() {
            // Make an AJAX call to retrieve notes history data
            var id = $('#sale_id').val();

            $.ajax({
                url: '{{ route('getSaleDocuments') }}', // Your backend URL to fetch notes history, replace it with your actual URL
                type: 'GET',
                data: {
                    id: id
                }, // Pass the id to your server to fetch the corresponding applicant's notes
                success: function(response) {
                    console.log(response);
                    var notesHtml = ''; // This will hold the combined HTML for all notes

                    // Check if the response data is empty
                    if (response.data.length === 0) {
                        notesHtml = '<p>No record found.</p>';
                    } else {
                        // Loop through the response array (assuming it's an array of documents)
                        response.data.forEach(doc => {
                            const created = moment(doc.created_at).format(
                                'DD MMM YYYY, h:mm A');

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

                    // Set the combined notes content in the modal
                    $('#viewSaleDocumentsModal .modal-body').html(notesHtml);

                    // Show the modal
                    $('#viewSaleDocumentsModal').modal('show');
                },
                error: function(xhr, status, error) {
                    console.log("Error fetching notes history: " + error);
                    // Optionally, you can display an error message in the modal
                    $('#viewSaleDocumentsModal .modal-body').html(
                        '<p>There was an error retrieving the notes. Please try again later.</p>');
                    $('#viewSaleDocumentsModal').modal('show');
                }
            });

            // Add the modal HTML to the page (only once, if not already present)
            if ($('#viewSaleDocumentsModal').length === 0) {
                $('body').append(
                    '<div class="modal fade" id="viewSaleDocumentsModal" tabindex="-1" aria-labelledby="viewSaleDocumentsModalLabel" >' +
                    '<div class="modal-dialog modal-dialog-scrollable modal-dialog-top">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<h5 class="modal-title" id="viewSaleDocumentsModalLabel">Sale Documents</h5>' +
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
        });

        $(document).on('click', '.export-btn', function(e) {
            e.preventDefault();

            const $link = $(this);
            const url = new URL($link.attr('href'), window.location.origin);
            const $dropdown = $link.closest('.dropdown');
            const $btn = $dropdown.find('button');
            const $icon = $btn.find('i');
            const $text = $btn.find('.btn-text');

            const applicantStatus = ($('#showFilterApplicantStatus').text() || '').trim().toLowerCase().replace(
                /\s+/g, ' ');
            const cvStatus = ($('#showFilterCvStatus').text() || '').trim().toLowerCase().replace(/\s+/g, ' ');
            const search = $.fn.DataTable.isDataTable('#applicants_table') ?
                ($('#applicants_table').DataTable().search() || '').trim() :
                '';

            url.searchParams.set('status_filter', applicantStatus === 'all applicant status' ? '' :
                applicantStatus);
            url.searchParams.set('cv_status_filter', cvStatus === 'all cv status' ? '' : cvStatus);
            url.searchParams.set('search', search);

            function appendSelectedIds(param, selector, dataKey) {
                url.searchParams.delete(param + '[]');
                url.searchParams.delete(param);
                $(selector + ':checked').each(function() {
                    const id = $(this).data(dataKey);
                    if (id !== '' && id !== null && typeof id !== 'undefined') {
                        url.searchParams.append(param + '[]', id);
                    }
                });
            }

            appendSelectedIds('source_filter', '.source-filter', 'source-id');

            // Same job titles the table is filtered by (taken from its last request).
            const tableParams = $.fn.DataTable.isDataTable('#applicants_table') ?
                ($('#applicants_table').DataTable().ajax.params() || {}) : {};
            url.searchParams.delete('title_filter[]');
            url.searchParams.delete('title_filter');
            [].concat(tableParams.title_filter || []).forEach(function(id) {
                if (id !== '' && id !== null && typeof id !== 'undefined') {
                    url.searchParams.append('title_filter[]', id);
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
    </script>
@endsection
@endsection
