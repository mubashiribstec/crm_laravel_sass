<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobSourcesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $sources = [
            [
            'name' => 'Niche',
            'description' => 'Specialized job platform',
            ],
            [
            'name' => 'Reed',
            'description' => 'UK-based job search platform',
            ],
            [
            'name' => 'Total Job',
            'description' => 'Comprehensive job search engine',
            ],
            [
            'name' => 'Referral',
            'description' => 'Employee or partner referrals',
            ],
            [
            'name' => 'CV Library',
            'description' => 'UK-based CV database and job board',
            ],
            [
            'name' => 'Social Media',
            'description' => 'Job postings on social media platforms',
            ],
            [
            'name' => 'Other Source',
            'description' => 'Miscellaneous or unclassified sources',
            ],
        ];

        foreach ($sources as $source) {               // insert only what is missing: safe to run twice
            if (!DB::table('job_sources')->where('name', $source['name'])->exists()) {
                DB::table('job_sources')->insert([
                    'name' => $source['name'],
                    'description' => $source['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}