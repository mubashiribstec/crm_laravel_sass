<?php

namespace App\Exports;

use Horsefly\Applicant;
use Horsefly\Sale;
use Horsefly\Region;

use App\Traits\HasDistanceCalculation;
use App\Exports\Concerns\HidesPrivateExportData;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;


class RegionApplicantsExport implements FromCollection, WithHeadings
{
    use HasDistanceCalculation; // NOTE: currently unused below — the distance SQL is inlined
    // because the trait's API wasn't available to reference here.
    // If the trait already exposes an equivalent helper, prefer
    // calling that instead of applyDistanceFilter() below.
    use HidesPrivateExportData;

    protected $type;
    protected $radius;
    protected $model_type;
    protected $model_id;
    protected $region_filter;
    protected $type_filter;
    protected $category_filter;
    protected $title_filter;
    protected $search;

    public function __construct(
        string $type = 'all',
        ?float $radius = null,
        ?string $model_type = null,
        ?int $model_id = null,
        ?array $region_filter = null,
        ?string $type_filter = null,
        ?array $category_filter = null,
        ?array $title_filter = null,
        ?string $search = null
    ) {
        $this->type = $type;
        $this->radius = $radius;
        $this->model_type = $model_type;
        $this->model_id = $model_id;
        $this->region_filter = $region_filter;
        $this->type_filter = $type_filter;
        $this->category_filter = $category_filter;
        $this->title_filter = $title_filter;
        $this->search = $search;
    }

    public function collection()
    {
        // Applicants around a private sale are not exported (users without show-private-data).
        if (strtolower((string) $this->model_type) === 'sale' && $this->exportIsPrivateSale($this->model_id)) {
            return collect();
        }

        switch ($this->type) {
            case 'emails':
                return $this->baseQuery()
                    ->addSelect([
                        'applicants.id',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'job_categories.name as job_category',
                        'applicants.job_type',
                        'job_titles.name as job_title',
                        'applicants.created_at',
                    ])
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at
                                ? $item->created_at->format('d M Y, h:i A')
                                : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name ?? '')),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'job_category' => strtoupper($item->job_category ?? ''),
                            'job_type' => strtoupper($item->job_type ?? ''),
                            'job_title' => strtoupper($item->job_title ?? ''),
                        ];
                    });

            case 'noLatLong':
                return $this->baseQuery(false) // false = skip the distance/HAVING filter
                    ->addSelect(
                        'applicants.id',
                        'applicants.applicant_name',
                        'applicants.applicant_postcode',
                        'applicants.lat',
                        'applicants.lng',
                        'job_categories.name as job_category',
                        'applicants.job_type',
                        'job_titles.name as job_title',
                        'applicants.created_at'
                    )
                    ->where(function ($q) {
                        $q->whereIn('applicants.lat', ['0', ''])
                            ->orWhereNull('applicants.lat');
                    })
                    ->where(function ($q) {
                        $q->whereIn('applicants.lng', ['0', ''])
                            ->orWhereNull('applicants.lng');
                    })
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name ?? '')),
                            'applicant_postcode' => strtoupper($item->applicant_postcode ?? ''),
                            'lat' => $item->lat,
                            'lng' => $item->lng,
                            'job_category' => strtoupper($item->job_category ?? ''),
                            'job_type' => strtoupper($item->job_type ?? ''),
                            'job_title' => strtoupper($item->job_title ?? ''),
                        ];
                    });

            case 'all':
                return $this->baseQuery()
                    ->addSelect([
                        'applicants.id',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_phone',
                        'applicants.applicant_phone_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_landline',
                        'job_categories.name as job_category',
                        'applicants.job_type',
                        'job_titles.name as job_title',
                        'applicants.created_at',
                    ])
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name ?? '')),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'applicant_postcode' => strtoupper($item->applicant_postcode ?? ''),
                            'applicant_phone' => $item->applicant_phone,
                            'applicant_phone_secondary' => $item->applicant_phone_secondary,
                            'applicant_landline' => $item->applicant_landline,
                            'job_category' => strtoupper($item->job_category ?? ''),
                            'job_type' => strtoupper($item->job_type ?? ''),
                            'job_title' => strtoupper($item->job_title ?? ''),
                        ];
                    });

            default:
                return collect(); // Return empty collection instead of null
        }
    }

    /**
     * Shared base query for the 'emails' and 'all' export types.
     * Deduplicates the filter logic that was previously copy-pasted in both branches.
     */
    private function baseQuery(): Builder
    {
        $query = Applicant::query()
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->where('applicants.status', 1)
            ->where('applicants.is_blocked', 0)
            ->whereNull('applicants.deleted_at');

        // Applicants with a hidden job source are not exported (users without show-private-data).
        $this->exportExcludeSourceColumn($query, 'applicants.job_source_id');

        if (!empty($this->category_filter)) {
            $query->whereIn('applicants.job_category_id', (array) $this->category_filter);
        }

        if (!empty($this->title_filter)) {
            $query->whereIn('applicants.job_title_id', (array) $this->title_filter);
        }

        if (!empty($this->type_filter) && strtolower($this->type_filter) !== 'all types') {
            $query->where('applicants.job_type', $this->type_filter);
        }

        if (!empty($this->region_filter)) {
            $districtCodes = Region::whereIn('id', $this->region_filter)
                ->pluck('districts_code')
                ->filter()
                ->toArray();
        } else {

            $firstRegion = Region::first();

            $districtCodes = $firstRegion && $firstRegion->districts_code
                ? [$firstRegion->districts_code]
                : [];
        }

        $districtRegex = implode('|', $districtCodes);
        $query->whereRaw(
            "UPPER(TRIM(applicants.applicant_postcode)) REGEXP '^($districtRegex)[0-9]'"
        );

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('applicants.applicant_name', 'LIKE', '%' . $this->search . '%')
                    ->orWhere('applicants.applicant_email', 'LIKE', '%' . $this->search . '%')
                    ->orWhere('applicants.applicant_email_secondary', 'LIKE', '%' . $this->search . '%')
                    ->orWhere('job_titles.name', 'LIKE', '%' . $this->search . '%')
                    ->orWhere('job_categories.name', 'LIKE', '%' . $this->search . '%');
            });
        }

        $this->applyDistanceFilter($query);

        return $query;
    }

    /**
     * FIX: previously this always used Region::find($this->region_filter) — which
     * returns a Collection (not a single model) when region_filter is an array,
     * breaking ->latitude / ->longitude access. It also completely ignored
     * $this->radius and $this->model_type/$this->model_id, even though the
     * controller clearly supports a "within Xkm of a specific model" flow
     * (see the "applicants_within_{radius}km_of_sale_..." filename).
     *
     * Priority order now is:
     *   1. If a model_type/model_id is given (e.g. a Sale), use that model's
     *      location + the explicit $radius.
     *   2. Otherwise, fall back to the first selected region's location + the
     *      explicit $radius (or the region's own default radius).
     *
     * ASSUMPTION: Sale (and any other supported model_type) exposes `lat`/`lng`
     * columns. Confirm and adjust the column names if that's not correct.
     */
    private function applyDistanceFilter(Builder $query): void
    {
        $latitude = null;
        $longitude = null;
        $radius = $this->radius;

        if (!empty($this->model_type) && !empty($this->model_id)) {
            $model = $this->resolveDistanceModel();

            if ($model && $model->lat && $model->lng) {
                $latitude = $model->lat;
                $longitude = $model->lng;
            }
        } elseif (!empty($this->region_filter)) {
            // Multiple regions can be selected for the general region_id filter above,
            // but distance-from-a-point only makes sense against a single origin —
            // we use the first selected region as that origin.
            $region = Region::whereIn('id', (array) $this->region_filter)->first();

            if ($region) {
                $latitude = $region->latitude;
                $longitude = $region->longitude;
                $radius = $radius ?? $region->radius ?? 10;
            }
        }

        if ($latitude === null || $longitude === null) {
            return;
        }

        $radius = $radius ?? 10;

        $query->selectRaw("
            (
                6371 * acos(LEAST(1, GREATEST(-1, 
                    cos(radians(?))
                    * cos(radians(applicants.lat))
                    * cos(radians(applicants.lng) - radians(?))
                    + sin(radians(?))
                    * sin(radians(applicants.lat))
                )))
            ) AS distance
        ", [$latitude, $longitude, $latitude]);

        $query->having('distance', '<=', $radius);
    }

    private function resolveDistanceModel()
    {
        // Extend this map as more model_type values become supported.
        return match (strtolower((string) $this->model_type)) {
            'sale' => Sale::find($this->model_id),
            default => null,
        };
    }

    public function headings(): array
    {
        switch ($this->type) {
            case 'emails':
                return ['Created At', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Job Category', 'Job Type', 'Job Title'];
            case 'noLatLong':
                return ['Created At', 'Applicant Name', 'Postcode', 'Latitude', 'Longitude', 'Job Category', 'Job Type', 'Job Title'];
            case 'all':
                return ['Created At', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone (Primary)', 'Phone (Secondary)', 'Landline', 'Job Category', 'Job Type', 'Job Title'];
            default:
                return [];
        }
    }
}
