<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JobCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $categories = [
            [
            'name' => 'Nurse',
            'description' => 'Nurse',
            'is_active' => true,
            ],
            [
            'name' => 'Non Nurse',
            'description' => 'Non Nurse',
            'is_active' => true,
            ],
            [
            'name' => 'Specialist',
            'description' => 'Specialist',
            'is_active' => true,
            ],
            [
            'name' => 'Chef',
            'description' => 'Chef',
            'is_active' => true,
            ],
            [
            'name' => 'Nursery',
            'description' => 'Nursery',
            'is_active' => true,
            ]
        ];

        // Fix the old misspelling in place, so a database seeded before keeps one category.
        DB::table('job_categories')->where('name', 'Speicialist')->update(['name' => 'Specialist', 'description' => 'Specialist']);

        foreach ($categories as $category) {          // insert only what is missing: safe to run twice
            if (!DB::table('job_categories')->where('name', $category['name'])->exists()) {
                DB::table('job_categories')->insert([
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_active' => $category['is_active'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}