<?php

namespace App\Services\Xplogent;

use App\Traits\FiltersRadiusApplicants;
use Horsefly\Applicant;
use Horsefly\JobTitle;
use Horsefly\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who matches a vacancy by radius: the sale's job title plus its related
 * titles, active applicants with coordinates, not in a nursing home, within
 * $radiusKm (haversine), filtered by FiltersRadiusApplicants (by default: the
 * sale's job category), with the latest note joined.
 *
 * Extracted from SaleController::getApplicantsBySaleRadius, which builds its
 * DataTables screen on top of this query, so the screen and the Xplogent
 * campaign queue always agree on the candidates.
 */
class CandidateQueueService
{
    use FiltersRadiusApplicants;

    /** The haversine distance in km from (?, ?) to the applicant; bind [lat, lng, lat]. */
    public const DISTANCE_SQL = '(6371 * acos(LEAST(1, GREATEST(-1, cos(radians(?)) * cos(radians(applicants.lat)) * cos(radians(applicants.lng) - radians(?)) + sin(radians(?)) * sin(radians(applicants.lat))))))';

    /** job_title ids an applicant may have: the sale's own title plus its related_titles. */
    public function titleIds(Sale $sale): array
    {
        $sale->loadMissing('jobTitle');
        $titleIds = collect([$sale->job_title_id]);

        if ($sale->jobTitle && !empty($sale->jobTitle->related_titles)) {
            $raw = $sale->jobTitle->related_titles;
            // related_titles is a JSON array or a comma-separated string of names
            if (is_array($raw)) {
                $relatedNames = $raw;
            } else {
                $decoded = json_decode($raw, true);
                $relatedNames = is_array($decoded)
                    ? $decoded
                    : array_filter(array_map('trim', explode(',', $raw)));
            }
            if (!empty($relatedNames)) {
                $titleIds = $titleIds->merge(JobTitle::whereIn('name', $relatedNames)->pluck('id'));
            }
        }

        return $titleIds->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * The radius query (not executed). $filters are the screen's list filters
     * (status_filter, cv_status_filter, source_filter, title_filter); the sale's
     * job category is always passed as category_id. $columns: the applicant
     * columns to select (the screen uses applicants.*; the API selects fewer).
     */
    public function radiusQuery(Sale $sale, float $radiusKm, array $filters = [], array $columns = ['applicants.*']): Builder
    {
        $lat = (float) $sale->lat;
        $lng = (float) $sale->lng;

        $latestModuleNoteSub = DB::table('module_notes')
            ->select('module_noteable_id', 'details', 'created_at')
            ->where('module_noteable_type', Applicant::class)
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('module_notes')
                    ->where('module_noteable_type', Applicant::class)
                    ->groupBy('module_noteable_id');
            });

        $latestApplicantNoteSub = DB::table('applicant_notes')
            ->select('applicant_id', 'details', 'created_at')
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('applicant_notes')
                    ->groupBy('applicant_id');
            });

        $model = Applicant::query()
            ->select([
                ...$columns,
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                DB::raw('COALESCE(latest_module_notes.details, latest_applicant_notes.details) AS notes_details'),
                DB::raw('COALESCE(latest_module_notes.created_at, latest_applicant_notes.created_at, applicants.updated_at) AS notes_created_at'),
            ])
            ->leftJoinSub($latestModuleNoteSub, 'latest_module_notes', function ($join) {
                $join->on('applicants.id', '=', 'latest_module_notes.module_noteable_id');
            })
            ->leftJoinSub($latestApplicantNoteSub, 'latest_applicant_notes', function ($join) {
                $join->on('applicants.id', '=', 'latest_applicant_notes.applicant_id');
            })
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->where('applicants.is_in_nurse_home', 0)
            ->whereNotNull('applicants.lat')
            ->whereNotNull('applicants.lng')
            ->whereIn('applicants.job_title_id', $this->titleIds($sale)); // sale's title + related titles
        // Box first (index-friendly), then the exact distance (P-01).
        $this->applyRadiusBoundingBox($model, $lat, $lng, $radiusKm)
            ->whereRaw(self::DISTANCE_SQL . ' <= ?', [$lat, $lng, $lat, $radiusKm]);

        $this->applyRadiusApplicantFilters($model, (int) $sale->id,
            ['category_id' => $sale->job_category_id] + $filters, false);

        return $model;
    }

    /** Adds the distance in km as `distance_km` (the same formula as the radius rule). */
    public function withDistance(Builder $query, Sale $sale): Builder
    {
        $lat = (float) $sale->lat;
        $lng = (float) $sale->lng;

        return $query->selectRaw(self::DISTANCE_SQL . ' AS distance_km', [$lat, $lng, $lat]);
    }
}
