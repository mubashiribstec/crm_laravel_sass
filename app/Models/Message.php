<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Message extends Model
{
    protected $table = 'messages';
    protected $fillable=[
        // 'id',
        'msg_id',
        'module_id',
        'module_type',
        'user_id',
        'message',
        'phone_number',
        'date',
        'time',
        'status',
        'is_sent',
        'is_read',
        'created_at',
        'updated_at'
    ];
    protected $appends = ['FormattedTime'];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'module_id')
            ->where('module_type', 'Horsefly\\Applicant');
    }

    public function getFormattedTimeAttribute()
    {
       return Carbon::parse($this->time)->format('h:i A');// Format the time as "11:10 AM"
    }

    /**
     * Which of these applicants have an unread incoming SMS (same rule as the topbar's unread messages;
     * opening the applicant's chat marks them read).
     *
     * @return array<int, true> applicant id => true
     */
    public static function applicantIdsWithUnread(iterable $applicantIds): array
    {
        $ids = collect($applicantIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return static::query()
            ->whereIn('module_id', $ids)
            ->where('module_type', 'Horsefly\\Applicant')
            ->where('status', 'incoming')
            ->where('is_read', 0)
            ->distinct()
            ->pluck('module_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
