<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasDistanceCalculation;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Searchable;

class Applicant extends Model
{
    use HasFactory, SoftDeletes, HasDistanceCalculation, Searchable;

    protected $table = 'applicants';
    protected $fillable = [
        // 'id',
        'applicant_uid',
        'user_id',
        'job_source_id',
        'job_category_id',
        'job_title_id',
        'job_type',
        'applicant_name',
        'applicant_email',
        'applicant_email_secondary',
        'applicant_postcode',
        'applicant_phone',
        'applicant_phone_secondary',
        'applicant_landline',
        'applicant_cv',
        'updated_cv',
        'applicant_notes',
        'applicant_experience',
        'lat',
        'lng',
        'gender',
        'dob',

        // Boolean flags
        'is_blocked',
        'is_temp_not_interested',
        'is_callback_enable',
        'is_no_job',
        'is_no_response',
        'is_in_nurse_home',
        'is_circuit_busy',
        'is_cv_in_quality',
        'is_cv_in_quality_clear',
        'is_cv_sent',
        'is_cv_in_quality_reject',
        'is_interview_confirm',
        'is_interview_attend',
        'is_in_crm_request',
        'is_in_crm_reject',
        'is_in_crm_request_reject',
        'is_crm_request_confirm',
        'is_crm_interview_attended',
        'is_in_crm_start_date',
        'is_in_crm_invoice',
        'is_in_crm_invoice_sent',
        'is_in_crm_start_date_hold',
        'is_in_crm_paid',
        'is_in_crm_dispute',
        'is_job_within_radius',
        'have_nursing_home_experience',

        // Status fields
        'status',
        'paid_status',
        'paid_timestamp',
        'deleted_at',
        // 'created_at',
        // 'updated_at'
    ];
    protected $casts = [
        'is_blocked' => 'boolean',
        'is_no_job' => 'boolean',
        'is_no_response' => 'boolean',
        'is_circuit_busy' => 'boolean',
        'is_cv_in_quality' => 'boolean',
        'is_cv_in_quality_clear' => 'boolean',
        'is_cv_sent' => 'boolean',
        'is_cv_in_quality_reject' => 'boolean',
        'is_interview_confirm' => 'boolean',
        'is_interview_attend' => 'boolean',
        'is_in_crm_request' => 'boolean',
        'is_in_crm_reject' => 'boolean',
        'is_in_crm_request_reject' => 'boolean',
        'is_crm_request_confirm' => 'boolean',
        'is_crm_interview_attended' => 'integer',   // 0 = no, 1 = yes, 2 = pending (boolean read 2 as true)
        'is_in_crm_start_date' => 'boolean',
        'is_in_crm_invoice' => 'boolean',
        'is_in_crm_invoice_sent' => 'boolean',
        'is_in_crm_start_date_hold' => 'boolean',
        'is_in_crm_paid' => 'boolean',
        'is_in_crm_dispute' => 'boolean',
        // Add other casts as needed
    ];

    protected $hidden = [
        'deleted_at',
        'dob',
        // PII fields — hidden from JSON serialisation / API responses
        'applicant_email',
        'applicant_email_secondary',
        'applicant_phone',
        'applicant_phone_secondary',
        'applicant_landline',
        'applicant_postcode',
        // Indexed normalised (digits-only) phone columns — internal search keys
        'applicant_phone_normalized',
        'applicant_phone_secondary_normalized',
        'applicant_landline_normalized',
    ];

    /**
     * Cached check for whether the indexed *_normalized phone columns exist.
     * Lets scopePhoneMatches() and the saving hook work both before and after
     * the add_normalized_phone_columns migration has been run, with a single
     * information_schema hit per request.
     */
    protected static ?bool $hasPhoneNormalizedColumns = null;

    public static function hasPhoneNormalizedColumns(): bool
    {
        if (static::$hasPhoneNormalizedColumns === null) {
            try {
                static::$hasPhoneNormalizedColumns = Schema::hasColumn(
                    (new static)->getTable(),
                    'applicant_phone_normalized'
                );
            } catch (\Throwable $e) {
                static::$hasPhoneNormalizedColumns = false;
            }
        }
        return static::$hasPhoneNormalizedColumns;
    }

    /**
     * Keep the indexed *_normalized columns in sync whenever an applicant is
     * saved, so click-to-dial / caller-ID lookup can match on an index instead
     * of a full-table REGEXP_REPLACE scan.
     */
    protected static function booted(): void
    {
        static::saving(function (self $applicant) {
            if (!static::hasPhoneNormalizedColumns()) {
                return;
            }
            $applicant->applicant_phone_normalized = PhoneNumber::normalize($applicant->applicant_phone);
            $applicant->applicant_phone_secondary_normalized = PhoneNumber::normalize($applicant->applicant_phone_secondary);
            $applicant->applicant_landline_normalized = PhoneNumber::normalize($applicant->applicant_landline);
        });
    }

    /**
     * Match an applicant by phone across primary / secondary / landline.
     * Tries an exact match first, then a fuzzy last-10-digit match. Uses the
     * indexed *_normalized columns when present (fast), otherwise falls back to
     * a REGEXP_REPLACE scan so it still works before the migration is applied.
     */
    public function scopePhoneMatches($query, ?string $number)
    {
        $raw = (string) $number;
        $tail = PhoneNumber::tail($number);

        return $query->where(function ($q) use ($raw, $tail) {
            $q->where('applicant_phone', $raw)
                ->orWhere('applicant_phone_secondary', $raw)
                ->orWhere('applicant_landline', $raw);

            if ($tail !== null) {
                if (static::hasPhoneNormalizedColumns()) {
                    $q->orWhere('applicant_phone_normalized', $tail)
                        ->orWhere('applicant_phone_secondary_normalized', $tail)
                        ->orWhere('applicant_landline_normalized', $tail);
                } else {
                    $q->orWhereRaw("RIGHT(REGEXP_REPLACE(applicant_phone, '[^0-9]', ''), 10) = ?", [$tail])
                        ->orWhereRaw("RIGHT(REGEXP_REPLACE(applicant_phone_secondary, '[^0-9]', ''), 10) = ?", [$tail])
                        ->orWhereRaw("RIGHT(REGEXP_REPLACE(applicant_landline, '[^0-9]', ''), 10) = ?", [$tail]);
                }
            }
        });
    }

    public function scopeIgnoreBooleans($query)
    {
        $booleanColumns = [
            'is_blocked',
            'is_no_job',
            'is_no_response',
            'is_circuit_busy',
            'is_cv_in_quality',
            'is_cv_in_quality_clear',
            'is_cv_sent',
            'is_cv_in_quality_reject',
            'is_interview_confirm',
            'is_interview_attend',
            'is_in_crm_request',
            'is_in_crm_reject',
            'is_in_crm_request_reject',
            'is_crm_request_confirm',
            'is_crm_interview_attended',
            'is_in_crm_start_date',
            'is_in_crm_invoice',
            'is_in_crm_invoice_sent',
            'is_in_crm_start_date_hold',
            'is_in_crm_paid',
            'is_in_crm_dispute',
            'is_job_within_radius',
            'have_nursing_home_experience',
        ];

        foreach ($booleanColumns as $column) {
            $query->whereNull($column);
        }

        return $query;
    }
    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // Default searchable columns (must exist in the table for the 'database' engine)
        $array = [
            'id' => (int) $this->id,
            'applicant_name' => $this->applicant_name,
            'applicant_email' => $this->applicant_email,
            'applicant_email_secondary' => $this->applicant_email_secondary,
            'applicant_postcode' => $this->applicant_postcode,
            'applicant_phone' => $this->applicant_phone,
            'applicant_phone_secondary' => $this->applicant_phone_secondary,
            'applicant_landline' => $this->applicant_landline,
            'applicant_notes' => strip_tags($this->applicant_notes),
            'applicant_experience' => strip_tags($this->applicant_experience),
        ];

        return $array;
    }
    public function scopeStatusWise($query, $status)
    {
        return $query->where('status', $status);
    }
    public function scopeWithExperience($query)
    {
        return $query->where('have_nursing_home_experience', true);
    }
    public function getFormattedPostcodeAttribute()
    {
        return strtoupper($this->applicant_postcode ?? '-');
    }
    public function getFormattedApplicantNameAttribute()
    {
        return ucwords(strtolower($this->applicant_name));
    }
    public function getFormattedPhoneAttribute()
    {
        return $this->applicant_phone;
    }
    public function getFormattedPhoneSecondaryAttribute()
    {
        return $this->applicant_phone_secondary;
    }
    public function getFormattedLandlineAttribute()
    {
        return $this->applicant_landline;
    }
    public function getFormattedCvAttribute()
    {
        return \App\Support\CvStorage::exists($this->applicant_cv)
            ? "<a href='" . e(\App\Support\CvStorage::url($this->id, 'original')) . "' target='_blank'>View CV</a>" : 'No CV';
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
    public function jobSource()
    {
        return $this->belongsTo(JobSource::class, 'job_source_id');
    }
    public function jobCategory()
    {
        return $this->belongsTo(JobCategory::class, 'job_category_id');
    }
    public function jobTitle()
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }
    public function getJobTitleName()
    {
        return $this->jobTitle ? $this->jobTitle->name : '-';
    }
    public function getJobCategoryName()
    {
        return $this->jobCategory ? $this->jobCategory->name : '-';
    }
    public function getJobSourceName()
    {
        return $this->jobSource ? $this->jobSource->name : '-';
    }
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
    public function module_note()
    {
        return $this->morphMany(ModuleNote::class, 'module_noteable');
    }
    public function crmNotes()
    {
        return $this->hasMany(CrmNote::class, 'applicant_id');
    }
    public function crmHistory()
    {
        return $this->hasMany(History::class)->where('stage', 'crm')->where('status', 1);
    }
    public function history()
    {
        return $this->hasMany(History::class, 'applicant_id');
    }
    public function revertStages()
    {
        return $this->hasMany(RevertStage::class, 'applicant_id');
    }
    public function crm_notes()
    {
        return $this->hasMany(CrmNote::class, 'applicant_id');
    }
    public function callback_notes()
    {
        return $this->hasMany(ApplicantNote::class)
            ->whereIn('moved_tab_to', ['callback', 'revert_callback'])
            ->orderBy('id', 'desc');
    }
    public function no_nursing_home_notes()
    {
        return $this->hasMany(ApplicantNote::class)
            ->whereIn('moved_tab_to', ['no_nursing_home', 'revert_no_nursing_home'])
            ->orderBy('id', 'desc');
    }
    public function cv_notes()
    {
        return $this->hasMany(CVNote::class, 'applicant_id', 'id');
    }
    public function pivotSales()
    {
        return $this->hasMany(ApplicantPivotSale::class, 'applicant_id');
    }
    public function history_request_nojob()
    {
        return $this->hasMany(History::class, 'applicant_id', 'id')
            ->whereIn('sub_stage', ['quality_cleared_no_job', 'crm_no_job_request']); // Limit to 1 result
    }
    public function applicant_notes()
    {
        return $this->hasMany(ApplicantNote::class, 'applicant_id');
    }
    public function updated_by_audits()
    {
        return $this->morphMany(Audit::class, 'auditable')
            ->where('message', 'like', '%has been updated%')
            ->with('user');
    }

    public function created_by_audit()
    {
        return $this->morphOne(Audit::class, 'auditable')
            ->where('message', 'like', '%has been created%')
            ->with('user');
    }
    public function messages()
    {
        return $this->hasMany(Message::class, 'module_id')
            ->where('module_type', 'Horsefly\\Applicant');
    }
    public function qualityNotes()
    {
        return $this->hasMany(QualityNotes::class, 'applicant_id', 'id');
    }
}
