<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class ApplicantsSynopsisForm extends Model
{
    protected $table = 'applicants_synopsis_forms';

    protected $fillable = [
        'applicant_id',
        'sale_id',
        'file_url',
    ];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
