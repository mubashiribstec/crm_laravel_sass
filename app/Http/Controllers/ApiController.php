<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Horsefly\Sale;
use Horsefly\Region;

class ApiController extends Controller
{
    /**
     * JSON API for external portals: all open/active sales.
     *
     * Mirrors getOpenSales() membership + filters, but returns clean JSON (no DataTables /
     * HTML columns), paginated only when asked (?page, ?per_page). By default returns ALL open sales
     * (no "last 3 months" date flock) — pass date_flock_filter / date_range_filter
     * to narrow the window.
     *
     * GET /api/sales/open
     * Auth: Authorization: Bearer <PORTAL_API_TOKEN>
     */
    public function openSalesApi(Request $request)
    {
        $this->assertPortalApiToken($request);

        // $typeFilter       = (string) $request->input('type_filter', '');
        // $titleFilter      = $this->normalizeApiFilterArray($request->input('title_filter', []));
        // $categoryFilter   = $this->normalizeApiFilterArray($request->input('category_filter', []));
        // $searchTerm       = trim((string) $request->input('search', $request->input('search.value', '')));

        // $perPage = (int) $request->input('per_page', 5);
        // if ($perPage < 1) {
        //     $perPage = 5;
        // }
        // if ($perPage > 100) {
        //     $perPage = 5;
        // }

        $latestAuditSub = DB::table('audits')
            ->selectRaw('MAX(id) as id, auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereIn('message', ['open', 'sale-opened'])
            //   ->orWhere('message', 'LIKE', '%has been updated%')
            ->groupBy('auditable_id');

        $cvCountSub = DB::table('cv_notes')
            ->select('sale_id', DB::raw('COUNT(*) as cv_count'))
            ->where('status', 1)
            ->groupBy('sale_id');

        // Same core membership as getOpenSales(): active + not on hold.
        $query = Sale::query()
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
                DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv'),
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

        // Portal has no logged-in Gate user — always honour hide_private_data.
        // $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        // $hidePrivateData = array_filter(
        //     array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        // );

        // if (count($hidePrivateData) > 0) {
        //     $sourceIds = JobSource::where('is_active', 1)
        //         ->where(function ($q) use ($hidePrivateData) {
        //             foreach ($hidePrivateData as $hideName) {
        //                 $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
        //             }
        //         })
        //         ->pluck('id')
        //         ->toArray();

        //     if (count($sourceIds) > 0) {
        //         $query->where(function ($q) use ($sourceIds) {
        //             $q->whereNotIn('sales.job_source_id', $sourceIds)
        //                 ->orWhereNull('sales.job_source_id');
        //         });
        //     }
        // }

        // if ($searchTerm !== '') {
        //     $saleIds = Sale::search($searchTerm)->keys()->toArray();
        //     $query->where(function ($q) use ($searchTerm, $saleIds) {
        //         if (!empty($saleIds)) {
        //             $q->whereIn('sales.id', $saleIds);
        //         }
        //         $q->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('job_sources.name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('sales.sale_postcode', 'LIKE', "%{$searchTerm}%");
        //     });
        // }

        // if ($typeFilter !== '') {
        //     $query->where('sales.job_type', $typeFilter);
        // }
        // if ($officeFilter !== []) {
        //     $query->whereIn('sales.office_id', $officeFilter);
        // }
        // if ($categoryFilter !== []) {
        //     $query->whereIn('sales.job_category_id', $categoryFilter);
        // }
        // if ($titleFilter !== []) {
        //     $query->whereIn('sales.job_title_id', $titleFilter);
        // }

        $sortMap = [
            'id'            => 'sales.id',
            'sale_title'     => 'job_titles.name',
            'sale_category'  => 'job_categories.name',
            'postcode' => 'sales.sale_postcode',
            'office'   => 'offices.office_name',
            'location'     => 'units.unit_name',
        ];

        $sortBy = (string) $request->input('sort_by', 'created');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (isset($sortMap[$sortBy])) {
            $query->orderBy($sortMap[$sortBy], $sortDir);
        } else {
            // Open date when present, otherwise the sale's last update — newest first.
            $query->orderByRaw('COALESCE(audits.created_at, sales.updated_at) ' . $sortDir);
        }

        // $paginator = $query->paginate($perPage)->appends($request->query());

        // Load regions once; each sale is assigned the nearest region by Haversine
        // using sales.lat / sales.lng against regions.latitude / regions.longitude.
        $regions = Region::query()
            ->get(['id', 'name', 'districts_code', 'latitude', 'longitude', 'radius']);

        // Opt-in pagination (P-03): ?page= / ?per_page= (max 100). Without them the feed returns every
        // open sale, as the portal has always received.
        $paginator = $this->apiPaginator($request, $query);
        $data = ($paginator ? $paginator->getCollection() : $query->get())->map(function ($sale) use ($regions) {
            $region = $this->resolveSaleRegionByHaversine(
                $sale->lat !== null ? (float) $sale->lat : null,
                $sale->lng !== null ? (float) $sale->lng : null,
                $sale->sale_postcode,
                $regions
            );

            $createdAt = $this->resolveApiCreatedAt($sale);

            return [
                'sale_id' => (int) $sale->id,
                'office' => $sale->office_name ? ucwords($sale->office_name) : null,
                'unit' => $sale->unit_name ? ucwords($sale->unit_name) : null,
                'postcode' => $sale->sale_postcode,
                'region' => $region['name'] ?? null,
                'region_id' => $region['id'] ?? null,
                'region_distance_km' => $region['distance_km'] ?? null,
                'position_type' => $sale->position_type,
                'title' => $sale->job_title_name ? strtoupper($sale->job_title_name) : null,
                'category' => $sale->job_category_name ? ucwords($sale->job_category_name) : null,
                'salary' => $this->formatSalaryForApi($sale->salary),
                'timing' => $this->cleanSaleText($sale->timing),
                'experience' => $this->cleanSaleText($sale->experience),
                'qualification' => $this->cleanSaleText($sale->qualification),
                'benefits' => $this->cleanSaleText($sale->benefits),
                'status' => $sale->status == 1 ? 'active' : 'closed',
                'created' => $this->formatApiCreatedDate($createdAt),
                'ago' => $createdAt ? $this->formatCreatedAgo($createdAt) : null,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data] + $this->apiMeta($paginator));
    }

    public function openkingsburypersonnelSalesApi(Request $request)
    {
        $this->assertPortalApiToken($request);

        // $typeFilter       = (string) $request->input('type_filter', '');
        // $titleFilter      = $this->normalizeApiFilterArray($request->input('title_filter', []));
        // $categoryFilter   = $this->normalizeApiFilterArray($request->input('category_filter', []));
        // $searchTerm       = trim((string) $request->input('search', $request->input('search.value', '')));

        // $perPage = (int) $request->input('per_page', 5);
        // if ($perPage < 1) {
        //     $perPage = 5;
        // }
        // if ($perPage > 100) {
        //     $perPage = 5;
        // }

        $latestAuditSub = DB::table('audits')
            ->selectRaw('MAX(id) as id, auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereIn('message', ['open', 'sale-opened'])
            //   ->orWhere('message', 'LIKE', '%has been updated%')
            ->groupBy('auditable_id');

        $cvCountSub = DB::table('cv_notes')
            ->select('sale_id', DB::raw('COUNT(*) as cv_count'))
            ->where('status', 1)
            ->groupBy('sale_id');

        // Same core membership as getOpenSales(): active + not on hold.
        $query = Sale::query()
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
                DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv'),
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
            ->whereNotIn('sales.job_source_id', [10]) // Exclude sources: 1=Kingsbury Personnel, 2=Kingsbury Recruitment, 3=Kingsbury Group
            ->where('sales.is_on_hold', 0);

        // Portal has no logged-in Gate user — always honour hide_private_data.
        // $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        // $hidePrivateData = array_filter(
        //     array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        // );

        // if (count($hidePrivateData) > 0) {
        //     $sourceIds = JobSource::where('is_active', 1)
        //         ->where(function ($q) use ($hidePrivateData) {
        //             foreach ($hidePrivateData as $hideName) {
        //                 $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
        //             }
        //         })
        //         ->pluck('id')
        //         ->toArray();

        //     if (count($sourceIds) > 0) {
        //         $query->where(function ($q) use ($sourceIds) {
        //             $q->whereNotIn('sales.job_source_id', $sourceIds)
        //                 ->orWhereNull('sales.job_source_id');
        //         });
        //     }
        // }

        // if ($searchTerm !== '') {
        //     $saleIds = Sale::search($searchTerm)->keys()->toArray();
        //     $query->where(function ($q) use ($searchTerm, $saleIds) {
        //         if (!empty($saleIds)) {
        //             $q->whereIn('sales.id', $saleIds);
        //         }
        //         $q->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('job_sources.name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
        //             ->orWhere('sales.sale_postcode', 'LIKE', "%{$searchTerm}%");
        //     });
        // }

        // if ($typeFilter !== '') {
        //     $query->where('sales.job_type', $typeFilter);
        // }
        // if ($officeFilter !== []) {
        //     $query->whereIn('sales.office_id', $officeFilter);
        // }
        // if ($categoryFilter !== []) {
        //     $query->whereIn('sales.job_category_id', $categoryFilter);
        // }
        // if ($titleFilter !== []) {
        //     $query->whereIn('sales.job_title_id', $titleFilter);
        // }

        $sortMap = [
            'id'            => 'sales.id',
            'sale_title'     => 'job_titles.name',
            'sale_category'  => 'job_categories.name',
            'postcode' => 'sales.sale_postcode',
            'office'   => 'offices.office_name',
            'location'     => 'units.unit_name',
        ];

        $sortBy = (string) $request->input('sort_by', 'created');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (isset($sortMap[$sortBy])) {
            $query->orderBy($sortMap[$sortBy], $sortDir);
        } else {
            // Open date when present, otherwise the sale's last update — newest first.
            $query->orderByRaw('COALESCE(audits.created_at, sales.updated_at) ' . $sortDir);
        }

        // $paginator = $query->paginate($perPage)->appends($request->query());

        // Load regions once; each sale is assigned the nearest region by Haversine
        // using sales.lat / sales.lng against regions.latitude / regions.longitude.
        $regions = Region::query()
            ->get(['id', 'name', 'districts_code', 'latitude', 'longitude', 'radius']);

        // Opt-in pagination (P-03): ?page= / ?per_page= (max 100). Without them the feed returns every
        // open sale, as the portal has always received.
        $paginator = $this->apiPaginator($request, $query);
        $data = ($paginator ? $paginator->getCollection() : $query->get())->map(function ($sale) use ($regions) {
            $region = $this->resolveSaleRegionByHaversine(
                $sale->lat !== null ? (float) $sale->lat : null,
                $sale->lng !== null ? (float) $sale->lng : null,
                $sale->sale_postcode,
                $regions
            );

            $createdAt = $this->resolveApiCreatedAt($sale);

            return [
                'sale_id' => (int) $sale->id,
                'office' => $sale->office_name ? ucwords($sale->office_name) : null,
                'unit' => $sale->unit_name ? ucwords($sale->unit_name) : null,
                'postcode' => $sale->sale_postcode,
                'region' => $region['name'] ?? null,
                'region_id' => $region['id'] ?? null,
                'region_distance_km' => $region['distance_km'] ?? null,
                'position_type' => $sale->position_type,
                'title' => $sale->job_title_name ? strtoupper($sale->job_title_name) : null,
                'category' => $sale->job_category_name ? ucwords($sale->job_category_name) : null,
                'salary' => $this->formatSalaryForApi($sale->salary),
                'timing' => $this->cleanSaleText($sale->timing),
                'experience' => $this->cleanSaleText($sale->experience),
                'qualification' => $this->cleanSaleText($sale->qualification),
                'benefits' => $this->cleanSaleText($sale->benefits),
                'status' => $sale->status == 1 ? 'active' : 'closed',
                'created' => $this->formatApiCreatedDate($createdAt),
                'ago' => $createdAt ? $this->formatCreatedAgo($createdAt) : null,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data] + $this->apiMeta($paginator));
    }

    /**
     * Portal API: sale details by id (same field shape as openSalesApi).
     * Auth: Authorization: Bearer <PORTAL_API_TOKEN>
     */
    public function getSaleDetailsApi(Request $request, $id)
    {
        $this->assertPortalApiToken($request);

        $latestAuditSub = DB::table('audits')
            ->selectRaw('MAX(id) as id, auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereIn('message', ['open', 'sale-opened'])
            //   ->orWhere('message', 'LIKE', '%has been updated%')
            ->groupBy('auditable_id');

        $cvCountSub = DB::table('cv_notes')
            ->select('sale_id', DB::raw('COUNT(*) as cv_count'))
            ->where('status', 1)
            ->groupBy('sale_id');

        $sale = Sale::query()
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
                DB::raw('COALESCE(cv_counts.cv_count, 0) as no_of_sent_cv'),
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
            ->where('sales.id', $id)
            ->whereNull('sales.deleted_at')
            ->first();

        if (!$sale) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found',
            ], 404);
        }

        $regions = Region::query()
            ->get(['id', 'name', 'districts_code', 'latitude', 'longitude', 'radius']);

        $region = $this->resolveSaleRegionByHaversine(
            $sale->lat !== null ? (float) $sale->lat : null,
            $sale->lng !== null ? (float) $sale->lng : null,
            $sale->sale_postcode,
            $regions
        );

        $createdAt = $this->resolveApiCreatedAt($sale);

        return response()->json([
            'success' => true,
            'data' => [
                'sale_id' => (int) $sale->id,
                'office' => $sale->office_name ? ucwords($sale->office_name) : null,
                'unit' => $sale->unit_name ? ucwords($sale->unit_name) : null,
                'postcode' => $sale->sale_postcode,
                'region' => $region['name'] ?? null,
                'region_id' => $region['id'] ?? null,
                'region_distance_km' => $region['distance_km'] ?? null,
                'position_type' => $sale->position_type,
                'title' => $sale->job_title_name ? strtoupper($sale->job_title_name) : null,
                'category' => $sale->job_category_name ? ucwords($sale->job_category_name) : null,
                'salary' => $this->formatSalaryForApi($sale->salary),
                'timing' => $this->cleanSaleText($sale->timing),
                'experience' => $this->cleanSaleText($sale->experience),
                'qualification' => $this->cleanSaleText($sale->qualification),
                'benefits' => $this->cleanSaleText($sale->benefits),
                'notes' => $this->cleanSaleText($sale->sale_notes),
                'status' => (int) $sale->status === 1 ? 'active' : 'closed',
                'created' => $this->formatApiCreatedDate($createdAt),
                'ago' => $createdAt ? $this->formatCreatedAgo($createdAt) : null,
            ],
        ]);
    }

    /**
     * Portal API: active regions (usable for geo matching) with display names.
     * Auth: Authorization: Bearer <PORTAL_API_TOKEN>
     */
    public function activeRegionsApi(Request $request)
    {
        $this->assertPortalApiToken($request);

        // Active = has lat/lng for Haversine matching (excludes e.g. "Common Regions").
        $data = Region::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('name')
            ->get(['id', 'name', 'latitude', 'longitude', 'radius', 'districts_code'])
            ->map(function ($region) {
                return [
                    'id' => (int) $region->id,
                    'name' => ucwords(trim((string) $region->name)),
                    'latitude' => $region->latitude !== null ? (float) $region->latitude : null,
                    'longitude' => $region->longitude !== null ? (float) $region->longitude : null,
                    'radius' => $region->radius !== null ? (float) $region->radius : null,
                    'districts_code' => $region->districts_code,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Assign a region to a sale using Haversine distance on lat/lng.
     * Prefer the nearest region within its radius; if none match, fall back to
     * postcode districts_code matching (same idea as RegionController).
     *
     * @param  \Illuminate\Support\Collection<int, \Horsefly\Region>  $regions
     * @return array{id:int,name:string,distance_km:float|null}|null
     */
    private function resolveSaleRegionByHaversine(?float $lat, ?float $lng, ?string $postcode, $regions): ?array
    {
        $best = null;

        if ($lat !== null && $lng !== null && $regions->isNotEmpty()) {
            foreach ($regions as $region) {
                if ($region->latitude === null || $region->longitude === null) {
                    continue;
                }

                $regionLat = (float) $region->latitude;
                $regionLng = (float) $region->longitude;
                $distance = $this->haversineDistanceKm($lat, $lng, $regionLat, $regionLng);

                $radius = $region->radius !== null ? (float) $region->radius : null;
                if ($radius !== null && $distance > $radius) {
                    continue;
                }

                if ($best === null || $distance < $best['distance_km']) {
                    $best = [
                        'id' => (int) $region->id,
                        'name' => ucwords((string) $region->name),
                        'distance_km' => round($distance, 2),
                    ];
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        // Fallback when coordinates are missing / outside every radius.
        return $this->resolveSaleRegionByPostcodeDistrict($postcode, $regions);
    }

    /**
     * Fallback: match sale postcode district against regions.districts_code (AB|EH|...).
     *
     * @param  \Illuminate\Support\Collection<int, \Horsefly\Region>  $regions
     * @return array{id:int,name:string,distance_km:null}|null
     */
    private function resolveSaleRegionByPostcodeDistrict(?string $postcode, $regions): ?array
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $postcode));
        if ($normalized === '' || $regions->isEmpty()) {
            return null;
        }

        foreach ($regions as $region) {
            $codes = array_filter(array_map('trim', explode('|', (string) $region->districts_code)));
            if ($codes === []) {
                continue;
            }

            // Same pattern used by RegionController: district then a digit.
            $regex = '/^(' . implode('|', array_map(static function ($code) {
                return preg_quote(strtoupper($code), '/');
            }, $codes)) . ')\d/';

            if (preg_match($regex, $normalized)) {
                return [
                    'id' => (int) $region->id,
                    'name' => ucwords((string) $region->name),
                    'distance_km' => null,
                ];
            }
        }

        return null;
    }

    /** Great-circle distance in kilometres (Haversine). */
    private function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1, sqrt($a)));
    }

    /**
     * Pull salary figure(s) from free-text and format with UK pound (£).
     * Examples: "£18 - £23 per hour..." → "£18 - £23"; "36-45k DOE" → "£36k - £45k".
     */
    private function formatSalaryForApi($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return null;
        }

        // Normalise dashes / spaces so ranges parse consistently.
        $text = str_replace(["\xC2\xA0", '–', '—', '−'], [' ', '-', '-', '-'], $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        preg_match_all(
            '/(?P<pound>£\s*)?(?P<amount>\d{1,3}(?:,\d{3})+|\d+)(?:\.(?P<decimals>\d{1,2}))?\s*(?P<k>[kK])?/u',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            return null;
        }

        $figures = [];
        $rangeHasK = false;

        foreach ($matches as $match) {
            $amount = (int) str_replace(',', '', $match['amount']);
            $decimals = isset($match['decimals']) && $match['decimals'] !== '' ? $match['decimals'] : null;
            $hasPound = isset($match['pound']) && trim((string) $match['pound']) !== '';
            $hasK = isset($match['k']) && $match['k'] !== '';
            $hasComma = str_contains($match['amount'], ',');

            // Drop experience-like tiny whole numbers (e.g. "1 years") with no money marker.
            if (!$hasPound && !$hasK && !$hasComma && $decimals === null && $amount < 10) {
                continue;
            }

            if ($hasK) {
                $rangeHasK = true;
            }

            $figures[] = [
                'amount' => $amount,
                'decimals' => $decimals,
                'k' => $hasK,
                'comma' => $hasComma,
            ];
        }

        if ($figures === []) {
            return null;
        }

        // "36-45k" → treat bare sibling values as thousands too.
        if ($rangeHasK) {
            foreach ($figures as &$figure) {
                if (
                    !$figure['k']
                    && $figure['decimals'] === null
                    && !$figure['comma']
                    && $figure['amount'] < 1000
                ) {
                    $figure['k'] = true;
                }
            }
            unset($figure);
        }

        $formatted = [];
        foreach ($figures as $figure) {
            $label = $this->formatSalaryFigure($figure);
            if (!in_array($label, $formatted, true)) {
                $formatted[] = $label;
            }
        }

        if ($formatted === []) {
            return null;
        }

        $salary = count($formatted) === 1
            ? $formatted[0]
            : $formatted[0] . ' - ' . $formatted[count($formatted) - 1];

        $period = $this->detectSalaryPeriod($text, $figures);
        if ($period !== null) {
            $salary .= ' ' . $period;
        }

        return $salary;
    }

    /**
     * Detect pay period from free-text (and figure shape as fallback).
     * Returns short labels: p/a (per annum), p/h (per hour), p/d (per day).
     *
     * @param  list<array{amount:int,decimals:?string,k:bool,comma:bool}>  $figures
     */
    private function detectSalaryPeriod(string $text, array $figures): ?string
    {
        $lower = strtolower($text);

        // Hourly first — some bad data says "/ Hour" on annual figures; still honour explicit hour wording.
        if (
            preg_match('/\b(?:per\s*hour|p\/h|ph|p\.h\.?|hourly)\b/i', $lower)
            || preg_match('/\bhour\b/i', $lower)
        ) {
            // Prefer annual if text clearly says year/annum AND amounts look annual (k / thousands).
            $looksAnnual = (bool) preg_match('/\b(?:per\s*annum|per\s*year|a\s*year|p\.?\s*a\.?|p\/a|annum|annual)\b/i', $lower);
            $hasAnnualFigure = false;
            foreach ($figures as $figure) {
                if ($figure['k'] || $figure['comma'] || $figure['amount'] >= 1000) {
                    $hasAnnualFigure = true;
                    break;
                }
            }
            if (!($looksAnnual && $hasAnnualFigure)) {
                return 'p/h';
            }
        }

        if (preg_match('/\b(?:per\s*annum|per\s*year|a\s*year|p\.?\s*a\.?|p\/a|annum|annual)\b/i', $lower)) {
            return 'p/a';
        }

        if (preg_match('/\b(?:per\s*day|p\/d|daily)\b/i', $lower)) {
            return 'p/d';
        }

        // Fallback from figure shape when wording is missing.
        foreach ($figures as $figure) {
            if ($figure['k'] || $figure['comma'] || $figure['amount'] >= 1000) {
                return 'p/a';
            }
        }

        foreach ($figures as $figure) {
            if ($figure['decimals'] !== null || ($figure['amount'] > 0 && $figure['amount'] < 100)) {
                return 'p/h';
            }
        }

        return null;
    }

    /**
     * @param  array{amount:int,decimals:?string,k:bool,comma:bool}  $figure
     */
    private function formatSalaryFigure(array $figure): string
    {
        if ($figure['k']) {
            return '£' . $figure['amount'] . 'k';
        }

        if ($figure['decimals'] !== null) {
            $value = (float) ($figure['amount'] . '.' . $figure['decimals']);
            $precision = strlen($figure['decimals']);

            return '£' . number_format($value, $precision, '.', ',');
        }

        if ($figure['comma'] || $figure['amount'] >= 1000) {
            return '£' . number_format($figure['amount']);
        }

        return '£' . $figure['amount'];
    }

    /**
     * Created date for the portal API: latest open audit, else the sale's updated_at.
     */
    private function resolveApiCreatedAt($sale): ?Carbon
    {
        $value = $sale->open_date ?: $sale->updated_at;
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    /**
     * Calendar date for the API.
     * Show Y-m-d only when the created date is within the last 3 weeks;
     * otherwise null.
     */
    private function formatApiCreatedDate(?Carbon $date): ?string
    {
        if ($date === null || $date->lt(Carbon::now()->subWeeks(3))) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * Relative age of the listing vs now, e.g. "recent", "2 hours ago".
     */
    private function formatCreatedAgo(Carbon $date): string
    {
        if ($date->greaterThanOrEqualTo(Carbon::now()->subHour())) {
            return 'recent';
        }

        return $date->diffForHumans();
    }

    private function cleanSaleText($value)
    {
        if (empty($value)) {
            return $value;
        }

        // Decode HTML entities
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove URLs
        $value = preg_replace(
            '/\b(?:https?:\/\/|www\.)[^\s<]+/iu',
            '',
            $value
        );

        // Convert common HTML block elements to paragraph breaks
        $value = preg_replace(
            '/<\/?(p|div|section|article|h[1-6]|li|ul|ol|blockquote)[^>]*>/iu',
            "\n\n",
            $value
        );

        // Convert <br> to line break
        $value = preg_replace('/<br\s*\/?>/iu', "\n", $value);

        // Remove all remaining HTML tags
        $value = strip_tags($value);

        // Remove unwanted symbols but keep:
        // - letters
        // - numbers
        // - spaces
        // - punctuation
        // - &
        // - currency symbols
        $value = preg_replace(
            '/[^\p{L}\p{N}\p{P}\p{Sc}\p{Z}\r\n&]/u',
            '',
            $value
        );

        // Remove common decorative/bullet symbols
        $value = preg_replace(
            '/[•●▪◦■□◆◇►▸➜➤★☆✓✔✦✧→←↑↓]/u',
            '',
            $value
        );

        // Normalize spaces on individual lines
        $lines = preg_split('/\R/u', $value);

        $cleanLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '') {
                $line = preg_replace('/[ \t]+/u', ' ', $line);
                $cleanLines[] = $line;
            } elseif (!empty($cleanLines) && end($cleanLines) !== '') {
                $cleanLines[] = '';
            }
        }

        // Remove more than one empty line
        $value = implode("\n", $cleanLines);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);

        return trim($value);
    }
    /**
     * Validate shared portal API token (Bearer / X-API-Token / ?api_token=).
     */
    private function assertPortalApiToken(Request $request): void
    {
        $expected = config('services.portal.token');
        $provided = $request->bearerToken()
            ?: $request->header('X-API-Token')
            ?: $request->query('api_token');

        if (empty($expected) || !is_string($provided) || !hash_equals((string) $expected, (string) $provided)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid or missing portal API token.',
            ], 401));
        }
    }
    /**
     * Accept filter values as array, CSV string, or scalar.
     *
     * @param  mixed  $value
     * @return list<int|string>
     */
    private function normalizeApiFilterArray($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }

        return array_values(array_filter((array) $value, fn($v) => $v !== '' && $v !== null));
    }

    /** A paginator when the caller asked for pages (?page / ?per_page, 1–100), else null (the whole list). */
    private function apiPaginator(Request $request, $query): ?\Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        if (!$request->hasAny(['page', 'per_page'])) {
            return null;
        }
        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

        return $query->paginate($perPage)->appends($request->query());
    }

    /** The "meta" block for a paginated reply (none for the whole list). */
    private function apiMeta(?\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return $paginator ? ['meta' => [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]] : [];
    }
}
