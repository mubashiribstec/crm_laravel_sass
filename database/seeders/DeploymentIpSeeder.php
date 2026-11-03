<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Horsefly\User;

class DeploymentIpSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::where('is_admin', 1)->first();

        if ($user) {
            $ip = getHostByName(getHostName()) ?? request()->ip() ?? '127.0.0.1';

            if (!DB::table('ip_addresses')->where('user_id', $user->id)->where('ip_address', $ip)->exists()) {
                DB::table('ip_addresses')->insert([
                    'user_id'    => $user->id,
                    'ip_address' => $ip,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
