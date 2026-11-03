<?php

namespace App\Http\Controllers;

use App\Services\Xplogent\CandidateQueueService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\CvStorage;

use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\User;
use Horsefly\Sale;
use Horsefly\CVNote;
use Horsefly\SaleNote;
use Horsefly\Applicant;
use Horsefly\JobCategory;
use Horsefly\Setting;
use Horsefly\JobTitle;
use Horsefly\JobSource;
use Horsefly\SaleDocument;
use Horsefly\ModuleNote;

use App\Observers\ActionObserver;

use App\Support\DialLink;
use App\Support\HtmlNotes;
use App\Support\IntelligentSearch;
use App\Support\StatusBadge;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use Yajra\DataTables\Facades\DataTables;
use App\Exports\SalesExport;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Traits\Geocode;
use App\Traits\FiltersRadiusApplicants;
use App\Traits\RecordsBlacklistNotes;
use Exception;

class SaleController extends Controller
{
    use Geocode, FiltersRadiusApplicants, RecordsBlacklistNotes;

    public function __construct()
    {
        $this->middleware('permission:sale-index')->only(['index', 'getSales']);
        $this->middleware('permission:sale-direct-index')->only(['directSaleIndex', 'getDirectSales']);
        $this->middleware('permission:sale-open-index')->only(['openSaleIndex', 'getOpenSales']);
        $this->middleware('permission:sale-closed-index')->only(['closeSaleIndex', 'getClosedSales']);
        $this->middleware('permission:sale-rejected-index')->only(['rejectedSaleIndex', 'getRejectedSales']);
        $this->middleware('permission:sale-hold-index')->only(['onHoldSaleIndex', 'getOnHoldSales']);
        $this->middleware('permission:sale-pending-hold-index')->only(['pendingOnHoldSaleIndex', 'pendingOnHoldSales']);
        $this->middleware('permission:sale-create')->only(['create', 'store']);
        $this->middleware('permission:sale-create,sale-edit')->only(['getOfficeUnits']);
        $this->middleware('permission:sale-edit')->only(['edit', 'update']);
        $this->middleware('permission:sale-blacklist')->only(['toggleBlacklist']);
        $this->middleware('permission:sale-view,sale-direct-view,sale-open-view')->only(['show', 'fetchApplicantsWithinSaleRadiusIndex', 'getApplicantsBySaleRadius']);
        $this->middleware('permission:sale-delete')->only(['destroy']);
        $this->middleware('permission:sale-export')->only(['export']);
        $this->middleware('permission:sale-add-note,sale-direct-add-note,sale-open-add-note,sale-closed-add-note')->only(['storeSaleNotes']);
        $this->middleware('permission:sale-change-status,sale-direct-change-status,sale-open-change-status,sale-closed-change-status,sale-rejected-change-status,sale-hold-change-status')->only(['changeSaleStatus']);
        $this->middleware('permission:sale-mark-on-hold,sale-direct-mark-on-hold,sale-open-mark-on-hold')->only(['changeSaleHoldStatus']);
        $this->middleware('permission:sale-pending-hold-mark-approved,sale-pending-hold-mark-dis-approved')->only(['updatePendingOnHoldStatus']);
        $this->middleware('permission:sale-view-history,sale-direct-view-history,sale-open-view-history,sale-closed-view-history,sale-rejected-view-history,sale-hold-view-history')->only(['saleHistoryIndex', 'getSaleHistoryAjaxRequest']);
        $this->middleware('permission:sale-view-documents,sale-direct-view-documents,sale-open-view-documents,sale-closed-view-documents,sale-rejected-view-documents,sale-hold-view-documents')->only(['getSaleDocuments', 'removeDocument']);
    }

    /**
     * Display a listing of the applicants.
     *
     * @return \Illuminate\Http\Response
     */

    private function formatWithUrlCTA($fullHtml, $idPrefix, $saleId, $modalTitle)
    {
        // 0. Remove inline styles and <span> tags (to avoid affecting layout)
        $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
        $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

        // 1. Convert block-level and <br> tags into \n
        $withBreaks = preg_replace(
            '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
            "\n",
            $cleanedHtml
        );

        // 2. Remove all other HTML tags except basic formatting tags
        $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

        // 3. Decode HTML entities
        $decodedText = html_entity_decode($plainText);

        // 4. Normalize multiple newlines
        $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

        // 5. Detect URL in the plain text
        preg_match('/https?:\/\/[^\s<>"\'\)\]\,\.]+/', $normalizedText, $matches);
        $url = $matches[0] ?? null;

        // 6. Remove the URL from the text if present to avoid showing long links in preview
        $textForPreview = $url ? str_replace($url, '', $normalizedText) : $normalizedText;

        // 7. Limit preview characters
        $preview = Str::limit(trim($textForPreview), 80);

        // 8. Convert newlines to <br>
        $shortText = nl2br($preview);

        $id = $idPrefix . '-' . $saleId;

        $urlCTA = '';
        $modalBody = $fullHtml;
        if ($url) {
            $urlCTA = '<a href="' . e($url) . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link">
                <iconify-icon icon="mdi:link-variant"></iconify-icon> URL
               </a>';

            $modalCTA = '<div class="my-2"><a href="' . e($url) . '" target="_blank" class="btn btn-sm btn-info rounded-pill px-3 py-1 d-inline-flex align-items-center shadow-sm" title="Open Link">
                    <iconify-icon icon="mdi:link-variant" class="me-2"></iconify-icon> Click to Open Link
                 </a></div>';

            // Append instead of str_replace — avoids corrupting hrefs/inner text that already contain the URL
            $modalBody = $fullHtml . $modalCTA;
        }

        return '<div class="d-flex flex-column align-items-start">
                    <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#' . $id . '">' . $shortText . '</a>' . $urlCTA . '
                </div>
                <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="' . $id . '-label">' . $modalTitle . '</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                ' . $modalBody . '
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>';
    }

    /**
     * Resolve latest note / CV count / open_date for a sales-list row.
     * Values are loaded in batched queries for the current DataTables page
     * (see warmSaleListEnrichment), not via full-table GROUP BY joins on the
     * recordsFiltered COUNT query — that pattern is what made live slow.
     */
    private function hydrateSaleListRow($sale, array $auditMessages): void
    {
        if ($sale->getAttribute('_list_hydrated')) {
            return;
        }

        $sale->setAttribute('_list_hydrated', true);
        $this->warmSaleListEnrichment([(int) $sale->id], $auditMessages);

        $cache = $this->saleListEnrichmentCache ?? [];

        if ($sale->getAttribute('latest_note') === null) {
            $sale->latest_note = $cache['notes'][(int) $sale->id] ?? null;
        }

        if ($sale->getAttribute('no_of_sent_cv') === null) {
            $sale->no_of_sent_cv = (int) ($cache['cvs'][(int) $sale->id] ?? 0);
        }

        if ($sale->getAttribute('open_date') === null) {
            $sale->open_date = $cache['dates'][(int) $sale->id] ?? null;
        }
    }

    /** @var array{notes: array<int,string|null>, cvs: array<int,int>, dates: array<int,string|null>}|null */
    private ?array $saleListEnrichmentCache = null;

    /**
     * Batch-load enrichment for the given sale IDs (idempotent within a request).
     *
     * Latest note = the newest text among:
     *   1) latest module_notes row (what Notes History shows)
     *   2) latest active sale_notes row (status = 1)
     * so Add Note / status / hold flows all surface correctly.
     */
    private function warmSaleListEnrichment(array $saleIds, array $auditMessages): void
    {
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($saleIds === []) {
            return;
        }

        $this->saleListEnrichmentCache ??= ['notes' => [], 'cvs' => [], 'dates' => []];
        $missing = array_values(array_diff($saleIds, array_keys($this->saleListEnrichmentCache['notes'])));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $id) {
            $this->saleListEnrichmentCache['notes'][$id] = null;
            $this->saleListEnrichmentCache['cvs'][$id] = 0;
            $this->saleListEnrichmentCache['dates'][$id] = null;
        }

        // Candidate A: latest module_notes row (same source as Notes History CTA)
        $moduleBySale = [];
        $moduleRows = DB::table('module_notes')
            ->select(['module_noteable_id', 'details', 'created_at'])
            ->where('module_noteable_type', 'Horsefly\\Sale')
            ->whereIn('module_noteable_id', $missing)
            ->whereIn('id', function ($q) use ($missing) {
                $q->selectRaw('MAX(id)')
                    ->from('module_notes')
                    ->where('module_noteable_type', 'Horsefly\\Sale')
                    ->whereIn('module_noteable_id', $missing)
                    ->groupBy('module_noteable_id');
            })
            ->get();

        foreach ($moduleRows as $row) {
            $moduleBySale[(int) $row->module_noteable_id] = $row;
        }

        // Candidate B: latest ACTIVE sale_notes row (status = 1, highest id)
        $saleNoteBySale = [];
        $saleNoteRows = DB::table('sale_notes')
            ->select(['sale_id', 'sale_note', 'created_at'])
            ->whereIn('sale_id', $missing)
            ->where('status', 1)
            ->whereIn('id', function ($q) use ($missing) {
                $q->selectRaw('MAX(id)')
                    ->from('sale_notes')
                    ->whereIn('sale_id', $missing)
                    ->where('status', 1)
                    ->groupBy('sale_id');
            })
            ->get();
        foreach ($saleNoteRows as $row) {
            $saleNoteBySale[(int) $row->sale_id] = $row;
        }

        foreach ($missing as $id) {
            $module = $moduleBySale[$id] ?? null;
            $saleNote = $saleNoteBySale[$id] ?? null;

            if ($module && $saleNote) {
                // Pick whichever was written more recently
                $this->saleListEnrichmentCache['notes'][$id] =
                    strtotime((string) $module->created_at) >= strtotime((string) $saleNote->created_at)
                    ? $module->details
                    : $saleNote->sale_note;
            } elseif ($module) {
                $this->saleListEnrichmentCache['notes'][$id] = $module->details;
            } elseif ($saleNote) {
                $this->saleListEnrichmentCache['notes'][$id] = $saleNote->sale_note;
            }
        }

        $cvRows = DB::table('cv_notes')
            ->selectRaw('sale_id, COUNT(*) as cv_count')
            ->whereIn('sale_id', $missing)
            ->where('status', 1)
            ->groupBy('sale_id')
            ->get();
        foreach ($cvRows as $row) {
            $this->saleListEnrichmentCache['cvs'][(int) $row->sale_id] = (int) $row->cv_count;
        }

        $auditRows = DB::table('audits')
            ->select(['auditable_id', 'created_at'])
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereIn('auditable_id', $missing)
            ->whereIn('message', $auditMessages)
            ->whereIn('id', function ($q) use ($missing, $auditMessages) {
                $q->selectRaw('MAX(id)')
                    ->from('audits')
                    ->where('auditable_type', 'Horsefly\\Sale')
                    ->whereIn('auditable_id', $missing)
                    ->whereIn('message', $auditMessages)
                    ->groupBy('auditable_id');
            })
            ->get();
        foreach ($auditRows as $row) {
            $this->saleListEnrichmentCache['dates'][(int) $row->auditable_id] = $row->created_at;
        }
    }

    public function index()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.list', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function directSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.direct', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function openSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.open', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function fetchApplicantsWithinSaleRadiusIndex($id, $radius = null)
    {
        $radius = $radius ?: 15; // Default radius to 15 kilometers if not provided

        $sale = Sale::FindOrfail($id);
        $office = Office::where('id', $sale->office_id)->select('office_name')->first();
        $unit = Unit::where('id', $sale->unit_id)->select('unit_name')->first();
        $jobCategory = JobCategory::where('id', $sale->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $sale->job_title_id)->select('name')->first();
        $jobType = ucwords(str_replace('-', ' ', $sale->job_type));
        $jobType = $jobType == 'Specialist' ? ' (' . $jobType . ')' : '';
        // Convert radius to miles if provided in kilometers (1 km ≈ 0.621371 miles)
        $radiusInMiles = round($radius * 0.621371, 1);

        $sale_cv_count = CVNote::where('sale_id', $id)
            ->where('status', 1)
            ->count();


        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $hiddenSourceIds = [];
        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $hiddenSourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $jobSourcesQuery = JobSource::where('is_active', 1);
        if (count($hiddenSourceIds) > 0) {
            $jobSourcesQuery->whereNotIn('id', $hiddenSourceIds);
        }
        $jobSources = $jobSourcesQuery->orderBy('name', 'asc')->get();

        return view('sales.fetch-applicants-by-radius', compact(
            'sale',
            'radiusInMiles',
            'jobCategory',
            'sale_cv_count',
            'jobTitle',
            'jobType',
            'office',
            'unit',
            'radius',
            'jobSources'
        ));
    }
    public function rejectedSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->whereNull('deleted_at')->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->whereNull('deleted_at')->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.rejected', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function closeSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->whereNull('deleted_at')->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->whereNull('deleted_at')->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.closed', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function onHoldSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->whereNull('deleted_at')->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->whereNull('deleted_at')->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.on-hold', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function pendingOnHoldSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->whereNull('deleted_at')->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->whereNull('deleted_at')->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.pending-on-hold', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function create()
    {
        $offices = Office::where('status', 1)
            ->where('is_blacklist', 0)
            ->select('id', 'office_name')
            ->orderBy('office_name', 'asc')
            ->get();

        $units = Unit::where('status', 1)
            ->where('is_blacklist', 0)
            ->select('id', 'unit_name')
            ->get();

        $jobCategories = JobCategory::where('is_active', 1)->get();
        $jobTitles = JobTitle::where('is_active', 1)->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('sales.create', compact('offices', 'units', 'jobCategories', 'jobTitles', 'jobSources'));
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => 'required|integer|exists:offices,id',
            'unit_id' => 'required|integer|exists:units,id',
            'sale_postcode' => 'required|string',
            'job_category_id' => 'required|integer|exists:job_categories,id',
            'job_title_id' => 'required|integer|exists:job_titles,id',
            'job_source_id' => 'required|integer|exists:job_sources,id',
            'job_type' => 'required|string|max:50',
            'position_type' => 'required|string|max:50',
            'cv_limit' => 'required|integer|min:1|max:127',     // tinyInteger; 0 would block Send CV everywhere
            'timing' => 'required',
            'experience' => 'required',
            'salary' => 'required',
            'benefits' => 'required',
            'qualification' => 'required',
            'sale_notes' => 'required',
            'job_description' => 'nullable|string',
            'attachments.*' => 'nullable|file|extensions:pdf,doc,docx,txt|max:10000', // max 10MB
        ]);

        // Overall duplicate check (runs after the rules above pass their basic checks)
        $validator->after(function ($validator) use ($request) {
            if ($request->filled('office_id')) {
                $office = Office::find($request->office_id);
                if (!$office || $office->is_blacklist) {
                    $validator->errors()->add('office_id', 'The selected office is blacklisted or unavailable.');
                }
            }

            if ($request->filled('unit_id')) {
                $unit = Unit::find($request->unit_id);
                if (
                    !$unit ||
                    $unit->is_blacklist ||
                    (string) $unit->office_id !== (string) $request->office_id
                ) {
                    $validator->errors()->add('unit_id', 'The selected unit is blacklisted or does not belong to this office.');
                }
            }

            $query = Sale::where('sale_postcode', $request->sale_postcode)
                ->where('office_id', $request->office_id)
                ->where('unit_id', $request->unit_id)
                ->where('job_category_id', $request->job_category_id)
                ->where('job_title_id', $request->job_title_id)
                ->whereIn('status', [1, 2]);

            if ($request->job_source_id == 10) {
                // Only conflict with other records that are ALSO job_source_id = 10
                $query->where('job_source_id', 10);
            } else {
                // Conflict with any record that is NOT job_source_id = 10
                $query->where('job_source_id', '!=', 10);
            }

            // Exclude current record when editing
            if ($request->filled('sale_id')) {
                $query->where('id', '!=', $request->sale_id);
            }

            if ($query->exists()) {
                $validator->errors()->add('sale_postcode', 'This sale already exists (duplicate postcode).')
                    ->add('unit_id', 'This sale already exists (duplicate unit).')
                    ->add('office_id', 'This sale already exists (duplicate office).')
                    ->add('job_category_id', 'This sale already exists (duplicate job category).')
                    ->add('job_title_id', 'This sale already exists (duplicate job title).')
                    ->add('job_source_id', 'This sale already exists (duplicate job source).');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $user = Auth::user();

        try {
            // Get office data
            $saleData = $request->only([
                'office_id',
                'unit_id',
                'job_category_id',
                'job_title_id',
                'job_source_id',
                'job_type',
                'position_type',
                'sale_postcode',
                'cv_limit',
                'timing',
                'experience',
                'salary',
                'benefits',
                'qualification',
                'sale_notes',
                'job_description',
            ]);

            $postcode = preg_replace('/\s+/', '', $request->sale_postcode);
            // 1. Try to find a match in the full postcodes table first
            $postcode_query = DB::table('postcodes')
                ->whereRaw("LOWER(REPLACE(postcode, ' ', '')) = ?", [strtolower($postcode)])
                ->first();

            // 2. Fallback: If not found in full postcodes, check outcodes
            if (!$postcode_query) {
                $postcode_query = DB::table('outcodepostcodes')
                    ->whereRaw("LOWER(REPLACE(outcode, ' ', '')) = ?", [strtolower($postcode)])
                    ->first();
            }

            if (!$postcode_query) {
                try {
                    $result = $this->geocode($postcode);

                    // If geocode fails, throw
                    if (!isset($result['lat']) || !isset($result['lng'])) {
                        throw new Exception('Geolocation failed. Latitude and longitude not found.');
                    }

                    $saleData['lat'] = $result['lat'];
                    $saleData['lng'] = $result['lng'];
                } catch (Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }
            } else {
                $saleData['lat'] = $postcode_query->lat;
                $saleData['lng'] = $postcode_query->lng;
            }

            $sale_add_note = $request->input('sale_notes') . ' --- By: ' . $user->name . ' Date: ' . Carbon::now()->format('d-m-Y') . '  Time: ' . Carbon::now()->format("h:iA");

            // Format data for office
            $saleData['user_id'] = $user->id;
            $saleData['sale_note'] = $sale_add_note;
            $sale = Sale::create($saleData);

            $sale_note = SaleNote::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'sale_note' => $sale_add_note,
            ]);

            // Generate UID
            $sale->update(['sale_uid' => md5($sale->id)]);
            $sale_note->update(['sales_notes_uid' => md5($sale_note->id)]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $sale_add_note,
                'module_noteable_id' => $sale->id,
                'module_noteable_type' => 'Horsefly\Sale',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            if ($request->hasFile('attachments')) {

                foreach ($request->file('attachments') as $attachment) {

                    // Original file info
                    $originalName = $attachment->getClientOriginalName();
                    $size = $attachment->getSize();
                    $extension = $attachment->getClientOriginalExtension();

                    // Filename without extension
                    $filename = pathinfo($originalName, PATHINFO_FILENAME);

                    // Clean filename (remove spaces)
                    $filename = preg_replace('/\s+/', '_', trim($filename));

                    // Unique filename
                    $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                    // 📁 Target directory inside public
                    $directory = 'uploads/docs';
                    $publicPath = public_path($directory);

                    // Ensure directory exists
                    if (!file_exists($publicPath)) {
                        mkdir($publicPath, 0755, true);
                    }

                    // 🚚 Move file to public/uploads/docs
                    $attachment->move($publicPath, $fileNameToStore);

                    // Save relative path in DB
                    $path = $directory . '/' . $fileNameToStore;

                    // 💾 Save document record
                    SaleDocument::create([
                        'sale_id' => $sale->id,
                        'user_id' => Auth::id(),
                        'document_name' => $fileNameToStore,
                        'document_path' => $path,
                        'document_extension' => $extension,
                        'document_size' => $size,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Sale created successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Error creating sale: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the sale. Please try again.'
            ], 500);
        }
    }
    public function edit($id, Request $request)
    {
        $sale = Sale::with('documents')->findOrFail($id);      // an unknown id is a 404, not a crash

        if ($sale->status == 4) {
            $offices = Office::where('status', 4)
                ->whereNull('deleted_at')
                ->select('id', 'office_name')
                ->orderBy('office_name', 'asc')
                ->get();
        } else {
            $offices = Office::where('status', 1)
                ->whereNull('deleted_at')
                ->select('id', 'office_name')
                ->orderBy('office_name', 'asc')
                ->get();
        }

        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        $redirect_url = $request->input('redirect_url', 'sales.list');

        return view('sales.edit', compact('sale', 'offices', 'jobCategories', 'jobTitles', 'redirect_url', 'jobSources'));
    }
    public function update(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'sale_id' => 'required|exists:sales,id',
            'office_id' => 'required|integer|exists:offices,id',
            'unit_id' => 'required|integer|exists:units,id',
            'sale_postcode' => 'required|string',
            'job_category_id' => 'required|integer|exists:job_categories,id',
            'job_title_id' => 'required|integer|exists:job_titles,id',
            'job_source_id' => 'required|integer|exists:job_sources,id',
            'job_type' => 'required|string|max:50',
            'position_type' => 'required|string|max:50',
            'cv_limit' => 'required|integer|min:1|max:127',     // tinyInteger; 0 would block Send CV everywhere
            'timing' => 'required',
            'experience' => 'required',
            'salary' => 'required',
            'benefits' => 'required',
            'qualification' => 'required',
            'sale_notes' => 'required',
            'job_description' => 'nullable|string',
            'attachments.*' => 'nullable|file|extensions:pdf,doc,docx,txt|max:10000', // max 10MB
        ]);

        // Overall duplicate check (runs after the rules above pass their basic checks)
        $validator->after(function ($validator) use ($request) {
            // A sale may keep its current (blacklisted) office/unit, but cannot be moved under a blacklisted one.
            $currentSale = Sale::select('office_id', 'unit_id')->find($request->sale_id);

            if (
                $currentSale &&
                (string) $currentSale->office_id !== (string) $request->office_id &&
                Office::whereKey($request->office_id)->where('is_blacklist', true)->exists()
            ) {
                $validator->errors()->add('office_id', 'The selected office is blacklisted.');
            }

            if (
                $currentSale &&
                (string) $currentSale->unit_id !== (string) $request->unit_id &&
                Unit::whereKey($request->unit_id)->where('is_blacklist', true)->exists()
            ) {
                $validator->errors()->add('unit_id', 'The selected unit is blacklisted.');
            }

            $query = Sale::where('office_id', $request->office_id)
                ->where('unit_id', $request->unit_id)
                ->where('sale_postcode', $request->sale_postcode)
                ->where('job_category_id', $request->job_category_id)
                ->where('job_title_id', $request->job_title_id)
                ->whereIn('status', [1, 2])
                // Always exclude the record being edited
                ->where('id', '!=', $request->sale_id);

            if ($request->job_source_id == 10) {
                // Only conflict with other records that are ALSO job_source_id = 10
                $query->where('job_source_id', 10);
            } else {
                // Conflict with any record that is NOT job_source_id = 10
                $query->where('job_source_id', '!=', 10);
            }

            if ($query->exists()) {
                $validator->errors()->add('sale_postcode', 'This sale already exists (duplicate postcode).')
                    ->add('unit_id', 'This sale already exists (duplicate unit).')
                    ->add('office_id', 'This sale already exists (duplicate office).')
                    ->add('job_category_id', 'This sale already exists (duplicate job category).')
                    ->add('job_title_id', 'This sale already exists (duplicate job title).')
                    ->add('job_source_id', 'This sale already exists (duplicate job source).');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $user = Auth::user();

        try {
            // Get office data
            $saleData = $request->only([
                'office_id',
                'unit_id',
                'job_category_id',
                'job_title_id',
                'job_source_id',
                'job_type',
                'position_type',
                'sale_postcode',
                'cv_limit',
                'timing',
                'experience',
                'salary',
                'benefits',
                'qualification',
                'sale_notes',
                'job_description',
            ]);

            $id = $request->input('sale_id');

            // Check for existing sale with the same critical fields (e.g., office_id, unit_id, sale_postcode, job_title_id)
            // $exists = Sale::where('office_id', $saleData['office_id'])
            //     ->where('unit_id', $saleData['unit_id'])
            //     ->where('sale_postcode', $saleData['sale_postcode'])
            //     ->where('job_category_id', $saleData['job_category_id'])
            //     ->where('job_title_id', $saleData['job_title_id'])
            //     ->when($id, fn($q) => $q->where('id', '!=', $id)) // safely exclude current sale
            //     ->first();

            // if ($exists) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'A sale with the same details already exists.'
            //     ], 409);
            // }

            // Retrieve the office record
            $sale = Sale::find($id);

            // If the applicant doesn't exist, throw an exception
            if (!$sale) {
                throw new Exception("Sale not found with ID: " . $id);
            }

            $postcode = preg_replace('/\s+/', '', $request->sale_postcode);

            if ($postcode != preg_replace('/\s+/', '', $sale->sale_postcode)) {
                // 1. Try to find a match in the full postcodes table first
                $postcode_query = DB::table('postcodes')
                    ->whereRaw("LOWER(REPLACE(postcode, ' ', '')) = ?", [strtolower($postcode)])
                    ->first();

                // 2. Fallback: If not found in full postcodes, check outcodes
                if (!$postcode_query) {
                    $postcode_query = DB::table('outcodepostcodes')
                        ->whereRaw("LOWER(REPLACE(outcode, ' ', '')) = ?", [strtolower($postcode)])
                        ->first();
                }

                if (!$postcode_query) {
                    try {
                        $result = $this->geocode($postcode);

                        // If geocode fails, throw
                        if (!isset($result['lat']) || !isset($result['lng'])) {
                            throw new Exception('Geolocation failed. Latitude and longitude not found.');
                        }

                        $saleData['lat'] = $result['lat'];
                        $saleData['lng'] = $result['lng'];
                    } catch (Exception $e) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to locate address: ' . $e->getMessage()
                        ], 400);
                    }
                } else {
                    $saleData['lat'] = $postcode_query->lat;
                    $saleData['lng'] = $postcode_query->lng;
                }
            }

            $sale_add_note = $request->input('sale_notes') . ' --- By: ' . $user->name . ' Date: ' . Carbon::now()->format('d-m-Y') . '  Time: ' . Carbon::now()->format("h:iA");

            $saleData['sale_notes'] = $sale_add_note;
            // Update the applicant with the validated and formatted data
            $sale->update($saleData);

            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Sale'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $sale_add_note,
                'module_noteable_id' => $sale->id,
                'module_noteable_type' => 'Horsefly\Sale',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $attachment) {
                    // Original file info
                    $originalName = $attachment->getClientOriginalName();
                    $size = $attachment->getSize();
                    $extension = $attachment->getClientOriginalExtension();

                    // Filename without extension
                    $filename = pathinfo($originalName, PATHINFO_FILENAME);

                    // Clean filename (remove spaces & special chars)
                    $filename = preg_replace('/\s+/', '_', trim($filename));

                    // Unique filename
                    $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                    // 📁 Public directory
                    $directory = 'sale_docs';
                    $publicPath = public_path($directory);

                    // Ensure directory exists
                    if (!file_exists($publicPath)) {
                        // mkdir($publicPath, 0755, true);
                        mkdir($publicPath, 0777, true);
                    }

                    // 🚚 Move file to public/sale_docs
                    $attachment->move($publicPath, $fileNameToStore);

                    // Save relative path in DB
                    $path = $directory . '/' . $fileNameToStore;

                    // 💾 Save document details
                    SaleDocument::create([
                        'sale_id' => $sale->id,
                        'user_id' => Auth::id(),
                        'document_name' => $fileNameToStore,
                        'document_path' => $path, // e.g. sale_docs/file.pdf
                        'document_extension' => $extension,
                        'document_size' => $size,
                    ]);
                }
            }

            if ($request->has('sale_notes')) {
                $sale_note = SaleNote::create([
                    'sale_id' => $sale->id,
                    'user_id' => $user->id,
                    'sale_note' => $sale_add_note,
                ]);

                $sale_note->update(['sales_notes_uid' => md5(uniqid($sale_note->id, true))]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sale updated successfully',
                'redirect' => route($request->redirect_url)
            ]);
        } catch (Exception $e) {
            Log::error('Error updating sale: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the sale. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $sale = Sale::findOrFail($id);
        $sale->delete();
        return redirect()->route('sales.list')->with('success', 'Sale deleted successfully');
    }
    public function show($id)
    {
        $sale = Sale::findOrFail($id);
        return view('sales.show', compact('sale'));
    }
    public function toggleBlacklist(Request $request, $id)
    {
        $noteInput = $request->input('blacklist_note');
        if (is_string($noteInput)) {
            $request->merge(['blacklist_note' => trim($noteInput)]);
        }

        $request->validate([
            'is_blacklist' => ['required', 'boolean'],
            'blacklist_note' => ['required', 'string', 'max:2000'],
        ]);
        $targetBlacklistState = $request->boolean('is_blacklist');
        $blacklistNote = $request->input('blacklist_note');
        $sale = Sale::findOrFail($id);

        $removedParents = DB::transaction(function () use ($sale, $targetBlacklistState, $blacklistNote) {
            $user = Auth::user();
            $note = $blacklistNote . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');
            $removedParents = [];

            // Removing a sale from the blacklist also removes its unit and office (when they
            // are blacklisted) with the same note. Other units/sales under them are untouched.
            // Lock order matches the office toggle (office → unit → sale) to avoid deadlocks.
            if (!$targetBlacklistState) {
                $office = Office::whereKey($sale->office_id)->where('is_blacklist', true)->lockForUpdate()->first();
                $unit = Unit::whereKey($sale->unit_id)->where('is_blacklist', true)->lockForUpdate()->first();

                if ($office) {
                    $office->update(['is_blacklist' => false]);
                    $this->recordOfficeNote($office, $note, $user->id);
                    $removedParents[] = 'office';
                }

                if ($unit) {
                    $unit->update(['is_blacklist' => false]);
                    $this->recordUnitNote($unit, $note, $user->id);
                    $removedParents[] = 'unit';
                }
            }

            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $sale->update(['is_blacklist' => $targetBlacklistState]);
            $this->recordSaleNote($sale, $note, $user->id);

            $observer = new ActionObserver();
            $observer->saleBlacklistAudit($sale, $targetBlacklistState);

            return $removedParents;
        });

        if ($targetBlacklistState) {
            $message = 'Sale has been added to the blacklist.';
        } elseif ($removedParents) {
            $message = 'Sale and its ' . implode(' and ', array_reverse($removedParents)) . ' have been removed from the blacklist.';
        } else {
            $message = 'Sale has been removed from the blacklist.';
        }

        return response()->json([
            'success' => true,
            'is_blacklist' => $targetBlacklistState,
            'removed_parents' => $removedParents,
            'message' => $message,
        ]);
    }
    /** The sale's current status (Open, On Hold, Closed...), ignoring the blacklist flag. */
    private function saleStatusBadge($sale): string
    {
        if ($sale->status == 1) {
            if ($sale->is_on_hold == 1) {
                return '<span class="badge bg-warning">On Hold</span>';
            }
            if ($sale->is_re_open == 1) {
                return '<span class="badge bg-dark">Re-Open</span>';
            }
            return '<span class="badge bg-success">Open</span>';
        }

        return match ((int) $sale->status) {
            0 => '<span class="badge bg-danger">Closed</span>',
            2 => '<span class="badge bg-warning">Pending</span>',
            3 => '<span class="badge bg-danger">Rejected</span>',
            default => '',
        };
    }

    public function getSales(Request $request)
    {
        $statusFilter     = $request->input('status_filter', '');
        $typeFilter       = $request->input('type_filter', '');
        $categoryFilter   = $request->input('category_filter', '');
        $sourceFilter     = $request->input('source_filter', '');
        $titleFilter      = $request->input('title_filter', '');
        $limitCountFilter = $request->input('cv_limit_filter', '');
        $officeFilter     = $request->input('office_filter', '');
        $userFilter       = $request->input('user_filter', '');

        $model = Sale::query()
            ->select([
                'sales.id',
                'sales.sale_uid',
                'sales.office_id',
                'sales.unit_id',
                'sales.user_id',
                'sales.job_category_id',
                'sales.job_title_id',
                'sales.job_source_id',
                'sales.job_type',
                'sales.position_type',
                'sales.sale_postcode',
                'sales.cv_limit',
                'sales.timing',
                'sales.status',
                'sales.is_on_hold',
                'sales.is_re_open',
                'sales.is_blacklist',
                'sales.lat',
                'sales.lng',
                'sales.sale_notes',
                'sales.created_at',
                'sales.updated_at',
                'sales.experience',
                'sales.salary',
                'sales.qualification',
                'sales.benefits',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'sales.job_source_id', '=', 'job_sources.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->whereNull('sales.deleted_at')
            ->whereNotIn('sales.status', [4, 5]);

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // ✅ Status filter — sets base status conditions and picks which audit
        // "message" values define the relevant open/close/reject event.
        $auditMessageMap = [
            'closed'   => ['close', 'sale-closed'],
            'pending'  => ['open', 'sale-opened'],
            'rejected' => ['reject', 'sale-rejected'],
            'on hold'  => ['close', 'sale-closed'],
            'open'     => ['open', 'sale-opened'],
            'blacklisted' => ['blacklist', 'sale-blacklisted'],
        ];

        $normalizedStatus = in_array($statusFilter, array_keys($auditMessageMap), true)
            ? $statusFilter
            : 'open';

        switch ($normalizedStatus) {
            case 'blacklisted':
                $model->where('sales.is_blacklist', 1);
                break;
            case 'closed':
                $model->where('sales.status', 0)
                    ->where('sales.is_blacklist', 0)
                    ->where('sales.is_on_hold', 0);
                break;
            case 'pending':
                $model->where('sales.status', 2)
                    ->where('sales.is_blacklist', 0);
                break;
            case 'rejected':
                $model->where('sales.status', 3)
                    ->where('sales.is_blacklist', 0);
                break;
            case 'on hold':
                $model->where('sales.is_on_hold', 1)
                    ->where('sales.is_blacklist', 0);
                break;
            case 'open':
            default:
                $model->where('sales.status', 1)
                    ->where('sales.is_blacklist', 0)
                    ->where('sales.is_on_hold', 0);
                break;
        }

        $auditMessages = $auditMessageMap[$normalizedStatus];

        // Only join the audits aggregation when the user is sorting by open_date —
        // otherwise open_date is hydrated per displayed row (see hydrateSaleListRow).
        $orderColumn = $request->has('order')
            ? $request->input('columns.' . $request->input('order.0.column') . '.data')
            : null;
        $needsAuditJoin = $orderColumn === 'open_date';

        if ($needsAuditJoin) {
            $model->leftJoinSub(
                DB::table('audits')
                    ->selectRaw('MAX(id) as id, auditable_id')
                    ->where('auditable_type', 'Horsefly\\Sale')
                    ->whereIn('message', $auditMessages)
                    ->groupBy('auditable_id'),
                'latest_open_audit_ids',
                'latest_open_audit_ids.auditable_id',
                '=',
                'sales.id'
            )->leftJoin('audits as open_audits', 'open_audits.id', '=', 'latest_open_audit_ids.id')
                ->addSelect('open_audits.created_at as open_date');
        }

        if ($typeFilter === 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } elseif ($typeFilter === 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        if ($request->filled('search.value')) {
            $searchTerm = (string) $request->input('search.value');
            $saleIds = Sale::search($searchTerm)->keys()->toArray();

            $model->where(function ($query) use ($searchTerm, $saleIds) {
                if (!empty($saleIds)) {
                    $query->whereIn('sales.id', $saleIds);
                }
                $query->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_sources.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        // CV-limit filter is the only case that needs the cv_notes aggregation in
        // SQL. For the badge on each row we hydrate no_of_sent_cv per page instead.
        $needsCvCountJoin = in_array($limitCountFilter, ['max', 'not max', 'zero'], true);

        if ($needsCvCountJoin) {
            $cvCountSub = DB::table('cv_notes')
                ->selectRaw('sale_id, COUNT(*) as cv_count')
                ->where('status', 1)
                ->groupBy('sale_id');

            $model->leftJoinSub($cvCountSub, 'cv_counts', 'cv_counts.sale_id', '=', 'sales.id')
                ->addSelect(DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv'));

            switch ($limitCountFilter) {
                case 'max':
                    $model->whereRaw('COALESCE(cv_counts.cv_count, 0) >= sales.cv_limit');
                    break;
                case 'not max':
                    $model->whereRaw('COALESCE(cv_counts.cv_count, 0) > 0 AND COALESCE(cv_counts.cv_count, 0) < sales.cv_limit');
                    break;
                case 'zero':
                    $model->whereRaw('COALESCE(cv_counts.cv_count, 0) = 0');
                    break;
            }
        }

        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }

        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }

        // ✅ Sorting — whitelist against actually-selected/joined columns.
        $sortMap = [
            'sale_uid'        => 'sales.sale_uid',
            'office_name'     => 'offices.office_name',
            'unit_name'       => 'units.unit_name',
            'job_title'       => 'job_titles.name',
            'job_category'    => 'job_categories.name',
            'job_source'      => 'job_sources.name',
            'user_name'       => 'users.name',
            'sale_postcode'   => 'sales.sale_postcode',
            'position_type'   => 'sales.position_type',
            'status'          => 'sales.status',
            'created_at'      => 'sales.created_at',
            'updated_at'      => 'sales.updated_at',
            'open_date'       => 'open_audits.created_at',
            'no_of_sent_cv'   => DB::raw('COALESCE(cv_counts.cv_count, 0)'),
        ];

        // Deferred via DataTables' ->order() below (NOT applied to $model here) —
        // Yajra computes recordsFiltered by wrapping the *current* query state in
        // `SELECT COUNT(*) FROM (...) count_row_table`. With ~8 joined tables/subqueries
        // per row, baking ORDER BY into $model forced MySQL to fully materialize and
        // filesort the entire matching set just to throw the order away for a COUNT —
        // measured at 20+ seconds for the "open" tab's count query alone (vs. ~500ms for
        // the actual paginated fetch). ->order() runs after that count query, so sorting
        // only ever affects the actual page fetch.
        $applySorting = function ($query) use ($request, $sortMap) {
            if ($request->has('order')) {
                $orderColumn    = $request->input('columns.' . $request->input('order.0.column') . '.data');
                $orderDirection = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

                if (isset($sortMap[$orderColumn])) {
                    $query->orderBy($sortMap[$orderColumn], $orderDirection);
                } else {
                    $query->orderBy('sales.updated_at', 'desc');
                }
            } else {
                $query->orderBy('sales.updated_at', 'desc');
            }
        };

        if ($request->ajax()) {
            // ✅ Resolve permissions ONCE per request instead of once per row.
            $canEdit               = Gate::allows('sale-edit');
            $canBlacklist          = Gate::allows('sale-blacklist');
            $canView                = Gate::allows('sale-view');
            $canAddNote              = Gate::allows('sale-add-note');
            $canChangeStatus         = Gate::allows('sale-change-status');
            $canMarkOnHold           = Gate::allows('sale-mark-on-hold');
            $canViewDocuments        = Gate::allows('sale-view-documents');
            $canViewHistory          = Gate::allows('sale-view-history');
            $canViewNotesHistory     = Gate::allows('sale-view-notes-history');
            $canViewManagerDetails   = Gate::allows('sale-view-manager-details');

            // Prefetch enrichment for the current page in 3 batched queries
            // (notes / cv counts / open dates) so hydrateSaleListRow is a cache hit.
            // Sorted clone only — never touch $model itself (that would put ORDER BY
            // back onto Yajra's COUNT wrapper).
            $pageStart  = max(0, (int) $request->input('start', 0));
            $pageLength = (int) $request->input('length', 10);
            if ($pageLength < 1 || $pageLength > 100) {
                $pageLength = 10;
            }
            $pageIdQuery = clone $model;
            $applySorting($pageIdQuery);
            $pageIds = $pageIdQuery->skip($pageStart)->take($pageLength)->pluck('id')->all();
            $this->warmSaleListEnrichment($pageIds, $auditMessages);

            return DataTables::eloquent($model)
                ->skipTotalRecords()
                ->order(fn($query) => $applySorting($query))
                ->addIndexColumn()
                ->setRowClass(fn ($sale) => $sale->is_blacklist == 1 ? 'table-danger blacklisted-row' : '')
                ->addColumn('office_name', function ($sale) {
                    return $sale->office_name ? ucwords($sale->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    return $sale->unit_name ? ucwords($sale->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                })
                ->addColumn('open_date', function ($sale) use ($auditMessages) {
                    $this->hydrateSaleListRow($sale, $auditMessages);
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype = $type === 'specialist'
                        ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                        : '';
                    return $sale->job_category_name ? ucwords($sale->job_category_name) . $stype : '-';
                })
                ->addColumn('job_source', function ($sale) {
                    return $sale->job_source_name
                        ? '<span class="badge bg-light text-dark">' . e(ucwords($sale->job_source_name)) . '</span>'
                        : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                            data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode">
                            <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                        </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $button = '<a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $button . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale`s Salary');
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at;
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at;
                })
                ->addColumn('cv_limit', function ($sale) use ($auditMessages) {
                    $this->hydrateSaleListRow($sale, $auditMessages);
                    return $sale->no_of_sent_cv == $sale->cv_limit
                        ? '<span class="badge w-100 bg-danger" style="font-size:90%">0/' . $sale->cv_limit . '<br>Limit Reached</span>'
                        : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv . '/' . (int) $sale->cv_limit) . "<br>Limit Remains</span>";
                })
                ->addColumn('position_type', function ($sale) {
                    $status = '-';
                    if ($sale->position_type == 'full time') {
                        $status = "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    } elseif ($sale->position_type == 'part time') {
                        $status = "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    }
                    return $status;
                })
                ->addColumn('sale_notes', function ($sale) use ($auditMessages) {
                    $this->hydrateSaleListRow($sale, $auditMessages);
                    // latest_note is the newest of module_notes / active sale_notes
                    // (see warmSaleListEnrichment). Fall back to the denormalized
                    // sales.sale_notes column only when both history tables are empty.
                    $notesIndex = !empty($sale->latest_note)
                        ? $sale->latest_note
                        : (!empty($sale->sale_notes) ? $sale->sale_notes : '-');

                    preg_match('/https?:\/\/[^\s]+/', $notesIndex, $matches);
                    $url = $matches[0] ?? null;

                    $notesValue = $url ? str_replace($url, '', $notesIndex) : $notesIndex;
                    $shortNotes = Str::limit(trim(strip_tags($notesValue)), 80);

                    $urlCTA = '';
                    $escapedNotes = htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8');
                    if ($url) {
                        $urlCTA = '<a href="' . $url . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link">
                                <iconify-icon icon="mdi:link-variant"></iconify-icon> URL
                            </a>';
                    }

                    $notes = nl2br($escapedNotes);
                    $postcode = htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8');
                    $office_name = ucwords($sale->office_name ?? '-');
                    $unit_name = ucwords($sale->unit_name ?? '-');

                    return '<div class="d-flex flex-column align-items-start">
                            <a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . $notes . '\', \'' . $office_name . '\', \'' . $unit_name . '\', \'' . $postcode . '\')">
                                ' . $shortNotes . '
                            </a>
                        </div>' . $urlCTA . '
                    </div>';
                })
                ->addColumn('status', function ($sale) {
                    return StatusBadge::withBlacklist($this->saleStatusBadge($sale), $sale->is_blacklist == 1);
                })
                ->addColumn('action', function ($sale) use (
                    $canEdit,
                    $canView,
                    $canAddNote,
                    $canChangeStatus,
                    $canMarkOnHold,
                    $canBlacklist,
                    $canViewDocuments,
                    $canViewHistory,
                    $canViewNotesHistory,
                    $canViewManagerDetails
                ) {
                    $postcode = strtoupper($sale->sale_postcode ?? '-');
                    $posted_date = $sale->formatted_created_at;
                    $office_name = ucwords($sale->office_name ?? '-');
                    $unit_name = ucwords($sale->unit_name ?? '-');
                    $jobTitle = strtoupper($sale->job_title_name ?? '-');
                    $stype = $sale->job_type == 'specialist' ? ' (Specialist)' : '';
                    $jobCategory = ucwords(($sale->job_category_name ?? '-') . $stype);

                    $badges = StatusBadge::withBlacklist($this->saleStatusBadge($sale), $sale->is_blacklist == 1);

                    $pos = strtoupper(str_replace('-', ' ', $sale->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($pos) . '</span>';

                    $action = '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                        </button>
                            <ul class="dropdown-menu">';

                    if ($canEdit) {
                        $action .= '<li><a class="dropdown-item" href="' . route('sales.edit', ['id' => (int) $sale->id]) . '">Edit</a></li>';
                    }


                    if ($canView) {
                        $viewArguments = [
                            $sale->id,
                            $posted_date,
                            $office_name,
                            $unit_name,
                            $postcode,
                            strip_tags($jobCategory),
                            strip_tags($jobTitle),
                            $badges,
                            $sale->timing,
                            strip_tags($sale->experience),
                            $sale->salary,
                            strip_tags($position),
                            strip_tags($sale->qualification),
                            strip_tags($sale->benefits),
                        ];
                        $encodedViewArguments = implode(', ', array_map(
                            fn($argument) => e(json_encode(
                                (string) ($argument ?? ''),
                                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                            )),
                            $viewArguments
                        ));

                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal('
                            . $encodedViewArguments . ')">View</a></li>';
                    }

                    if ($canAddNote) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="addNotesModal(' . (int) $sale->id . ')">Add Note</a></li>';
                    }

                    if ($canChangeStatus) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatusModal(' . (int) $sale->id . ',' . $sale->status . ')">Mark As Open/Close</a></li>';
                    }

                    if ($canMarkOnHold && $sale->status == 1 && $sale->is_on_hold == 0) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleOnHoldStatusModal(' . (int) $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }

                    if ($canBlacklist) {
                        $blockLabel = $sale->is_blacklist ? 'Remove from Blacklist' : 'Add to Blacklist';
                        $blockStatus = $sale->is_blacklist ? 0 : 1;
                        $action .= '<li><a class="dropdown-item ' . ($sale->is_blacklist ? 'text-success' : 'text-danger') . '" href="javascript:void(0);" onclick="toggleSaleBlacklist(' . (int) $sale->id . ', ' . $blockStatus . ')">' . $blockLabel . '</a></li>';
                    }

                    $action .= '<li><hr class="dropdown-divider"></li>';

                    if ($canViewDocuments) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . (int) $sale->id . ')">View Documents</a></li>';
                    }

                    if ($canViewHistory) {
                        $url = route('sales.history', ['id' => (int) $sale->id]);
                        $action .= '<li><a class="dropdown-item" target="_blank" href="' . $url . '">View History</a></li>';
                    }

                    if ($canViewNotesHistory) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . (int) $sale->id . ')">Notes History</a></li>';
                    }

                    if ($canViewManagerDetails) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . (int) $sale->unit_id . ')">Manager Details</a></li>';
                    }

                    $action .= '</ul></div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'position_type', 'sale_postcode', 'qualification', 'job_title', 'job_source', 'cv_limit', 'open_date', 'job_category', 'office_name', 'salary', 'unit_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function getDirectSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $sourceFilter = $request->input('source_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // The latest "opened" audit per sale (open_date), as one derived table joined by id, the
        // getSales pattern (P-02), instead of an IN (MAX(id) … GROUP BY) evaluated in the join.
        $latestOpenAudit = DB::table('audits')
            ->selectRaw('MAX(id) AS id, auditable_id')
            ->where('auditable_type', 'Horsefly\Sale')
            ->where('message', 'like', '%sale-opened%')
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
                'open_audits.created_at as open_date'
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            // Join only the latest "opened" audit for each sale
            ->leftJoinSub($latestOpenAudit, 'latest_open_audit_ids', 'latest_open_audit_ids.auditable_id', '=', 'sales.id')
            ->leftJoin('audits as open_audits', 'open_audits.id', '=', 'latest_open_audit_ids.id')
            ->with(['jobTitle', 'jobCategory'])      // the only relations the rows use (the rest are joined)
            ->where('sales.status', 1)
            ->whereNull('sales.deleted_at')
            ->where('sales.is_on_hold', 0)
            ->leftJoinSub(DB::table('cv_notes')->selectRaw('sale_id, COUNT(*) AS cv_count')->where('status', 1)->groupBy('sale_id'),
                'cv_counts', 'cv_counts.sale_id', '=', 'sales.id')   // one grouped count, not a subquery per row
            ->addSelect(DB::raw('COALESCE(cv_counts.cv_count, 0) AS no_of_sent_cv'));

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    $likeSearch = "%{$searchTerm}%";

                    $query->whereRaw('LOWER(sales.sale_postcode) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.experience) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.timing) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_description) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.position_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.cv_limit) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.salary) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.benefits) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.qualification) LIKE ?', [$likeSearch]);

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($likeSearch) {
                        $q->where('job_titles.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($likeSearch) {
                        $q->where('job_categories.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('unit', function ($q) use ($likeSearch) {
                        $q->where('units.unit_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } else if ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }

        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->where('sales.office_id', $officeFilter);
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->where('sales.user_id', $userFilter);
        }

        // Filter by category if it's not empty
        switch ($limitCountFilter) {
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->where('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->where('sales.job_title_id', $titleFilter);
        }

        if ($dateRangeFilter) {
            // Parse the date range filter (format: "YYYY-MM-DD|YYYY-MM-DD")
            [$start_date, $end_date] = explode('|', $dateRangeFilter);
            $start_date = trim($start_date) . ' 00:00:00';
            $end_date = trim($end_date) . ' 23:59:59';

            $model->where(function ($query) use ($start_date, $end_date) {
                $query->whereBetween('sales.updated_at', [$start_date, $end_date])
                    ->orWhereBetween('open_audits.created_at', [$start_date, $end_date]);
            });
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? $office->office_name : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? $unit->unit_name : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >0/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv . '/' . (int) $sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $button = '<a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>'; // Using accessor
                        return '<div class="d-flex align-items-center justify-content-between">' . $button . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-'; // Using accessor
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale Salary');
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = !empty($sale->sale_notes) ? $sale->sale_notes : ($sale->latest_note ?? '-');
                    preg_match('/https?:\/\/[^\s]+/', $notesIndex, $matches);
                    $url = $matches[0] ?? null;
                    $notesValue = $url ? str_replace($url, '', $notesIndex) : $notesIndex;
                    $shortNotes = Str::limit(trim(strip_tags($notesValue)), 80);
                    $urlCTA = $url ? '<a href="' . $url . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link"><iconify-icon icon="mdi:link-variant"></iconify-icon> URL</a>' : '';

                    return '<div class="d-flex flex-column align-items-start">
                                <a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8')) . '\', \'' . ucwords($sale->office_name ?? '-') . '\', \'' . ucwords($sale->unit_name ?? '-') . '\', \'' . htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8') . '\')">
                                    ' . $shortNotes . '
                                </a>
                            </div>' . $urlCTA . '</div>';
                })
                ->addColumn('position_type', function ($sale) {
                    $status = '-';
                    if ($sale->position_type == 'full time') {
                        $status = "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    } elseif ($sale->position_type == 'part time') {
                        $status = "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    }
                    return $status;
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type));
                    $position = '<span class="badge bg-primary">' . $position_type . '</span>';

                    $action = '';
                    $action = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int) $sale->id . ',
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                    if ($sale->status == 1) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatusModal(' . (int) $sale->id . ', 0)">Mark as Close</a></li>';
                    } else {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatusModal(' . (int) $sale->id . ', 1)">Mark as Open</a></li>';
                    }
                    if ($sale->status == 1 && $sale->is_on_hold == 0) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleOnHoldStatusModal(' . (int) $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }
                    $url = route('sales.history', ['id' => (int) $sale->id]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . (int) $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="' . $url . '">View History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . (int) $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . (int) $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'qualification', 'position_type', 'sale_postcode', 'cv_limit', 'open_date', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getRejectedSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $sourceFilter = $request->input('source_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        // The latest "rejected" audit per sale, as one derived table joined by id (P-02).
        $latestRejectAudit = DB::table('audits')
            ->selectRaw('MAX(id) AS id, auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereIn('message', ['sale-rejected', 'reject'])
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
                'rejected_audits.created_at as rejected_date'
            ])
            ->where('sales.status', 3) // rejected sales
            ->whereNull('sales.deleted_at')
            ->where('sales.is_on_hold', 0) // Not on hold
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            // Join only the latest "rejected" audit for each sale
            ->leftJoinSub($latestRejectAudit, 'latest_reject_audit_ids', 'latest_reject_audit_ids.auditable_id', '=', 'sales.id')
            ->leftJoin('audits as rejected_audits', 'rejected_audits.id', '=', 'latest_reject_audit_ids.id')
            ->with(['jobTitle', 'jobCategory'])      // the only relations the rows use (the rest are joined)
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')

            // Join the actual sale_notes record
            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')
            ->leftJoinSub(DB::table('cv_notes')->selectRaw('sale_id, COUNT(*) AS cv_count')->where('status', 1)->groupBy('sale_id'),
                'cv_counts', 'cv_counts.sale_id', '=', 'sales.id')   // one grouped count, not a subquery per row
            ->addSelect(DB::raw('COALESCE(cv_counts.cv_count, 0) AS no_of_sent_cv'));

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        if ($request->filled('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            // 1. Get Matching IDs from Scout (searches internal Sale columns like postcode, UID, etc.)
            $saleIds = Sale::search($searchTerm)->keys()->toArray();

            // 2. Combine Scout results with direct relationship searches
            $model->where(function ($query) use ($searchTerm, $saleIds) {
                // IDs from Scout
                if (!empty($saleIds)) {
                    $query->whereIn('sales.id', $saleIds);
                }

                // Plus manual searches for relationships (Scout's database driver doesn't JOIN)
                $query->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } else if ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }

        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        // Filter by category if it's not empty
        switch ($limitCountFilter) {
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }

        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        $now = Carbon::today();
        switch ($dateRangeFilter) {
            case 'last-3-months':
                $startDate = $now->copy()->subMonths(3)->startOfDay();
                $endDate = $now->endOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;

            case 'last-6-months':
                $endDate = $now->copy()->subMonths(3)->endOfDay();
                $startDate = $endDate->copy()->subMonths(6)->startOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;

            case 'last-9-months':
                $endDate = $now->copy()->subMonths(9)->endOfDay();
                $startDate = $endDate->copy()->subMonths(9)->startOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;

            case 'other':
                $cutoffDate = $now->copy()->subMonths(18);
                $model->where('sales.updated_at', '<', $cutoffDate);
                break;

            default:
                $startDate = $now->copy()->subMonths(3)->startOfDay();
                $endDate = $now->endOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? ucwords($office->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? ucwords($unit->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('rejected_date', function ($sale) {
                    return $sale->rejected_date ? Carbon::parse($sale->rejected_date)->format('d M Y, h:i A') : '-'; // Using accessor
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                        data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode">
                                        <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                    </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $button = '<a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>'; // Using accessor
                        return '<div class="d-flex align-items-center justify-content-between">' . $button . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >0/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv . '/' . (int) $sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale Salary');
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = !empty($sale->sale_notes) ? $sale->sale_notes : ($sale->latest_note ?? '-');
                    preg_match('/https?:\/\/[^\s]+/', $notesIndex, $matches);
                    $url = $matches[0] ?? null;
                    $notesValue = $url ? str_replace($url, '', $notesIndex) : $notesIndex;
                    $shortNotes = Str::limit(trim(strip_tags($notesValue)), 80);
                    $urlCTA = $url ? '<a href="' . $url . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link"><iconify-icon icon="mdi:link-variant"></iconify-icon> URL</a>' : '';

                    return '<div class="d-flex flex-column align-items-start">
                                <a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8')) . '\', \'' . ucwords($sale->office_name ?? '-') . '\', \'' . ucwords($sale->unit_name ?? '-') . '\', \'' . htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8') . '\')">
                                    ' . $shortNotes . '
                                </a>
                            </div>' . $urlCTA . '</div>';
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('position_type', function ($sale) {
                    $status = '-';
                    if ($sale->position_type == 'full time') {
                        $status = "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    } elseif ($sale->position_type == 'part time') {
                        $status = "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    }
                    return $status;
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type));
                    $position = '<span class="badge bg-primary">' . $position_type . '</span>';

                    $action = '';
                    $action = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                ' . $sale->id . ',
                                \'' . e($posted_date) . '\',
                                \'' . e($office_name) . '\',
                                \'' . e($unit_name) . '\',
                                \'' . e($postcode) . '\',
                                \'' . e(strip_tags($jobCategory)) . '\',
                                \'' . e(strip_tags($jobTitle)) . '\',
                                \'' . e($status_badge) . '\',
                                \'' . e($sale->timing) . '\',
                                \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                \'' . e($sale->salary) . '\',
                                \'' . e(strip_tags($position)) . '\',
                                \'' . e($sale->qualification) . '\',
                                \'' . e($sale->benefits) . '\'
                            )">View</a></li>';
                    $action .= '<li>
                            <a class="dropdown-item" href="javascript:void(0);" title="Add Short Note" onclick="addNotesModal(' . $sale->id . ')">
                                Add Note
                            </a>
                        </li>';
                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatusModal(' . $sale->id . ', 1)">Mark as Open</a></li>';
                    if ($sale->status == 1 && $sale->is_on_hold == 0) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }
                    $url = route('sales.history', ['id' => $sale->id]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="' . $url . '" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'salary', 'position_type', 'sale_postcode', 'qualification', 'job_title', 'cv_limit', 'rejected_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getClosedSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', '');
        $categoryFilter = $request->input('category_filter', '');
        $sourceFilter = $request->input('source_filter', '');
        $titleFilter = $request->input('title_filter', '');
        $dateRangeFilter = $request->input('date_range_filter', '');
        $limitCountFilter = $request->input('cv_limit_filter', '');
        $officeFilter = $request->input('office_filter', '');
        $userFilter = $request->input('user_filter', '');

        // Subquery for CV counts
        $cvCountSub = DB::table('cv_notes')
            ->select('sale_id', DB::raw('COUNT(*) as cv_count'))
            ->where('status', 1)
            ->groupBy('sale_id');

        // Subquery for latest closed audit
        $latestAuditSub = DB::table('audits')->selectRaw('MAX(id) as id, auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')->whereIn('message', ['close', 'sale-closed'])
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
                'audits.created_at as closed_date',
                DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv')
            ])
            ->where('sales.status', 0)
            ->where('sales.is_on_hold', 0)
            ->whereNull('sales.deleted_at')
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')->leftJoinSub($latestAuditSub, 'latest_closed_audit_ids', 'latest_closed_audit_ids.auditable_id', '=', 'sales.id')
            ->leftJoin('audits', 'audits.id', '=', 'latest_closed_audit_ids.id')
            ->leftJoinSub($cvCountSub, 'cv_counts', 'cv_counts.sale_id', '=', 'sales.id')
            ->leftJoin(DB::raw('(SELECT sale_id, MAX(id) AS latest_id FROM sale_notes GROUP BY sale_id) AS latest_notes'), 'sales.id', '=', 'latest_notes.sale_id')
            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user']);

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        if ($request->filled('search.value')) {
            $searchTerm = (string) $request->input('search.value');
            $saleIds = Sale::search($searchTerm)->keys()->toArray();
            $model->where(function ($query) use ($searchTerm, $saleIds) {
                if (!empty($saleIds)) {
                    $query->whereIn('sales.id', $saleIds);
                }
                $query->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        if ($userFilter) {
            $model->whereIn('sales.user_id', (array) $userFilter);
        }
        if ($officeFilter) {
            $model->whereIn('sales.office_id', (array) $officeFilter);
        }
        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', (array) $categoryFilter);
        }
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', (array) $titleFilter);
        }

        switch ($limitCountFilter) {
            case 'max':
                $model->havingRaw('COALESCE(cv_counts.cv_count, 0) >= sales.cv_limit');
                break;
            case 'not max':
                $model->havingRaw('COALESCE(cv_counts.cv_count, 0) > 0 AND COALESCE(cv_counts.cv_count, 0) < sales.cv_limit');
                break;
            case 'zero':
                $model->havingRaw('COALESCE(cv_counts.cv_count, 0) = 0');
                break;
        }

        if ($dateRangeFilter) {
            $now = Carbon::today();
            switch ($dateRangeFilter) {
                case 'last-3-months':
                    $startDate = $now->copy()->subMonths(3)->startOfDay();
                    $endDate = $now->endOfDay();
                    $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                    break;
                case 'last-6-months':
                    $startDate = $now->copy()->subMonths(6)->startOfDay();
                    $endDate = $now->copy()->subMonths(3)->endOfDay();
                    $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                    break;
                case 'last-9-months':
                    $startDate = $now->copy()->subMonths(9)->startOfDay();
                    $endDate = $now->copy()->subMonths(6)->endOfDay();
                    $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                    break;
                case 'other':
                    $cutoffDate = $now->copy()->subMonths(18);
                    $model->where('sales.updated_at', '<', $cutoffDate);
                    break;
                default:
                    $startDate = $now->copy()->subMonths(3)->startOfDay();
                    $endDate = $now->endOfDay();
                    $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                    break;
            }
        } else {
            $now = Carbon::today();
            $startDate = $now->copy()->subMonths(3)->startOfDay();
            $endDate = $now->endOfDay();
            $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)->addIndexColumn()
                ->addColumn('office_name', function ($sale) {
                    return $sale->office_name ? ucwords($sale->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    return $sale->unit_name ? ucwords($sale->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                })
                ->addColumn('closed_date', function ($sale) {
                    return $sale->closed_date ? Carbon::parse($sale->closed_date)->format('d M Y, h:i A') : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $stype = $sale->job_type == 'specialist' ? '<br>(Specialist)' : '';
                    return $sale->job_category_name ? ucwords($sale->job_category_name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode"><iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon></button>';
                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        return '<div class="d-flex align-items-center justify-content-between"><a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>' . $copyBtn . '</div>';
                    }
                    return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                })
                ->addColumn('cv_limit', function ($sale) {
                    return $sale->no_of_sent_cv == $sale->cv_limit
                        ? '<span class="badge w-100 bg-danger" style="font-size:90%">0/' . $sale->cv_limit . '<br>Limit Reached</span>'
                        : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv) . '/' . (int) $sale->cv_limit . "<br>Limit Remains</span>";
                })->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at;
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at;
                })
                ->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale Salary');
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = !empty($sale->sale_notes) ? $sale->sale_notes : $sale->latest_note;
                    $notes = nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8'));
                    $shortNotes = Str::limit(trim($notes), 80);
                    if (!empty($notes)) {
                        return '<a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . $notes . '\', \'' . ucwords($sale->office_name) . '\', \'' . ucwords($sale->unit_name) . '\', \'' . htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8') . '\')">
                               ' . $shortNotes . '
                            </a>';
                    }
                    return '-';
                })
                ->addColumn('status', function ($sale) {
                    if ($sale->status == 1 && $sale->is_on_hold == 1)
                        return '<span class="badge bg-warning">On Hold</span>';
                    if ($sale->status == 1 && $sale->is_re_open == 1)
                        return '<span class="badge bg-dark">Re-Open</span>';
                    if ($sale->status == 0)
                        return '<span class="badge bg-danger">Closed</span>';
                    if ($sale->status == 1)
                        return '<span class="badge bg-success">Open</span>';
                    if ($sale->status == 2)
                        return '<span class="badge bg-warning">Pending</span>';
                    if ($sale->status == 3)
                        return '<span class="badge bg-danger">Rejected</span>';
                    return '-';
                })
                ->addColumn('position_type', function ($sale) {
                    if ($sale->position_type == 'full time')
                        return "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    if ($sale->position_type == 'part time')
                        return "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    return '-';
                })
                ->addColumn('action', function ($sale) {
                    $office_name = $sale->office_name ? ucwords($sale->office_name) : '-';
                    $unit_name = $sale->unit_name ? ucwords($sale->unit_name) : '-';
                    $jobTitle = $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                    $stype = $sale->job_type == 'specialist' ? '<br>(Specialist)' : '';
                    $jobCategory = $sale->job_category_name ? ucwords($sale->job_category_name) . $stype : '-';
                    $status_badge = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1)
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    elseif ($sale->status == 1 && $sale->is_re_open == 1)
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    elseif ($sale->status == 0)
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    elseif ($sale->status == 1)
                        $status_badge = '<span class="badge bg-success">Open</span>';

                    $action = '<div class="dropdown">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                 ' . $sale->id . ',
                                 \'' . e($sale->formatted_created_at) . '\',
                                 \'' . e($office_name) . '\',
                                 \'' . e($unit_name) . '\',
                                 \'' . e($sale->sale_postcode) . '\',
                                 \'' . e(strip_tags($jobCategory)) . '\',
                                 \'' . e(strip_tags($jobTitle)) . '\',
                                 \'' . e($status_badge) . '\',
                                 \'' . e($sale->timing) . '\',
                                 \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                 \'' . e($sale->salary) . '\',
                                 \'' . e(strip_tags($sale->position_type)) . '\',
                                 \'' . e($sale->qualification) . '\',
                                 \'' . e($sale->benefits) . '\'
                             )">View</a></li>';
                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" title="Add Short Note" onclick="addNotesModal(' . $sale->id . ')">Add Note</a></li>';
                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatusModal(' . $sale->id . ', 1)">Mark as Open</a></li>';
                    if ($sale->status == 1 && $sale->is_on_hold == 0) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }
                    $url = route('sales.history', ['id' => $sale->id]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="' . $url . '" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . (int) $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';
                    return $action;
                })->rawColumns(['sale_notes', 'experience', 'salary', 'position_type', 'sale_postcode', 'qualification', 'job_title', 'cv_limit', 'closed_date', 'job_category', 'office_name', 'unit_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function getOpenSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', '');
        $categoryFilter = $request->input('category_filter', []);
        $sourceFilter = $request->input('source_filter', []);
        $titleFilter = $request->input('title_filter', []);
        $dateFlockFilter = $request->input('date_flock_filter', '');
        $dateRangeFilter = $request->input('date_range_filter', '');
        $limitCountFilter = $request->input('cv_limit_filter', '');
        $officeFilter = $request->input('office_filter', []);
        $userFilter = $request->input('user_filter', []);

        // 1. Efficient Subqueries for Join
        $latestAuditSub = DB::table('audits')->selectRaw('MAX(id) as id, auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereIn('message', ['open', 'sale-opened'])
            ->groupBy('auditable_id');

        $cvCountSub = DB::table('cv_notes')->select('sale_id', DB::raw('COUNT(*) as cv_count'))
            ->where('status', 1)
            ->groupBy('sale_id');

        $model = Sale::query()
            ->select([
                'sales.id',
                'sales.sale_uid',
                'sales.office_id',
                'sales.unit_id',
                'sales.user_id',
                'sales.job_category_id',
                'sales.job_title_id',
                'sales.job_source_id',
                'sales.job_type',
                'sales.position_type',
                'sales.sale_postcode',
                'sales.cv_limit',
                'sales.timing',
                'sales.status',
                'sales.is_on_hold',
                'sales.is_re_open',
                'sales.lat',
                'sales.lng',
                'sales.sale_notes',
                'sales.created_at',
                'sales.updated_at',
                'sales.experience',
                'sales.salary',
                'sales.qualification',
                'sales.benefits',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
                'audits.created_at as open_date',
                DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv')
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'sales.job_source_id', '=', 'job_sources.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->leftJoinSub($latestAuditSub, 'latest_open_audit_ids', 'latest_open_audit_ids.auditable_id', '=', 'sales.id')
            ->leftJoin('audits', 'audits.id', '=', 'latest_open_audit_ids.id')
            ->leftJoinSub($cvCountSub, 'cv_counts', 'cv_counts.sale_id', '=', 'sales.id')
            ->where('sales.status', 1)
            ->whereNull('sales.deleted_at')
            ->where('sales.is_on_hold', 0);

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // 2. Scout Search
        if ($request->filled('search.value')) {
            $searchTerm = (string) $request->input('search.value');
            $saleIds = Sale::search($searchTerm)->keys()->toArray();
            $model->where(function ($query) use ($searchTerm, $saleIds) {
                if (!empty($saleIds))
                    $query->whereIn('sales.id', $saleIds);
                $query->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_sources.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        // 3. Filters
        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }
        if (isset($typeFilter) && $typeFilter !== 'all types') {
            $model->where('sales.job_type', $typeFilter);
        }
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }

        switch ($limitCountFilter) {
            case 'max':
                $model->havingRaw('COALESCE(cv_counts.cv_count, 0) >= sales.cv_limit');
                break;
            case 'not max':
                $model->havingRaw('COALESCE(cv_counts.cv_count, 0) > 0 AND COALESCE(cv_counts.cv_count, 0) < sales.cv_limit');
                break;
            case 'zero':
                $model->havingRaw('COALESCE(cv_counts.cv_count, 0) = 0');
                break;
        }

        if ($dateRangeFilter) {
            [$start, $end] = explode('|', $dateRangeFilter);
            $model->whereBetween('sales.updated_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
        }

        $now = Carbon::today();
        switch ($dateFlockFilter) {
            case 'last-3-months':
                $model->whereBetween('sales.updated_at', [$now->copy()->subMonths(3)->startOfDay(), $now->endOfDay()]);
                break;
            case 'last-6-months':
                $model->whereBetween('sales.updated_at', [$now->copy()->subMonths(9)->startOfDay(), $now->copy()->subMonths(3)->endOfDay()]);
                break;
            case 'last-9-months':
                $model->whereBetween('sales.updated_at', [$now->copy()->subMonths(18)->startOfDay(), $now->copy()->subMonths(9)->endOfDay()]);
                break;
            case 'other':
                $model->where('sales.updated_at', '<', $now->copy()->subMonths(18)->endOfDay());
                break;
            default:
                $model->whereBetween('sales.updated_at', [$now->copy()->subMonths(3)->startOfDay(), $now->endOfDay()]);
                break;
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)->addIndexColumn()
                ->addColumn('office_name', function ($sale) {
                    return $sale->office_name ? ucwords($sale->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    return $sale->unit_name ? ucwords($sale->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                })
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $stype = $sale->job_type == 'specialist' ? '<br>(Specialist)' : '';
                    return $sale->job_category_name ? ucwords($sale->job_category_name) : '-';
                })
                ->addColumn('job_source', function ($sale) {
                    return $sale->job_source_name
                        ? '<span class="badge bg-light text-dark">' . e(ucwords($sale->job_source_name)) . '</span>'
                        : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode"><iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon></button>';
                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        return '<div class="d-flex align-items-center justify-content-between"><a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>' . $copyBtn . '</div>';
                    }
                    return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                })
                ->addColumn('cv_limit', function ($sale) {
                    return $sale->no_of_sent_cv == $sale->cv_limit
                        ? '<span class="badge w-100 bg-danger" style="font-size:90%">0/' . $sale->cv_limit . '<br>Limit Reached</span>'
                        : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv) . '/' . (int) $sale->cv_limit . "<br>Limit Remains</span>";
                })->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at;
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at;
                })
                ->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale Salary');
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = !empty($sale->sale_notes) ? $sale->sale_notes : ($sale->latest_note ?? '-');
                    preg_match('/https?:\/\/[^\s]+/', $notesIndex, $matches);
                    $url = $matches[0] ?? null;
                    $notesValue = $url ? str_replace($url, '', $notesIndex) : $notesIndex;
                    $shortNotes = Str::limit(trim(strip_tags($notesValue)), 80);
                    $urlCTA = $url ? '<a href="' . $url . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link"><iconify-icon icon="mdi:link-variant"></iconify-icon> URL</a>' : '';

                    return '<div class="d-flex flex-column align-items-start">
                                <a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8')) . '\', \'' . ucwords($sale->office_name ?? '-') . '\', \'' . ucwords($sale->unit_name ?? '-') . '\', \'' . htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8') . '\')">
                                    ' . $shortNotes . '
                                </a>
                            </div>' . $urlCTA . '</div>';
                })
                ->addColumn('status', function ($sale) {
                    if ($sale->status == 1 && $sale->is_on_hold == 1)
                        return '<span class="badge bg-warning">On Hold</span>';
                    if ($sale->status == 1 && $sale->is_re_open == 1)
                        return '<span class="badge bg-dark">Re-Open</span>';
                    if ($sale->status == 0)
                        return '<span class="badge bg-danger">Closed</span>';
                    if ($sale->status == 1)
                        return '<span class="badge bg-success">Active</span>';
                    return '<span class="badge bg-secondary">-</span>';
                })
                ->addColumn('position_type', function ($sale) {
                    if ($sale->position_type == 'full time')
                        return "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    if ($sale->position_type == 'part time')
                        return "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    return '-';
                })
                ->addColumn('action', function ($sale) {
                    $office_name = $sale->office_name ? ucwords($sale->office_name) : '-';
                    $unit_name = $sale->unit_name ? ucwords($sale->unit_name) : '-';
                    $jobTitle = $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                    $stype = $sale->job_type == 'specialist' ? '<br>(Specialist)' : '';
                    $jobCategory = $sale->job_category_name ? ucwords($sale->job_category_name) : '-';
                    $status_badge = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1)
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    elseif ($sale->status == 1 && $sale->is_re_open == 1)
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    elseif ($sale->status == 0)
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    elseif ($sale->status == 1)
                        $status_badge = '<span class="badge bg-success">Active</span>';

                    $action = '<div class="dropdown">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . $sale->id . ',
                                    \'' . e($sale->formatted_created_at) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($sale->sale_postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($sale->position_type)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" title="Add Short Note" onclick="addNotesModal(' . $sale->id . ')">Add Note</a></li>';
                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatusModal(' . $sale->id . ', 0)">Mark as Close</a></li>';
                    if ($sale->status == 1 && $sale->is_on_hold == 0) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }
                    $url = route('sales.history', ['id' => $sale->id]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                     <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="' . $url . '" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . (int) $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';
                    return $action;
                })->rawColumns(['sale_notes', 'job_source', 'experience', 'salary', 'position_type', 'sale_postcode', 'qualification', 'cv_limit', 'job_title', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function pendingOnHoldSales(Request $request)
    {
        $typeFilter       = $request->input('type_filter', '');
        $categoryFilter   = $request->input('category_filter', []);
        $sourceFilter     = $request->input('source_filter', []);
        $titleFilter      = $request->input('title_filter', []);
        $dateFlockFilter  = $request->input('date_flock_filter', '');
        $dateRangeFilter  = $request->input('date_range_filter', '');
        $limitCountFilter = $request->input('cv_limit_filter', '');
        $officeFilter     = $request->input('office_filter', []);
        $userFilter       = $request->input('user_filter', []);

        // Lean COUNT path (same approach as getSales): no full-table sale_notes /
        // cv_notes / audits aggregations on the base query. Notes, CV counts and
        // open_date are page-hydrated via warmSaleListEnrichment().
        $model = Sale::query()
            ->select([
                'sales.id',
                'sales.sale_uid',
                'sales.office_id',
                'sales.unit_id',
                'sales.user_id',
                'sales.job_category_id',
                'sales.job_title_id',
                'sales.job_source_id',
                'sales.job_type',
                'sales.position_type',
                'sales.sale_postcode',
                'sales.cv_limit',
                'sales.timing',
                'sales.status',
                'sales.is_on_hold',
                'sales.is_re_open',
                'sales.lat',
                'sales.lng',
                'sales.sale_notes',
                'sales.created_at',
                'sales.updated_at',
                'sales.experience',
                'sales.salary',
                'sales.qualification',
                'sales.benefits',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
            ])
            ->where('sales.status', 1)
            ->whereNull('sales.deleted_at')
            ->where('sales.is_on_hold', 2)
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'sales.job_source_id', '=', 'job_sources.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id');

        $auditMessages = ['open', 'sale-opened'];

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];
        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        if ($request->filled('search.value')) {
            $searchTerm = (string) $request->input('search.value');
            $saleIds = Sale::search($searchTerm)->keys()->toArray();

            $model->where(function ($query) use ($searchTerm, $saleIds) {
                if (!empty($saleIds)) {
                    $query->whereIn('sales.id', $saleIds);
                }
                $query->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_sources.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        if ($typeFilter === 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } elseif ($typeFilter === 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }

        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }

        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // CV-limit filter is the only case that needs the cv_notes aggregation in SQL.
        $needsCvCountJoin = in_array($limitCountFilter, ['max', 'not max', 'zero'], true);
        if ($needsCvCountJoin) {
            $cvCountSub = DB::table('cv_notes')
                ->selectRaw('sale_id, COUNT(*) as cv_count')
                ->where('status', 1)
                ->groupBy('sale_id');

            $model->leftJoinSub($cvCountSub, 'cv_counts', 'cv_counts.sale_id', '=', 'sales.id')
                ->addSelect(DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv'));

            switch ($limitCountFilter) {
                case 'max':
                    $model->whereRaw('COALESCE(cv_counts.cv_count, 0) >= sales.cv_limit');
                    break;
                case 'not max':
                    $model->whereRaw('COALESCE(cv_counts.cv_count, 0) > 0 AND COALESCE(cv_counts.cv_count, 0) < sales.cv_limit');
                    break;
                case 'zero':
                    $model->whereRaw('COALESCE(cv_counts.cv_count, 0) = 0');
                    break;
            }
        }

        $orderColumn = $request->has('order')
            ? $request->input('columns.' . $request->input('order.0.column') . '.data')
            : null;

        // Join audits only when sorting by open_date or filtering by a date range
        // that also matches audit open dates (legacy behaviour).
        $needsAuditJoin = $orderColumn === 'open_date' || filled($dateRangeFilter);
        if ($needsAuditJoin) {
            $model->leftJoinSub(
                DB::table('audits')
                    ->selectRaw('MAX(id) as id, auditable_id')
                    ->where('auditable_type', 'Horsefly\\Sale')
                    ->whereIn('message', $auditMessages)
                    ->groupBy('auditable_id'),
                'latest_open_audit_ids',
                'latest_open_audit_ids.auditable_id',
                '=',
                'sales.id'
            )->leftJoin('audits as open_audits', 'open_audits.id', '=', 'latest_open_audit_ids.id')
                ->addSelect('open_audits.created_at as open_date');
        }

        if ($dateRangeFilter) {
            [$start_date, $end_date] = array_map('trim', explode('|', $dateRangeFilter));
            $start_date .= ' 00:00:00';
            $end_date .= ' 23:59:59';

            $model->where(function ($query) use ($start_date, $end_date, $needsAuditJoin) {
                $query->whereBetween('sales.updated_at', [$start_date, $end_date]);
                if ($needsAuditJoin) {
                    $query->orWhereBetween('open_audits.created_at', [$start_date, $end_date]);
                }
            });
        }

        $now = Carbon::today();
        switch ($dateFlockFilter) {
            case 'last-3-months':
                $model->whereBetween('sales.updated_at', [
                    $now->copy()->subMonths(3)->startOfDay(),
                    $now->copy()->endOfDay(),
                ]);
                break;
            case 'last-6-months':
                $model->whereBetween('sales.updated_at', [
                    $now->copy()->subMonths(9)->startOfDay(),
                    $now->copy()->subMonths(3)->endOfDay(),
                ]);
                break;
            case 'last-9-months':
                $model->whereBetween('sales.updated_at', [
                    $now->copy()->subMonths(18)->startOfDay(),
                    $now->copy()->subMonths(9)->endOfDay(),
                ]);
                break;
            case 'other':
                $model->where('sales.updated_at', '<', $now->copy()->subMonths(18)->endOfDay());
                break;
            default:
                // Preserve previous default: last 3 months when no flock filter sent.
                $model->whereBetween('sales.updated_at', [
                    $now->copy()->subMonths(3)->startOfDay(),
                    $now->copy()->endOfDay(),
                ]);
                break;
        }

        $sortMap = [
            'sale_uid'      => 'sales.sale_uid',
            'office_name'   => 'offices.office_name',
            'unit_name'     => 'units.unit_name',
            'job_title'     => 'job_titles.name',
            'job_category'  => 'job_categories.name',
            'job_source'    => 'job_sources.name',
            'user_name'     => 'users.name',
            'sale_postcode' => 'sales.sale_postcode',
            'position_type' => 'sales.position_type',
            'status'        => 'sales.status',
            'created_at'    => 'sales.created_at',
            'updated_at'    => 'sales.updated_at',
            'open_date'     => 'open_audits.created_at',
            'no_of_sent_cv' => DB::raw('COALESCE(cv_counts.cv_count, 0)'),
            'cv_limit'      => 'sales.cv_limit',
        ];

        // Deferred via DataTables ->order() so Yajra's COUNT wrapper does not pay ORDER BY.
        $applySorting = function ($query) use ($request, $sortMap) {
            if ($request->has('order')) {
                $orderColumn    = $request->input('columns.' . $request->input('order.0.column') . '.data');
                $orderDirection = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

                if (isset($sortMap[$orderColumn])) {
                    $query->orderBy($sortMap[$orderColumn], $orderDirection);
                } else {
                    $query->orderBy('sales.updated_at', 'desc');
                }
            } else {
                $query->orderBy('sales.updated_at', 'desc');
            }
        };

        if ($request->ajax()) {
            $pageStart  = max(0, (int) $request->input('start', 0));
            $pageLength = (int) $request->input('length', 10);
            if ($pageLength < 1 || $pageLength > 100) {
                $pageLength = 10;
            }
            $pageIdQuery = clone $model;
            $applySorting($pageIdQuery);
            $pageIds = $pageIdQuery->skip($pageStart)->take($pageLength)->pluck('id')->all();
            $this->warmSaleListEnrichment($pageIds, $auditMessages);

            return DataTables::eloquent($model)
                ->skipTotalRecords()
                ->order(fn($query) => $applySorting($query))
                ->addIndexColumn()
                ->addColumn('office_name', function ($sale) {
                    return $sale->office_name ? ucwords($sale->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    return $sale->unit_name ? ucwords($sale->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                })
                ->addColumn('job_source', function ($sale) {
                    return $sale->job_source_name
                        ? '<span class="badge bg-light text-dark">' . e(ucwords($sale->job_source_name)) . '</span>'
                        : '-';
                })
                ->addColumn('open_date', function ($sale) use ($auditMessages) {
                    $this->hydrateSaleListRow($sale, $auditMessages);
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $stype = $sale->job_type === 'specialist'
                        ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                        : '';
                    return $sale->job_category_name ? ucwords($sale->job_category_name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $button = '<a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $button . $copyBtn . '</div>';
                    }

                    return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at;
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at;
                })
                ->addColumn('cv_limit', function ($sale) use ($auditMessages) {
                    $this->hydrateSaleListRow($sale, $auditMessages);
                    return $sale->no_of_sent_cv == $sale->cv_limit
                        ? '<span class="badge w-100 bg-danger" style="font-size:90%">0/' . $sale->cv_limit . '<br>Limit Reached</span>'
                        : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv . '/' . (int) $sale->cv_limit) . "<br>Limit Remains</span>";
                })
                ->addColumn('status', function ($sale) {
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        return '<span class="badge bg-warning">On Hold</span>';
                    }
                    if ($sale->status == 1 && $sale->is_on_hold == 2) {
                        return '<span class="badge bg-warning">Pending On Hold</span>';
                    }
                    if ($sale->status == 1 && $sale->is_re_open == 1) {
                        return '<span class="badge bg-dark">Re-Open</span>';
                    }
                    if ($sale->status == 0) {
                        return '<span class="badge bg-danger">Closed</span>';
                    }
                    if ($sale->status == 1) {
                        return '<span class="badge bg-success">Active</span>';
                    }
                    if ($sale->status == 2) {
                        return '<span class="badge bg-warning">Pending</span>';
                    }
                    if ($sale->status == 3) {
                        return '<span class="badge bg-danger">Rejected</span>';
                    }
                    return '';
                })
                ->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale Salary');
                })
                ->addColumn('sale_notes', function ($sale) use ($auditMessages) {
                    $this->hydrateSaleListRow($sale, $auditMessages);
                    $notesIndex = !empty($sale->latest_note)
                        ? $sale->latest_note
                        : (!empty($sale->sale_notes) ? $sale->sale_notes : '-');

                    preg_match('/https?:\/\/[^\s]+/', $notesIndex, $matches);
                    $url = $matches[0] ?? null;
                    $notesValue = $url ? str_replace($url, '', $notesIndex) : $notesIndex;
                    $shortNotes = Str::limit(trim(strip_tags($notesValue)), 80);
                    $urlCTA = $url
                        ? '<a href="' . $url . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link"><iconify-icon icon="mdi:link-variant"></iconify-icon> URL</a>'
                        : '';

                    return '<div class="d-flex flex-column align-items-start">
                                <a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8')) . '\', \'' . ucwords($sale->office_name ?? '-') . '\', \'' . ucwords($sale->unit_name ?? '-') . '\', \'' . htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8') . '\')">
                                    ' . $shortNotes . '
                                </a>
                            </div>' . $urlCTA . '</div>';
                })
                ->addColumn('position_type', function ($sale) {
                    if ($sale->position_type == 'full time') {
                        return "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    }
                    if ($sale->position_type == 'part time') {
                        return "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    }
                    return '-';
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_name = ucwords($sale->office_name ?? '-');
                    $unit_name = ucwords($sale->unit_name ?? '-');
                    $jobTitle = $sale->job_title_name ? strtoupper($sale->job_title_name) : '-';
                    $stype = $sale->job_type === 'specialist' ? '<br>(' . ucwords($sale->job_type) . ')' : '';
                    $jobCategory = $sale->job_category_name ? ucwords($sale->job_category_name) . $stype : '-';

                    $status_badge = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_on_hold == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($position_type) . '</span>';

                    $action = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . $sale->id . ',
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience ?? '', ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                    $action .= '<li>
                            <a class="dropdown-item" href="javascript:void(0);" title="Add Short Note" onclick="addNotesModal(' . $sale->id . ')">
                                Add Note
                            </a>
                        </li>';
                    $action .= '<li>
                                    <a class="dropdown-item" href="javascript:void(0);" data-sale-id="' . $sale->id . '" data-action="approve">
                                        Mark Approved
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0);" data-sale-id="' . $sale->id . '" data-action="disapprove">
                                        Mark Disapproved
                                    </a>
                                </li>';

                    $url = route('sales.history', ['id' => $sale->id]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="' . $url . '" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'job_source', 'salary', 'position_type', 'sale_postcode', 'qualification', 'cv_limit', 'job_title', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function getOnHoldSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $sourceFilter = $request->input('source_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)


        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
            ])
            ->where('sales.status', 1)
            ->where('sales.is_on_hold', 1)
            ->whereNull('sales.deleted_at')
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')
            // Join the actual sale_notes record
            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')
            ->selectRaw(DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) as no_of_sent_cv"));

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        if ($request->filled('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            // 1. Get Matching IDs from Scout (searches internal Sale columns like postcode, UID, etc.)
            $saleIds = Sale::search($searchTerm)->keys()->toArray();

            // 2. Combine Scout results with direct relationship searches
            $model->where(function ($query) use ($searchTerm, $saleIds) {
                // IDs from Scout
                if (!empty($saleIds)) {
                    $query->whereIn('sales.id', $saleIds);
                }

                // Plus manual searches for relationships (Scout's database driver doesn't JOIN)
                $query->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } else if ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }

        // Filter by category if it's not empty
        switch ($limitCountFilter) {
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? ucwords($office->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? ucwords($unit->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $button = '<a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>'; // Using accessor
                        return '<div class="d-flex align-items-center justify-content-between">' . $button . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >0/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int) $sale->cv_limit - (int) $sale->no_of_sent_cv . '/' . (int) $sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })->addColumn('qualification', function ($sale) {
                    return $this->formatWithUrlCTA($sale->qualification, 'qua', $sale->id, 'Sale Qualification');
                })
                ->addColumn('experience', function ($sale) {
                    return $this->formatWithUrlCTA($sale->experience, 'exp', $sale->id, 'Sale Experience');
                })
                ->addColumn('salary', function ($sale) {
                    return $this->formatWithUrlCTA($sale->salary, 'slry', $sale->id, 'Sale Salary');
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = !empty($sale->sale_notes) ? $sale->sale_notes : ($sale->latest_note ?? '-');
                    preg_match('/https?:\/\/[^\s]+/', $notesIndex, $matches);
                    $url = $matches[0] ?? null;
                    $notesValue = $url ? str_replace($url, '', $notesIndex) : $notesIndex;
                    $shortNotes = Str::limit(trim(strip_tags($notesValue)), 80);
                    $urlCTA = $url ? '<a href="' . $url . '" target="_blank" class="btn btn-xs btn-info rounded-pill px-2 ms-1" title="Open Link"><iconify-icon icon="mdi:link-variant"></iconify-icon> URL</a>' : '';

                    return '<div class="d-flex flex-column align-items-start">
                                <a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int) $sale->id . '\',\'' . nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8')) . '\', \'' . ucwords($sale->office_name ?? '-') . '\', \'' . ucwords($sale->unit_name ?? '-') . '\', \'' . htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8') . '\')">
                                    ' . $shortNotes . '
                                </a>
                            </div>' . $urlCTA . '</div>';
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('position_type', function ($sale) {
                    $status = '-';
                    if ($sale->position_type == 'full time') {
                        $status = "<span class='badge w-100 bg-primary'>" . ucwords($sale->position_type) . "</span>";
                    } elseif ($sale->position_type == 'part time') {
                        $status = "<span class='badge w-100 bg-info'>" . ucwords($sale->position_type) . "</span>";
                    }
                    return $status;
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type));
                    $position = '<span class="badge bg-primary">' . $position_type . '</span>';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $action = '';
                    $action .= '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . $sale->id . ',
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                    $action .= '<li>
                            <a class="dropdown-item" href="javascript:void(0);" title="Add Short Note" onclick="addNotesModal(' . $sale->id . ')">
                                Add Note
                            </a>
                        </li>';
                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 0)">Mark as Unhold</a></li>';
                    $url = route('sales.history', ['id' => $sale->id]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="' . $url . '" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'position_type', 'experience', 'salary', 'sale_postcode', 'qualification', 'cv_limit', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function getApplicantsBySaleRadius(Request $request)
    {
        if (!$request->ajax()) {
            return response()->json(['error' => 'Invalid request'], 400);
        }

        $validated = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'radius'  => 'nullable|numeric|min:1',
        ]);

        $sale_id = (int) $validated['sale_id'];
        $radius  = (float) ($validated['radius'] ?? 15);

        // Load the sale together with its job title row (for related_titles)
        $sale = Sale::with('jobTitle')->findOrFail($sale_id);

        if ($sale->lat === null || $sale->lng === null) {
            return response()->json(['error' => 'Sale location coordinates are missing.'], 422);
        }

        $filters = [
            'status_filter'    => $request->input('status_filter', ''),
            'cv_status_filter' => $request->input('cv_status_filter', ''),
            'source_filter'    => $request->input('source_filter', []),
            'title_filter'     => $request->input('title_filter', []),   // the ticked titles (was dropped)
            'category_id'      => $sale->job_category_id,
        ];

        $sale_cv_counts = CVNote::where('sale_id', $sale_id)
            ->where('status', 1)
            ->count();

        // The candidate query is shared with the Xplogent campaign queue
        // (App\Services\Xplogent\CandidateQueueService): title + related titles,
        // base filters, haversine radius, list filters and the latest note.
        $model = app(CandidateQueueService::class)->radiusQuery($sale, $radius, $filters)
            ->with([
                'cv_notes' => fn($q) => $q
                    ->select('id', 'applicant_id', 'sale_id', 'status', 'created_at')
                    ->orderByDesc('id'),
                'jobTitle:id,name',
                'jobCategory:id,name',
                'jobSource:id,name',
            ])
            ->selectRaw("
            CASE
                WHEN applicants.paid_status = 'close' THEN 1
                WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 1) THEN 2
                WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 0 AND cv_notes.sale_id = ?) THEN 3
                WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 0) THEN 4
                WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 2 AND cv_notes.sale_id = ? AND applicants.paid_status = 'open') THEN 5
                ELSE 6
            END AS paid_status_order
        ", [$sale_id, $sale_id]);

        // Sorting logic. The direction went raw into orderByRaw (SQL injection) and any
        // column name reached orderBy (most of the table's columns aren't real query columns,
        // so sorting them failed). Both are allowlisted now.
        if ($request->has('order')) {
            $orderColumn    = (string) $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = strtolower((string) $request->input('order.0.dir')) === 'asc' ? 'asc' : 'desc';
            $sortable = [
                'job_source' => 'applicants.job_source_id',
                'job_category' => 'applicants.job_category_id',
                'job_title' => 'applicants.job_title_id',
                'applicant_name' => 'applicants.applicant_name',
                'applicant_email' => 'applicants.applicant_email',
                'applicant_postcode' => 'applicants.applicant_postcode',
                'applicant_experience' => 'applicants.applicant_experience',
                'paid_status' => 'paid_status_order',
                'created_at' => 'applicants.created_at',
                'updated_at' => 'applicants.updated_at',
            ];

            if ($orderColumn === 'notes_created_at') {
                $model->orderByRaw('notes_created_at ' . $orderDirection);       // computed column
            } elseif (isset($sortable[$orderColumn])) {
                $model->orderBy($sortable[$orderColumn], $orderDirection);
            } else {
                $model->orderByRaw('notes_created_at DESC');
            }
        } else {
            // ✅ Default: latest note date first
            $model->orderByRaw('notes_created_at DESC');
        }

        // Search - first/last name tokens plus similar words; notes via EXISTS
        if ($request->has('search.value')) {
            $searchTerm = trim((string) $request->input('search.value'));

            if ($searchTerm !== '') {
                $model->where(function ($query) use ($searchTerm) {
                    IntelligentSearch::apply($query, $searchTerm, IntelligentSearch::applicantColumns());
                    $query->orWhereRaw('EXISTS (
                    SELECT 1 FROM module_notes mn
                    WHERE mn.module_noteable_id = applicants.id
                      AND mn.module_noteable_type = ?
                      AND mn.details LIKE ?
                )', [Applicant::class, '%' . $searchTerm . '%'])
                        ->orWhereRaw("EXISTS (
                    SELECT 1 FROM applicant_notes an
                    WHERE an.applicant_id = applicants.id
                      AND an.details LIKE ?
                )", ['%' . $searchTerm . '%']);
                });
            }
        }

        return IntelligentSearch::withoutDefaultFilter(DataTables::eloquent($model))
            ->addIndexColumn()
            ->addColumn('checkbox', function ($applicant) {
                return '<input type="checkbox" name="applicant_checkbox[]" class="applicant_checkbox" value="' . $applicant->id . '"/>';
            })
            ->addColumn('job_title', function ($applicant) {
                return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
            })
            ->addColumn('job_category', function ($sale) {
                $type  = $sale->job_type;
                $stype = $type === 'specialist'
                    ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                    : '';
                return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
            })
            ->addColumn('job_source', function ($applicant) {
                if (!$applicant->jobSource)
                    return '-';
                return '<span class="badge bg-light text-dark">' . e($applicant->jobSource->name) . '</span>';
            })
            ->addColumn('applicant_name', function ($applicant) {
                return $applicant->formatted_applicant_name;
            })
            ->addColumn('applicant_email', function ($applicant) {

                // Blocked applicant + no permission
                if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                    return "<span class='badge bg-dark'>Blocked</span>";
                }

                $email = $applicant->applicant_email_secondary
                    ? $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary
                    : $applicant->applicant_email;

                // Blocked applicant + has permission
                if ($applicant->is_blocked && Gate::allows('applicant-show-blocked-data')) {
                    return '<div class="bg-dark text-white p-1 rounded">' . $email . '</div>';
                }

                // Normal applicant
                return $email;
            })
            ->addColumn('applicant_experience', function ($applicant) {
                $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                $full  = e($applicant->applicant_experience);
                $id    = 'exp-' . $applicant->id;

                return '
                    <a href="javascript:void(0);" class="text-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#' . $id . '">
                        ' . $short . '
                    </a>
                    <div class="modal fade" id="' . $id . '" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Applicant Experience</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">' . nl2br($full) . '</div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>';
            })
            ->editColumn('applicant_postcode', function ($applicant) {
                $rawPostcode = trim($applicant->applicant_postcode);
                if (empty($rawPostcode))
                    return '<div class="text-center w-100">-</div>';

                $postcode = $applicant->formatted_postcode;
                $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($applicant->applicant_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                if ($applicant->lat != null && $applicant->lng != null && !$applicant->is_blocked) {
                    $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                    $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                    return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                } else {
                    return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                }
            })
            ->addColumn('applicantNotes', function ($applicant) use ($sale_id) {
                // ✅ Just use notes_details, fall back to applicant_notes field
                $notesDetails = $applicant->notes_details ?? $applicant->applicant_notes;
                $notes = HtmlNotes::toSafeHtml($notesDetails);

                $status_value = $this->getApplicantStatusForSale($applicant, $sale_id);

                if ($status_value == 'open') {
                    return '
                            <a href="javascript:void(0);" class="active_postcode" title="Add/Edit Note"
                            onclick="addShortNotesModal(' . (int) $applicant->id . ')">
                                ' . $notes . '
                            </a>
                        ';
                } else {
                    return $notes;
                }
            })
            ->filterColumn('applicantNotes', function ($query, $keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('applicants.applicant_notes', 'LIKE', "%{$keyword}%")
                        ->orWhereRaw('EXISTS (
                                SELECT 1 FROM module_notes mn
                                WHERE mn.module_noteable_id = applicants.id
                                AND mn.module_noteable_type = ?
                                AND mn.details LIKE ?
                            )', [Applicant::class, "%{$keyword}%"])
                        ->orWhereRaw('EXISTS (
                                SELECT 1 FROM applicant_notes an
                                WHERE an.applicant_id = applicants.id
                                AND an.details LIKE ?
                            )', ["%{$keyword}%"]);
                });
            })
            ->addColumn('applicantPhone', function ($applicant) {

                // Blocked + no permission -> always show blocked badge, nothing else matters
                if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                    return "<span class='badge bg-dark'>Blocked</span>";
                }

                $showBlockedData = $applicant->is_blocked
                    && Gate::allows('applicant-show-blocked-data');

                $class = $showBlockedData ? 'show_hidden_phone' : '';

                $parts = [];

                if (!empty($applicant->applicant_phone)) {
                    $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
                }

                if (!empty($applicant->applicant_phone_secondary)) {
                    $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
                }

                if (!empty($applicant->applicant_landline)) {
                    $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
                }

                $phones = implode('<br>', $parts) ?: '-';

                if ($showBlockedData) {
                    return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                        . $phones
                        . '</div>';
                }

                return $phones;
            })
            ->filterColumn('applicantPhone', function ($query, $keyword) {
                $clean = preg_replace('/[^0-9]/', '', $keyword);
                $query->where(function ($q) use ($clean) {
                    $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                });
            })
            ->addColumn('notes_created_at', function ($applicant) {
                return Carbon::parse($applicant->notes_created_at)->format('d M Y, h:i A');
            })
            ->addColumn('applicant_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->applicant_cv, $applicant->is_blocked, 'original', 'success', 'Download CV'))
            ->addColumn('crm_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->updated_cv, $applicant->is_blocked, 'updated', 'primary', 'Download Updated CV'))
            ->addColumn('paid_status', function ($applicant) use ($sale_id) {
                $status_value = 'open';
                $color_class  = 'bg-dark';

                if ($applicant->paid_status == 'close') {
                    $status_value = 'paid';
                    $color_class  = 'bg-primary';
                } else {
                    foreach ($applicant->cv_notes as $value) {
                        if ($value['sale_id'] == $sale_id) {
                            if ($value['status'] == 1) {
                                $status_value = 'sent';
                                $color_class  = 'bg-success';
                                break;
                            } elseif ($value['status'] == 0) {
                                $status_value = 'reject_job';
                                $color_class  = 'bg-danger';
                                break;
                            } elseif ($value['status'] == 2) {
                                $status_value = 'paid';
                                $color_class  = 'bg-primary';
                                break;
                            }
                        } elseif ($value['sale_id'] != $sale_id) {
                            if ($value['status'] == 1) {
                                $status_value = 'CRM Active';
                                $color_class  = 'bg-primary';
                            }
                        }
                    }
                }

                return '<span class="badge ' . $color_class . '">' . ucwords($status_value) . '</span>';
            })
            ->addColumn('action', function ($applicant) use ($sale_id, $sale, $sale_cv_counts) {
                $status_value = $this->getApplicantStatusForSale($applicant, $sale_id);

                $html = '<div class="btn-group dropstart">
                        <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                        </button>
                        <ul class="dropdown-menu">';

                if ($status_value == 'open') {
                    $html .= '<li><a href="javascript:void(0);" onclick="markNotInterestedModal(' . $applicant->id . ', ' . $sale_id . ')" class="dropdown-item">Mark Not Interested On Sale</a></li>
                              <li><a href="javascript:void(0);" class="dropdown-item" onclick="markNoNursingHomeModal(' . $applicant->id . ')">Mark No Nursing Home</a></li>';

                    if ($sale->is_on_hold != 0) {
                        $html .= '<li><a href="javascript:void(0)" class="dropdown-item"><span><small class="text-danger">(Sale On Hold)</small></span></a></li>';
                    } elseif ($sale_cv_counts >= $sale->cv_limit && $sale->is_on_hold == 0) {
                        $html .= '<li><a href="javascript:void(0)" class="dropdown-item"><span><small class="text-danger">(CV Limit Reached)</small></span></a></li>';
                    } else {
                        $html .= '<li>
                                <a href="javascript:void(0);" class="dropdown-item"
                                onclick="sendCVModal('
                            . (int) $applicant->id . ','
                            . (int) $sale_id . ','
                            . htmlspecialchars(json_encode($applicant->applicant_postcode), ENT_QUOTES, 'UTF-8') . ','
                            . (int) $applicant->have_nursing_home_experience . ')">
                                    <span>Send CV</span>
                                </a>
                            </li>';
                    }

                    $html .= '<li><a href="javascript:void(0);" class="dropdown-item" onclick="markApplicantCallbackModal(' . $applicant->id . ', ' . $sale_id . ')">Mark Callback</a></li>';
                } elseif ($status_value == 'sent' || $status_value == 'reject_job' || $status_value == 'paid') {
                    $html .= '<li><button type="button" class="btn btn-light btn-sm disabled d-inline-flex align-items-center">
                                    <iconify-icon icon="solar:lock-bold" class="fs-14 me-1"></iconify-icon> Locked
                                </button></li>';
                }

                $html .= '</ul></div>';
                return $html;
            })
            ->rawColumns([
                'checkbox',
                'applicant_postcode',
                'applicant_experience',
                'applicantNotes',
                'applicant_email',
                'applicantPhone',
                'job_title',
                'crm_resume',
                'applicant_resume',
                'paid_status',
                'job_category',
                'job_source',
                'action'
                // ✅ notes_created_at removed from rawColumns (plain formatted text, not HTML)
            ])
            ->with(['sale_id' => $sale_id])
            // Sorted above from the allowlist only. DataTables' own sort would also add the column
            // named in the request (an unknown one was a 500).
            ->order(function () {})
            ->make(true);
    }
    private function getApplicantStatusForSale($applicant, $sale_id): string
    {
        if ($applicant->paid_status == 'close') {
            return 'paid';
        }

        foreach ($applicant->cv_notes as $note) {
            if ($note['sale_id'] == $sale_id) {
                return match ((int) $note->status) {
                    1 => 'sent',
                    0 => 'reject_job',
                    2 => 'paid',
                    default => 'open',
                };
            }
        }

        return 'open';
    }
    public function storeSaleNotes(Request $request)
    {
        $v = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'details' => 'required|string|max:5000',
        ]);
        $user = Auth::user();
        $sale = Sale::findOrFail((int) $v['sale_id']);
        $sale_notes = $v['details'] . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        // One transaction: older notes are only switched off if the new one is saved.
        DB::transaction(function () use ($sale, $sale_notes, $user) {
            Sale::whereKey($sale->id)->update(['sale_notes' => $sale_notes]);   // as before: no model events
            $sale->refresh();                                                     // the audit records the new note

            SaleNote::where('sale_id', $sale->id)->update(['status' => 0]);
            $sale_note = SaleNote::create([
                'sale_id' => $sale->id,
                'sale_note' => $sale_notes,
                'user_id' => $user->id,
            ]);
            $sale_note->update(['sales_notes_uid' => md5($sale_note->id)]);

            (new ActionObserver())->customSaleAudit($sale, 'sale_notes');

            $this->replaceSaleModuleNote($sale->id, $sale_notes, $user->id, false);
        });

        return redirect()->to(url()->previous());
    }

    /** Switch off the sale's current module note and add the new one (the CRM's "latest note"). */
    private function replaceSaleModuleNote(int $saleId, string $details, int $userId, bool $withStatus = true): void
    {
        ModuleNote::where(['module_noteable_id' => $saleId, 'module_noteable_type' => 'Horsefly\Sale'])
            ->where('status', 1)->update(['status' => 0]);
        $moduleNote = ModuleNote::create(array_merge([
            'details' => $details,
            'module_noteable_id' => $saleId,
            'module_noteable_type' => 'Horsefly\Sale',
            'user_id' => $userId,
        ], $withStatus ? ['status' => 1] : []));
        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);
    }
    public function changeSaleStatus(Request $request)
    {
        $v = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'status' => 'required|in:0,1,2,3',          // closed, open, pending, rejected
            'details' => 'required|string|max:5000',
        ]);
        $user = Auth::user();
        $status = (int) $v['status'];
        $sale = Sale::findOrFail((int) $v['sale_id']);
        $sale_notes = $v['details'] . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');
        // Opening a sale makes it pending (2) until it is approved.
        $stored = $status === 1 ? 2 : $status;

        DB::transaction(function () use ($sale, $status, $stored, $sale_notes, $user) {
            $sale->update([
                'sale_notes' => $sale_notes,
                'status' => $stored,
                'is_on_hold' => false,
                'is_re_open' => false,
            ]);
            // The audit's message keeps the action asked for ("sale-opened" is the open date the
            // exports and the portal feed read); changes_made also records what was stored.
            (new ActionObserver())->changeSaleStatus($sale, ['status' => $status, 'stored_status' => $stored]);

            $this->replaceSaleModuleNote($sale->id, $sale_notes, $user->id);
        });

        return redirect()->to(url()->previous());
    }
    public function changeSaleHoldStatus(Request $request)
    {
        $v = $request->validate([
            'id' => 'required|integer|exists:sales,id',
            'status' => 'required|in:0,1,2',            // off hold, on hold, pending approval
            'details' => 'nullable|string|max:5000',
        ]);
        $user = Auth::user();
        $status = (int) $v['status'];
        $sale = Sale::findOrFail((int) $v['id']);
        $sale_notes = ($v['details'] ?? '') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        DB::transaction(function () use ($sale, $status, $sale_notes, $user, $v) {
            $sale->update(isset($v['details'])
                ? ['is_on_hold' => $status, 'sale_notes' => $sale_notes]
                : ['is_on_hold' => $status]);

            $this->replaceSaleModuleNote($sale->id, $sale_notes, $user->id, false);

            (new ActionObserver())->changeSaleOnHoldStatus($sale, ['status' => $status]);
        });

        return redirect()->to(url()->previous());
    }
    // SaleController.php
    public function updatePendingOnHoldStatus(Request $request)
    {
        $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'status' => 'required|in:0,1'
        ]);

        $sale = Sale::findOrFail($request->sale_id);
        if ((int) $sale->is_on_hold !== 2) {          // only a sale waiting for approval can be approved
            return response()->json(['success' => false,
                'message' => 'This sale is not waiting for on-hold approval.'], 409);
        }

        if ($request->status == 1) {
            $sale->is_on_hold = 1;
            $message = 'Sale marked as Approved';
            $newStatusText = 'Approved';
        } else {
            $sale->is_on_hold = 0;
            $message = 'Sale marked as Disapproved';
            $newStatusText = 'On Hold Removed';
        }

        $sale->save();

        return response()->json([
            'success' => true,
            'message' => $message,
            'new_status_text' => $newStatusText
        ]);
    }
    public function saleHistoryIndex(int $id)
    {
        $sale = Sale::withCount('active_cvs')->find($id);

        if (!$sale) {
            return redirect()->back()->with('error', 'Sale not found.');
        }
        $office = Office::where('id', $sale->office_id)->select('office_name')->first();
        $unit = Unit::where('id', $sale->unit_id)->select('unit_name')->first();
        $jobCategory = JobCategory::where('id', $sale->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $sale->job_title_id)->select('name')->first();
        $jobType = ucwords(str_replace('-', ' ', $sale->job_type));
        $jobType = $jobType == 'Specialist' ? ' (' . $jobType . ')' : '';
        $postcode = ucwords($sale->sale_postcode);
        $active_cvs_count = $sale->active_cvs_count;
        $cv_limit = $sale->cv_limit;

        $badgeColor = '';

        if ($cv_limit <= $active_cvs_count) {
            $badgeColor = 'bg-danger';
        } else {
            $badgeColor = 'bg-success';
        }

        return view('sales.history', compact('sale', 'office', 'unit', 'jobCategory', 'jobTitle', 'jobType', 'postcode', 'active_cvs_count', 'cv_limit', 'badgeColor'));
    }
    public function getOfficeUnits(Request $request)
    {
        $office = Office::find($request->input('office_id'));
        if (!$office || $office->is_blacklist) {
            return response()->json([]);
        }

        $units = Unit::where('office_id', $request->input('office_id'))
            ->where('is_blacklist', 0)
            // ->where('status', 1)
            ->whereNull('deleted_at')
            ->select('id', 'unit_name')
            ->get();

        return response()->json($units);
    }
    public function removeDocument(Request $request)
    {
        $documentId = $request->input('id');

        try {
            // Find the document
            $document = SaleDocument::findOrFail($documentId);

            $sale = Sale::findOrFail($document->sale_id);
            $audit = new ActionObserver();
            $audit->customSaleAudit($sale, 'document_removed');

            // Delete the file from the directory
            $filePath = storage_path('app/public/' . $document->document_path);
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete the document record from the database
            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document removed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing document: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while removing the document. Please try again.',
            ], 500);
        }
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        $filters = $this->exportFiltersFromRequest($request);

        if ($type == 'declined') {
            $filename = 'crm_declined_data_' . Carbon::now()->format('d-M-Y');
        } elseif ($type == 'not_attended') {
            $filename = 'crm_not_attended_data_' . Carbon::now()->format('d-M-Y');
        } elseif ($type == 'start_date_hold') {
            $filename = 'crm_start_date_hold_data_' . Carbon::now()->format('d-M-Y');
        } elseif ($type == 'dispute') {
            $filename = 'crm_disputed_data_' . Carbon::now()->format('d-M-Y');
        } elseif ($type == 'paid') {
            $filename = 'crm_paid_data_' . Carbon::now()->format('d-M-Y');
        } else {
            $filename = 'sales_' . $type;
        }

        return Excel::download(new SalesExport($type, $filters), $filename . ".csv");
    }
    protected function exportFiltersFromRequest(Request $request): array
    {
        $filters = [];
        foreach (
            [
                'status_filter',
                'type_filter',
                'category_filter',
                'source_filter',
                'title_filter',
                'office_filter',
                'user_filter',
                'cv_limit_filter',
                'date_range_filter',
                'date_flock_filter',
                'search',
            ] as $key
        ) {
            if ($request->exists($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        return $filters;
    }
    public function getSaleDocuments(Request $request)
    {
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'id' => 'required|integer',  // Assuming 'module_notes' is the table name and 'id' is the primary key
            ]);

            // Fetch the module notes by the given ID
            $document = SaleDocument::where('sale_id', $request->id)->latest()->get();

            // Check if the module note was found
            if (!$document) {
                return response()->json(['error' => 'Document not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $document,
                'success' => true
            ]);
        } catch (\Exception $e) {
            // If an error occurs, catch it and return a meaningful error message
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again later.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500); // Internal server error
        }
    }
    public function getSaleHistoryAjaxRequest(Request $request)
    {
        $sale_id = $request->sale_id;
        // Prepare CRM Notes query
        $model = Applicant::query()
            ->join(DB::raw('
                (
                    SELECT *
                    FROM crm_notes
                    WHERE id IN (
                        SELECT MAX(id)
                        FROM crm_notes
                        GROUP BY applicant_id, sale_id
                    )
                ) AS crm_notes
            '), 'crm_notes.applicant_id', '=', 'applicants.id')
            ->join('sales', 'sales.id', '=', 'crm_notes.sale_id')
            ->join('offices', 'offices.id', '=', 'sales.office_id')
            ->join('units', 'units.id', '=', 'sales.unit_id')
            ->join('history', function ($join) {
                $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                    ->on('crm_notes.sale_id', '=', 'history.sale_id');
            })
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.applicant_postcode',
                'applicants.applicant_phone',
                'applicants.applicant_landline',
                'applicants.job_category_id',
                'applicants.job_title_id',
                'applicants.job_type',
                'applicants.is_blocked',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'history.created_at',
                'history.stage',
                'history.sub_stage',
                'crm_notes.details as note_details',
                'crm_notes.created_at as notes_created_at',
            ])
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->with(['jobTitle', 'jobCategory'])
            ->where([
                'crm_notes.sale_id' => $sale_id,
                'history.status' => 1
            ]);

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            // Handle special cases first
            if ($orderColumn === 'job_category') {
                $model->orderBy('job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('history.created_at', 'desc');
            }
        } else {
            $model->orderBy('history.created_at', 'desc');
        }

        // Apply search filter BEFORE sending to DataTables
        if ($request->has('search.value')) {
            $searchTerm = $request->input('search.value');
            $model->where(function ($query) use ($searchTerm) {
                $query->where('history.sub_stage', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('history.stage', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('history.created_at', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('crm_notes.details', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%");

                // Relationship searches with explicit table names
                $query->orWhereHas('jobTitle', function ($q) use ($searchTerm) {
                    $q->where('job_titles.name', 'LIKE', "%{$searchTerm}%");
                });

                $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                    $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                });
            });
        }

        // Handle AJAX request
        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->addColumn('created_at', function ($row) {
                    return Carbon::parse($row->created_at)->format('d M Y, h:i A');
                })
                ->addColumn('job_title', function ($row) {
                    return $row->jobTitle ? strtoupper($row->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($row) {
                    $type = $row->job_type;
                    $stype = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $row->jobCategory ? $row->jobCategory->name . $stype : '-';
                })
                ->addColumn('stage', function ($row) {
                    return strtoupper($row->stage);
                })
                ->addColumn('sub_stage', function ($row) {
                    return '<span class="badge bg-primary">' . ucwords(str_replace('_', ' ', $row->sub_stage)) . '</span>';
                })
                ->editColumn('applicant_postcode', function ($row) {
                    $rawPostcode = trim($row->applicant_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $row->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($row->applicant_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                })
                ->addColumn('details', function ($row) {
                    $short = Str::limit(strip_tags($row->note_details), 100);
                    $full = e($row->note_details);
                    $id = 'exp-' . $row->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '<br><span class="font-weight:600;">Dated: ' .
                        Carbon::parse($row->created_at)->format('d M Y, h:i A') . '</span>
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('applicantPhone', function ($row) {

                    if ($row->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $row->is_blocked
                        && Gate::allows('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($row->applicant_phone)) {
                        $parts[] = DialLink::render($row->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($row->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($row->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($row->applicant_landline)) {
                        $parts[] = DialLink::render($row->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword);
                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->addColumn('action', function ($row) use ($sale_id) {
                    // Tooltip content with additional data-bs-placement and title
                    return '<a href="javascript:void(0);" title="View All Notes" onclick="viewNotesHistory(\'' . (int) $row->id . '\',\'' . (int) $sale_id . '\')">
                                <iconify-icon icon="solar:clipboard-text-bold" class="text-info fs-24"></iconify-icon>
                            </a>';
                })
                ->rawColumns(['details', 'job_category', 'applicant_postcode', 'applicantPhone', 'stage', 'job_title', 'action', 'sub_stage'])
                ->make(true);
        }
    }
}
