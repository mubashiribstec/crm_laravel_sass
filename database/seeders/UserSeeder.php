<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Horsefly\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable model events if needed (e.g., observers)
        $dispatcher = User::getEventDispatcher();
        User::unsetEventDispatcher();

        // Idempotent (a second db:seed used to fail on the unique email), and no well-known
        // password: a random one is printed once. Set your own: php artisan tinker, or the
        // Users screen. SEED_ADMIN_PASSWORD overrides it (e.g. for a test machine).
        if (!User::where('email', 'admin@gmail.com')->exists()) {
            $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(20);
            User::create([
                'name' => 'admin',
                'email' => 'admin@gmail.com',
                'email_verified_at' => now(),
                'is_admin' => 1,
                'password' => Hash::make($password),
                'remember_token' => Str::random(10),
            ]);
            if (!env('SEED_ADMIN_PASSWORD') && $this->command) {
                $this->command->warn("Admin login: admin@gmail.com / {$password}  (shown once: change it after logging in)");
            }
        }

        // Restore events
        User::setEventDispatcher($dispatcher);
    }
}
