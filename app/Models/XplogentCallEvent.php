<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

/** One Xplogent call summary; call_id is the idempotency key, payload holds the transcript. */
class XplogentCallEvent extends Model
{
    protected $table = 'xplogent_call_events';

    protected $fillable = ['call_id', 'applicant_id', 'sale_id', 'call_log_id', 'outcome', 'payload'];

    protected $casts = [
        'call_id' => 'integer',
        'applicant_id' => 'integer',
        'sale_id' => 'integer',
        'call_log_id' => 'integer',
        'payload' => 'array',
    ];
}
