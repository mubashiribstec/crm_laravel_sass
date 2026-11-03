<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfficeDocument extends Model
{
    use SoftDeletes;

    protected $table = 'offices_documents';

    protected $fillable = [
        'office_id',
        'user_id',
        'document_name',
        'document_path',
        'document_size',
        'document_extension',
        'delete_reason',
        'deleted_by',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }
}
