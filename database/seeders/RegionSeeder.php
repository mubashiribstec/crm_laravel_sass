<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * UK regions for a fresh install (audit S-05): the region screens and exports match applicants
 * by districts_code (postcode areas, used as ^(AB|DD|...)[0-9]) and the portal feed by the
 * centroid. Only an EMPTY regions table is seeded: an existing install keeps its own regions.
 *
 * The postcode-area split is approximate (some areas cross region boundaries, e.g. SY, EN, KT);
 * adjust it in the CRM's Regions settings if needed. The names and centroids match the
 * 2026_08_11 backfill migration, plus Yorkshire and the Humber, which that list lacks.
 */
class RegionSeeder extends Seeder
{
    public const REGIONS = [
        // name => [postcode areas, latitude, longitude, radius km]
        'Scotland' => ['AB|DD|DG|EH|FK|G|HS|IV|KA|KW|KY|ML|PA|PH|TD|ZE', 56.4907, -4.2026, 250],
        'Northern Ireland' => ['BT', 54.7877, -6.4923, 120],
        'Wales' => ['CF|LD|LL|NP|SA', 52.1307, -3.7837, 150],
        'North East' => ['DH|DL|NE|SR|TS', 54.9783, -1.6178, 100],
        'North West' => ['BB|BL|CA|CH|CW|FY|L|LA|M|OL|PR|SK|WA|WN', 53.4808, -2.2426, 120],
        'Yorkshire and the Humber' => ['BD|DN|HD|HG|HU|HX|LS|S|WF|YO', 53.9583, -1.0803, 110],
        'West Midlands' => ['B|CV|DY|HR|ST|SY|TF|WR|WS|WV', 52.4862, -1.8904, 100],
        'East Midlands' => ['DE|LE|LN|NG|NN', 52.9548, -1.1581, 110],
        'East of England' => ['AL|CB|CM|CO|IP|LU|NR|PE|SG|SS', 52.2053, 0.1218, 120],
        'Greater London' => ['BR|CR|E|EC|EN|HA|IG|KT|N|NW|RM|SE|SM|SW|TW|UB|W|WC', 51.5074, -0.1278, 40],
        'South East' => ['BN|CT|DA|GU|HP|ME|MK|OX|PO|RG|RH|SL|SO|TN', 51.2787, 0.5217, 120],
        'South West' => ['BA|BH|BS|DT|EX|GL|PL|SN|SP|TA|TQ|TR', 50.7156, -3.5309, 160],
    ];

    public function run(): void
    {
        if (DB::table('regions')->exists()) {
            return;
        }
        $withCentroids = \Illuminate\Support\Facades\Schema::hasColumn('regions', 'latitude');
        foreach (self::REGIONS as $name => [$codes, $lat, $lng, $radius]) {
            DB::table('regions')->insert(array_merge(['name' => $name, 'districts_code' => $codes,
                'created_at' => now(), 'updated_at' => now()],
                $withCentroids ? ['latitude' => $lat, 'longitude' => $lng, 'radius' => $radius] : []));
        }
    }
}
