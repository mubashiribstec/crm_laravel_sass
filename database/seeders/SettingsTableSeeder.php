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
            [
                'key'   => 'primary_email_address',
                'value' => '',
                'type'  => 'email',
                'group' => 'email',
            ],
            [
                'key'   => 'email_signature_image',
                'value' => '',
                'type'  => 'string',
                'group' => 'signature',
            ],
            [
                'key'   => 'email_signature_company_name',
                'value' => '',
                'type'  => 'string',
                'group' => 'signature',
            ],
            [
                'key'   => 'email_signature_phone',
                'value' => '',
                'type'  => 'string',
                'group' => 'signature',
            ],
            [
                'key'   => 'email_signature_email',
                'value' => '',
                'type'  => 'email',
                'group' => 'signature',
            ],
            [
                'key'   => 'email_signature_address',
                'value' => '',
                'type'  => 'string',
                'group' => 'signature',
            ],
            
        ];

        foreach ($settings as $setting) {
            // Only missing keys: a re-seed must never wipe a real API key, URL,
            // email signature or primary email address.
            DB::table('settings')->insertOrIgnore([
                'key'        => $setting['key'],
                'value'      => $setting['value'],
                'type'       => $setting['type'],
                'group'      => $setting['group'],
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }
}
