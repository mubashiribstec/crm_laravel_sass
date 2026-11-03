<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SmtpSettingsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'from_name'    => 'My App',
                'from_address' => 'noreply@example.com',
                'mailer'       => 'smtp',
                'host'         => 'smtp.mailtrap.io',
                'port'         => 587,
                'username'     => 'your-username',
                'password'     => 'your-password',
                'encryption'   => 'tls',
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]
        ];

        // Placeholder settings for a fresh install only: never add a second active row, never
        // overwrite real settings.
        if (!DB::table('smtp_settings')->exists()) {
            DB::table('smtp_settings')->insert($settings);
        }
    }
}