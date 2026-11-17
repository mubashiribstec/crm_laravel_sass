<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Network prefixes allowed to open the CRM (the IPAddress middleware compares the first three
 * parts of the visitor's IPv4 address). CRM_ALLOWED_IP_PREFIXES in .env, comma-separated, e.g.
 * "192.168.110,10.0.5"; default 192.168.110 (the office) and 127.0.0 (the server itself).
 * Inserts only what is missing; never removes a prefix.
 */
class AllowedIpsSeeder extends Seeder
{
    public static function prefixes(): array
    {
        $raw = (string) (env('CRM_ALLOWED_IP_PREFIXES') ?: '192.168.110,127.0.0');

        return array_values(array_unique(array_filter(array_map(
            fn ($p) => trim(preg_replace('/(\.\*|\.)+$/', '', trim($p))),
            explode(',', $raw)
        ), fn ($p) => (bool) preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $p))));
    }

    public function run(): void
    {
        foreach (self::prefixes() as $prefix) {
            if (!DB::table('allowed_ips')->where('ip_prefix', $prefix)->exists()) {
                DB::table('allowed_ips')->insert(['ip_prefix' => $prefix, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
