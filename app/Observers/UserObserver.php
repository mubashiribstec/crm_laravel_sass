<?php

namespace App\Observers;

use Horsefly\User;
use Horsefly\Audit;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UserObserver
{
    /** Columns whose values never go into an audit row (2026_11_04_200500 cleans older rows). */
    public const SECRET_FIELDS = ['password', 'remember_token', 'sip_password'];

    public const CHANGED = '(changed)';

    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $user->audits()->create([
            "user_id" => Auth::id() ?? 1, // Fallback to 1 (admin) if none authenticated
            "data" => $user->toJson(),
            "message" => "User '{$user->name}' has been created successfully at {$user->created_at}",
        ]);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user)
    {
        // Ensure created_at and updated_at are Carbon instances before calling eq()
        if ($user->created_at && $user->updated_at) {
            $createdAt = Carbon::parse($user->created_at);
            $updatedAt = Carbon::parse($user->updated_at);

            if ($createdAt->eq($updatedAt)) {
                return;
            }
        }

        $columns = $user->getDirty();
        // Signing in with "remember me" or signing out only cycles remember_token: not an edit.
        if (array_diff(array_keys($columns), ['remember_token', 'updated_at']) === []) {
            return; // No real changes
        }

        // Never copy a secret into the audit log (activity logs show these values): say that it
        // changed, not what to. (audit N-11)
        unset($columns['remember_token']);
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $columns)) {
                $columns[$field] = self::CHANGED;
            }
        }

        $user->audits()->create([
            // A console or queue change has no signed-in user; audits.user_id is required.
            "user_id" => Auth::id() ?? $user->id,
            "data" => json_encode(array_merge($user->toArray(), ['changes_made' => $columns])),
            "message" => "User '{$user->name}' has been updated successfully at {$user->updated_at}",
        ]);
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        //
    }
}
