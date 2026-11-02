<?php

namespace App\Console\Commands;

use App\Support\Xplogent;
use Horsefly\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan xplogent:make-ai-user
 *
 * Creates (once) the "Xplogent AI" user every Xplogent write is attributed to,
 * and prints its id for XPLOGENT_AI_USER_ID. The user has no roles and an
 * unusable random password, so nobody can log in as it.
 */
class XplogentMakeAiUser extends Command
{
    protected $signature = 'xplogent:make-ai-user {--email=xplogent-ai@invalid.local : login email (never used)}';
    protected $description = 'Create the "Xplogent AI" user (idempotent) and print its id';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $user = User::withTrashed()->where('email', $email)->first();
        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }
            $this->info("Xplogent AI user already exists: id {$user->id}");
        } else {
            $user = new User();
            $user->name = Xplogent::AI_USER_NAME;
            $user->email = $email;
            $user->password = Str::random(64);   // hashed by the model; never shared
            if (in_array('is_active', $user->getFillable(), true)) {
                $user->is_active = 0;                // no dashboard login
            }
            $user->save();
            $this->info("Created the Xplogent AI user: id {$user->id}");
        }
        $this->line("Set in .env: XPLOGENT_AI_USER_ID={$user->id}");

        return self::SUCCESS;
    }
}
