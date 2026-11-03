<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Horsefly\Applicant;
use Horsefly\JobTitle;
use Horsefly\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Event;
class UpdateAvailableJobStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-available-job-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update available job status for applicants based on their sales radius';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $applicants = Applicant::select(
                'id',
                'job_title_id',
                'applicant_postcode',
                'lat as app_latitude',
                'lng as app_longitude',
                'is_job_within_radius'
            )
            ->where('status', 1)
            ->where('is_blocked', 0)
            ->get();

        $radius = 24.14; // km = 15 miles

        foreach ($applicants as $applicant) {
            $data = [
                'job_title_id'        => $applicant->job_title_id,
                'applicant_postcode'  => $applicant->applicant_postcode,
                'is_job_within_radius'=> $applicant->is_job_within_radius,
                'lat'                 => $applicant->app_latitude,
                'lng'                 => $applicant->app_longitude,
            ];

            $isNearSales = $this->checkNearbySales($data, $radius);
            // Log::info('Applicant ID: ' . $applicant->id . ' - Is near sales: ' . ($isNearSales ? 'Yes' : 'No'));
            DB::table('applicants')
                ->where('id', $applicant->id)
                ->update([
                    'is_job_within_radius' => $isNearSales ? 1 : 0,
                    'updated_at' => DB::raw('updated_at') // keep old timestamp
                ]);

        }

        $this->info('Applicant job statuses updated successfully.');
    }
    private function checkNearbySales(array $data, int $radiusKm)
    {
        $lat = $data['lat'];
        $lon = $data['lng'];

        $jobTitle = JobTitle::find($data['job_title_id']);

        if (!$jobTitle) {
            return false;
        }

        $relatedTitles = is_array($jobTitle->related_titles)
            ? $jobTitle->related_titles
            : json_decode($jobTitle->related_titles ?? '[]', true);

        $titles = collect($relatedTitles)
            ->map(fn($item) => strtolower(trim($item)))
            ->push(strtolower(trim($jobTitle->name)))
            ->unique()
            ->values()
            ->toArray();

        $jobTitleIds = JobTitle::whereIn(DB::raw('LOWER(name)'), $titles)
            ->pluck('id')
            ->toArray();

        $location_distance = Sale::selectRaw("
                *,
                (ACOS(LEAST(1, GREATEST(-1, 
                    SIN(? * PI() / 180) * SIN(lat * PI() / 180) +
                    COS(? * PI() / 180) * COS(lat * PI() / 180) *
                    COS((? - lng) * PI() / 180)
                ))) * 180 / PI()) * 111.045 AS distance
            ", [$lat, $lat, $lon])
            ->where("sales.status", 1)
            ->where("sales.is_on_hold", 0)
            ->whereIn("sales.job_title_id", $jobTitleIds)
            ->havingRaw("distance < ?", [$radiusKm])
            ->get();

        return $location_distance->isNotEmpty();
    }

}
