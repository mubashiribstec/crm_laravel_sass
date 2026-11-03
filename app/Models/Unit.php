<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Unit extends Model
{
    use HasFactory, SoftDeletes, Searchable;

    protected $table = 'units';
    protected $fillable = [
        // 'id',
        'unit_uid',
        'user_id',
        'office_id',
        'job_source_id',
        'unit_name',
        'unit_postcode',
        'unit_website',
        'unit_notes',
        'lat',
        'lng',
        'status',
        'is_blacklist',
        // 'created_at',
        // 'updated_at'
    ];

    protected $casts = [
        'is_blacklist' => 'boolean',
    ];

    public function toSearchableArray(): array
    {
        return [
            'id' => (int)$this->id,
            'unit_name' => $this->unit_name,
            'unit_postcode' => $this->unit_postcode,
            'unit_website' => $this->unit_website,
            'unit_notes' => $this->unit_notes,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
    public function getFormattedUnitNameAttribute()
    {
        return ucwords(strtolower($this->unit_name));
    }
    public function getFormattedPostcodeAttribute()
    {
        return strtoupper($this->unit_postcode ?? '-');
    }
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '-';
    }
    public function getFormattedUpdatedAtAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d M Y, h:i A') : '-';
    }
    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }
    public function sales()
    {
        return $this->hasMany(Sale::class, 'unit_id');
    }
    public function office()
    {
        return $this->belongsTo(Office::class, 'office_id');
    }
    public function documents()
    {
        return $this->hasMany(UnitDocument::class, 'unit_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
}
