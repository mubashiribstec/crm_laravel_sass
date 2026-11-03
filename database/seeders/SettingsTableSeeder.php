<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key'   => 'site_name',
                'value' => 'CRM',
                'type'  => 'string',
                'group' => 'general',
            ],
            [
                'key'   => 'google_map_api_url',
                'value' => '',
                'type'  => 'string',
                'group' => 'google_maps',
            ],
            [
                'key'   => 'google_map_api_key',
                'value' => '',
                'type'  => 'string',
                'group' => 'google_maps',
            ],
            [
                'key'   => 'email_notifications',
                'value' => 'true',
                'type'  => 'boolean',
                'group' => 'notifications',
            ],
            [
                'key'   => 'sms_notifications',
                'value' => 'true',
                'type'  => 'boolean',
                'group' => 'notifications',
            ],
            [
                'key'   => 'sms_api_url',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            [
                'key'   => 'sms_port',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            [
                'key'   => 'sms_username',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            [
                'key'   => 'sms_password',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            
        ];

        foreach ($settings as $setting) {
            // Only missing keys: a re-seed must never wipe a real API key or URL.
            if (!DB::table('settings')->where('key', $setting['key'])->exists()) {
                DB::table('settings')->insert([
                    'key' => $setting['key'],
                    'value' => $setting['value'],
                    'type'  => $setting['type'],
                    'group' => $setting['group'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }
}
