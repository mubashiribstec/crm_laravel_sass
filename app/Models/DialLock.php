<?php

namespace Horsefly;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

class DialLock extends Model
{
    protected $table = 'dial_locks';

    protected $fillable = [
        'phone_key',
        'full_number',
        'user_id',
        'user_name',
        'applicant_id',
        'lock_token',     // Xplogent: only the same per-call token may renew or release
        'call_count',
        'locked_at',
        'expires_at',
    ];

    protected $casts = [
        'locked_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'user_id'      => 'integer',
        'applicant_id' => 'integer',
        'call_count'   => 'integer',
    ];

    /**
     * Normalise a phone number to a lock key. Delegates to the shared
     * PhoneNumber helper so lookup / dialing / search all agree on what
     * counts as "the same number".
     */
    public static function keyFor(?string $number): ?string
    {
        return PhoneNumber::lockKey($number);
    }
}
