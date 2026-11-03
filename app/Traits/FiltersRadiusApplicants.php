<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait FiltersRadiusApplicants
{
    /**
     * Apply the fetch-applicants-by-radius list filters (status, title, source, search).
     */
    protected function applyRadiusApplicantFilters($query, int $saleId, array $filters, bool $includeSearch = true)
    {
        $titleIds = $this->arrayFilterIds($filters['title_filter'] ?? null);
        if ($titleIds !== []) {
            $query->whereIn('applicants.job_title_id', $titleIds);
        } elseif (!empty($filters['category_id'])) {
            $query->where('applicants.job_category_id', $filters['category_id']);
        }

        $sourceIds = $this->arrayFilterIds($filters['source_filter'] ?? null);
        if ($sourceIds !== []) {
            $query->whereIn('applicants.job_source_id', $sourceIds);
        }

        $statusFilter = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($filters['status_filter'] ?? ''))));
        $statusFilter = str_replace('_', ' ', $statusFilter);
        if (in_array($statusFilter, ['', 'all', 'all applicant status'], true)) {
            $statusFilter = '';
        }

        switch ($statusFilter) {
            case 'interested':
                $query->where('is_no_job', false)
                    ->where('is_blocked', false)
                    ->where(function ($inner) {
                        $inner->where(function ($q) {
                            $q->where('is_temp_not_interested', false)->where('is_callback_enable', true);
                        })->orWhere(function ($q) {
                            $q->where('is_temp_not_interested', true)->where('is_callback_enable', true);
                        })->orWhere(function ($q) {
                            $q->where('is_temp_not_interested', false)->where('is_callback_enable', false);
                        });
                    })
                    ->where(function ($inner) {
                        $inner->where('have_nursing_home_experience', false)
                            ->orWhereNull('have_nursing_home_experience');
                    })
                    ->whereDoesntHave('pivotSales', function ($inner) use ($saleId) {
                        $inner->where('sale_id', $saleId);
                    });
                break;

            case 'not interested':
                $query->where('is_no_job', false)
                    ->where('is_blocked', false)
                    ->where('is_callback_enable', false)
                    ->where(function ($inner) use ($saleId) {
                        $inner->where('is_temp_not_interested', true)
                            ->orWhereHas('pivotSales', function ($q) use ($saleId) {
                                $q->where('sale_id', $saleId);
                            });
                    })
                    ->where(function ($inner) {
                        $inner->where('have_nursing_home_experience', false)
                            ->orWhereNull('have_nursing_home_experience');
                    })
                    ->where(function ($inner) use ($saleId) {
                        $inner->doesntHave('history_request_nojob')
                            ->orWhereDoesntHave('history_request_nojob', function ($q) use ($saleId) {
                                $q->where('sale_id', $saleId);
                            });
                    });
                break;

            case 'blocked':
                $query->where('is_no_job', false)
                    ->where('is_blocked', true)
                    ->where('is_callback_enable', false)
                    ->where('is_temp_not_interested', false)
                    ->where(function ($inner) {
                        $inner->where('have_nursing_home_experience', false)
                            ->orWhereNull('have_nursing_home_experience');
                    });
                break;

            case 'callback':
                $query->where('is_callback_enable', true);
                break;

            case 'have nursing home experience':
                $query->where('have_nursing_home_experience', true);
                break;

            case 'no job':
                $query->where(function ($inner) use ($saleId) {
                    $inner->where(function ($noJob) {
                        $noJob->where('is_no_job', true)
                            ->where('is_callback_enable', false)
                            ->where(function ($q) {
                                $q->where('have_nursing_home_experience', false)
                                    ->orWhereNull('have_nursing_home_experience');
                            });
                    })->orWhereHas('history_request_nojob', function ($q) use ($saleId) {
                        $q->where('sale_id', $saleId);
                    });
                });
                break;
        }

        $cvStatusFilter = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($filters['cv_status_filter'] ?? ''))));
        $cvStatusFilter = str_replace('_', ' ', $cvStatusFilter);
        if (in_array($cvStatusFilter, ['', 'all', 'all cv status'], true)) {
            $cvStatusFilter = '';
        }

        switch ($cvStatusFilter) {
            case 'open':
                $query->where(function ($inner) {
                    $inner->whereNull('applicants.paid_status')
                        ->orWhere('applicants.paid_status', '!=', 'close');
                })
                    ->whereNotExists(function ($sub) use ($saleId) {
                        $this->cvNotesForSale($sub, $saleId)->whereIn('cv_notes.status', [0, 1, 2]);
                    })
                    ->whereNotExists(function ($sub) use ($saleId) {
                        $this->cvNotesExceptSale($sub, $saleId)->where('cv_notes.status', 1);
                    });
                break;

            case 'sent':
                $query->where(function ($inner) {
                    $inner->whereNull('applicants.paid_status')
                        ->orWhere('applicants.paid_status', '!=', 'close');
                })
                    ->whereExists(function ($sub) use ($saleId) {
                        $this->cvNotesForSale($sub, $saleId)->where('cv_notes.status', 1);
                    });
                break;

            case 'paid':
                $query->where(function ($inner) use ($saleId) {
                    $inner->where('applicants.paid_status', 'close')
                        ->orWhereExists(function ($sub) use ($saleId) {
                            $this->cvNotesForSale($sub, $saleId)->where('cv_notes.status', 2);
                        });
                });
                break;

            case 'reject job':
                $query->where(function ($inner) {
                    $inner->whereNull('applicants.paid_status')
                        ->orWhere('applicants.paid_status', '!=', 'close');
                })
                    ->whereExists(function ($sub) use ($saleId) {
                        $this->cvNotesForSale($sub, $saleId)->where('cv_notes.status', 0);
                    })
                    ->whereNotExists(function ($sub) use ($saleId) {
                        $this->cvNotesForSale($sub, $saleId)->where('cv_notes.status', 1);
                    });
                break;

            case 'crm active':
                $query->where(function ($inner) {
                    $inner->whereNull('applicants.paid_status')
                        ->orWhere('applicants.paid_status', '!=', 'close');
                })
                    ->whereNotExists(function ($sub) use ($saleId) {
                        $this->cvNotesForSale($sub, $saleId)->whereIn('cv_notes.status', [0, 1, 2]);
                    })
                    ->whereExists(function ($sub) use ($saleId) {
                        $this->cvNotesExceptSale($sub, $saleId)->where('cv_notes.status', 1);
                    });
                break;
        }

        if (!$includeSearch) {
            return $query;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('applicants.applicant_name', 'LIKE', "%{$search}%")
                    ->orWhere('applicants.applicant_email', 'LIKE', "%{$search}%")
                    ->orWhere('applicants.applicant_email_secondary', 'LIKE', "%{$search}%")
                    ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$search}%")
                    ->orWhere('applicants.applicant_phone', 'LIKE', "%{$search}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$search}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$search}%")
                    ->orWhere('job_sources.name', 'LIKE', "%{$search}%")
                    ->orWhereRaw('EXISTS (
                        SELECT 1 FROM module_notes mn
                        WHERE mn.module_noteable_id = applicants.id
                          AND mn.module_noteable_type = ?
                          AND mn.details LIKE ?
                    )', ['Horsefly\\Applicant', '%' . $search . '%'])
                    ->orWhereRaw('EXISTS (
                        SELECT 1 FROM applicant_notes an
                        WHERE an.applicant_id = applicants.id
                          AND an.details LIKE ?
                    )', ['%' . $search . '%']);
            });
        }

        return $query;
    }

    protected function cvNotesForSale($sub, int $saleId)
    {
        return $sub->select(DB::raw(1))
            ->from('cv_notes')
            ->whereColumn('cv_notes.applicant_id', 'applicants.id')
            ->where('cv_notes.sale_id', $saleId);
    }

    protected function cvNotesExceptSale($sub, int $saleId)
    {
        return $sub->select(DB::raw(1))
            ->from('cv_notes')
            ->whereColumn('cv_notes.applicant_id', 'applicants.id')
            ->where('cv_notes.sale_id', '!=', $saleId);
    }

    protected function arrayFilterIds($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_filter((array) $value, fn ($item) => $item !== '' && $item !== null));
    }

    protected function applyRadiusDistanceFilter($query, float $lat, float $lng, float $radiusKm)
    {
        $padded = $radiusKm * 1.05;
        $deltaLat = $padded / 111.32;
        $cosLat = max(abs(cos(deg2rad($lat))), 0.01);
        $deltaLng = $padded / (111.32 * $cosLat);

        return $query
            ->whereBetween('applicants.lat', [$lat - $deltaLat, $lat + $deltaLat])
            ->whereBetween('applicants.lng', [$lng - $deltaLng, $lng + $deltaLng])
            ->whereRaw(
                '(6371 * acos(LEAST(1, GREATEST(-1, cos(radians(?)) * cos(radians(applicants.lat)) * cos(radians(applicants.lng) - radians(?)) + sin(radians(?)) * sin(radians(applicants.lat)))))) <= ?',
                [$lat, $lng, $lat, $radiusKm]
            );
    }
}
