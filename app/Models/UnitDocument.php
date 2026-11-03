<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitDocument extends Model
{
    use SoftDeletes;

    protected $table = 'units_documents';

    protected $fillable = [
        'unit_id',
        'user_id',
        'document_name',
        'document_path',
        'document_size',
        'document_extension',
        'delete_reason',
        'deleted_by',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
