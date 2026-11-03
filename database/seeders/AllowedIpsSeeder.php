<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AllowedIpsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['192.168.110', '127.0.0'] as $prefix) {     // insert only what is missing
            if (!DB::table('allowed_ips')->where('ip_prefix', $prefix)->exists()) {
                DB::table('allowed_ips')->insert(['ip_prefix' => $prefix]);
            }
        }
    }
}