<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Office extends Model
{
    use HasFactory, SoftDeletes, Searchable;

    protected $table = 'offices';
    protected $fillable = [
        // 'id',
        'office_uid',
        'user_id',
        'office_name',
        'office_type',
        'office_website',
        'office_postcode',
        'office_notes',
        'office_lat',
        'office_lng',
        'status',
        'is_blacklist',
        // 'created_at',
        // 'updated_at',
    ];
    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'is_blacklist' => 'boolean',
    ];

    public function toSearchableArray(): array
    {
        return [
            'id' => (int)$this->id,
            'office_name' => $this->office_name,
            'office_postcode' => $this->office_postcode,
            'office_type' => $this->office_type,
            'office_website' => $this->office_website,
            'office_notes' => $this->office_notes,
            'office_lat' => $this->office_lat,
            'office_lng' => $this->office_lng
        ];
    }

    public function getFormattedOfficeNameAttribute()
    {
        return ucwords(strtolower($this->office_name));
    }
    public function getFormattedPostcodeAttribute()
    {
        return strtoupper($this->office_postcode ?? '-');
    }
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '-';
    }
    public function getFormattedUpdatedAtAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d M Y, h:i A') : '-';
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function sales()
    {
        return $this->hasMany(Sale::class, 'office_id');
    }
    public function units()
    {
        return $this->hasMany(Unit::class, 'office_id');
    }
    public function documents()
    {
        return $this->hasMany(OfficeDocument::class, 'office_id');
    }
    public function contact()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
    public function module_note()
    {
        return $this->morphMany(ModuleNote::class, 'module_noteable');
    }
}
