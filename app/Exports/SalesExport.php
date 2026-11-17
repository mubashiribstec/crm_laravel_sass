<?php

namespace App\Exports;

use Horsefly\Sale;
use Horsefly\Applicant;
use App\Exports\Concerns\HidesPrivateExportData;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalesExport implements FromCollection, WithHeadings
{
    use HidesPrivateExportData;

    protected $type;
    protected $filters;

    public function __construct(string $type = 'all', array $filters = [])
    {
        $this->type = $type;
        $this->filters = $filters;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        switch ($this->type) {
            case 'emails':
                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    )
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'rejected_cv':
                return Applicant::query()
                    ->select([
                        'sales.id as sale_id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                    ])
                    ->where('applicants.status', 1)
                    ->where('applicants.is_blocked', 0)
                    ->whereNull('applicants.deleted_at')

                    // ✅ Latest crm_notes per applicant/sale for rejected CVs
                    ->joinSub(
                        DB::table('crm_notes as cn')
                            ->select('cn.applicant_id', 'cn.sale_id', 'cn.created_at')
                            ->whereIn('cn.moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
                            ->whereIn('cn.id', function ($sub) {
                                $sub->select(DB::raw('MAX(id)'))
                                    ->from('crm_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'latest_crm',
                        function ($join) {
                            $join->on('applicants.id', '=', 'latest_crm.applicant_id');
                        }
                    )

                    // ✅ Related data joins
                    ->join('sales', 'latest_crm.sale_id', '=', 'sales.id')
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')

                    // ✅ History join for rejected entries
                    ->join('history', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'history.applicant_id')
                            ->on('latest_crm.sale_id', '=', 'history.sale_id')
                            ->whereIn('history.sub_stage', ['crm_reject', 'crm_no_job_reject'])
                            ->where('history.status', 1);
                    })

                    // ✅ Latest CV notes (optional but kept)
                    ->leftJoinSub(
                        DB::table('cv_notes as cv')
                            ->select('cv.applicant_id', 'cv.sale_id', 'cv.status')
                            ->whereIn('cv.id', function ($sub) {
                                $sub->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'latest_cv',
                        function ($join) {
                            $join->on('latest_crm.applicant_id', '=', 'latest_cv.applicant_id')
                                ->on('latest_crm.sale_id', '=', 'latest_cv.sale_id');
                        }
                    )

                    // ✅ Supporting joins
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })

                    // ✅ Prevent duplicates
                    ->groupBy([
                        'sales.id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name',
                        'sales.job_type',
                        'job_titles.name',
                        'sales.created_at',
                    ])
                    // ✅ Map exactly in your heading order
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'Created At'         => $item->created_at ? \Carbon\Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'Head Office Name'   => ucwords(strtolower($item->office_name)),
                            'Unit Name'          => ucwords(strtolower($item->unit_name)),
                            'Sale Postcode'      => strtoupper($item->sale_postcode),
                            'Contact Email'      => $item->contact_email ?? 'N/A',
                            'Job Category'       => ucwords($item->job_category ?? ''),
                            'Job Type'           => ucwords(str_replace('-', ' ', $item->job_type ?? '')),
                            'Job Title'          => strtoupper($item->job_title ?? ''),
                        ];
                    });

            case 'declined':
                return Applicant::query()
                ->select([
                    'sales.id as sale_id',
                    'offices.office_name',
                    'units.unit_name',
                    'sales.sale_postcode',
                    'contacts.contact_email',
                    'job_categories.name as job_category',
                    'sales.job_type',
                    'job_titles.name as job_title',
                    'sales.created_at',
                ])
                ->where('applicants.status', 1)
                ->where('applicants.is_blocked', 0)
                ->whereNull('applicants.deleted_at')

                // joinSub to get latest crm_notes with "declined"
                ->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->where('moved_tab_to', 'declined')
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'crm_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    }
                )

                // joins same as parent
                ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                ->join('offices', 'sales.office_id', '=', 'offices.id')
                ->join('units', 'sales.unit_id', '=', 'units.id')

                // join history for crm_declined
                ->join('history', function ($join) {
                    $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                        ->on('crm_notes.sale_id', '=', 'history.sale_id')
                        ->where('history.sub_stage', 'crm_declined')
                        ->where('history.status', 1);
                })

                // left join cv_notes subquery to get latest note per applicant/sale
                ->leftJoinSub(
                    DB::table('cv_notes')
                        ->select('applicant_id', 'sale_id', 'user_id', 'status')
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('cv_notes')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'cv_notes',
                    function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                    }
                )

                // same supporting joins as parent
                ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                ->leftJoin('contacts', function ($join) {
                    $this->constrainVisibleUnitContacts($join);
                })

                ->distinct('applicants.id')
                ->tap(function ($query) {
                    $this->excludeHiddenSaleSources($query);
                    $this->applyListFilters($query);
                })
                ->toBase()
                ->get()
                ->map(function ($item) {
                    return [
                        'created_at'   => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                        'office_name'  => ucwords(strtolower($item->office_name)),
                        'unit_name'    => ucwords(strtolower($item->unit_name)),
                        'sale_postcode'=> strtoupper($item->sale_postcode),
                        'contact_email'=> $item->contact_email,
                        'job_category' => ucwords($item->job_category),
                        'job_type'     => ucwords(str_replace('-', ' ', $item->job_type)),
                        'job_title'    => strtoupper($item->job_title),
                    ];
                });


            case 'not_attended':
                return Applicant::query()
                    ->select([
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->where('applicants.status', 1)
                    ->where('applicants.is_blocked', 0)
                    ->distinct('applicants.id')
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn("crm_notes.moved_tab_to", ["interview_not_attended"])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["crm_interview_not_attended"])
                            ->where("history.status", 1);
                    })
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('cv_notes')
                            ->whereColumn('cv_notes.applicant_id', 'applicants.id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id');
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => ucwords($item->job_category),
                            'job_type' => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });
            
            case 'start_date_hold':
                return Applicant::query()
                    ->select([
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->where('applicants.status', 1)
                    ->where('applicants.is_blocked', 0)
                    ->distinct('applicants.id')
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn("crm_notes.moved_tab_to", ["start_date_hold", "start_date_hold_save"])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["crm_start_date_hold", "crm_start_date_hold_save"])
                            ->where("history.status", 1);
                    })
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('cv_notes')
                            ->whereColumn('cv_notes.applicant_id', 'applicants.id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id');
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => ucwords($item->job_category),
                            'job_type' => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });
            case 'dispute':
                return Applicant::query()
                    ->select([
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->where('applicants.status', 1)
                    ->where('applicants.is_blocked', 0)
                    ->distinct('applicants.id')
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn("crm_notes.moved_tab_to", ["dispute"])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["crm_dispute"])
                            ->where("history.status", 1);
                    })
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('cv_notes')
                            ->whereColumn('cv_notes.applicant_id', 'applicants.id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id');
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => ucwords($item->job_category),
                            'job_type' => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });
            
            case 'paid':
                // Subquery: latest cv_note per applicant + sale
                $latestCvNotes = DB::table('cv_notes as cv1')
                    ->select('cv1.*')
                    ->whereIn('cv1.id', function ($q) {
                        $q->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                return Applicant::query()
                    ->select([
                        'applicants.id',
                        'sales.id as sale_id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn('crm_notes.moved_tab_to', ['paid'])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn('history.sub_stage', ['crm_paid'])
                            ->where('history.status', 1);
                    })
                    ->leftJoinSub($latestCvNotes, 'latest_cv', function ($join) {
                        $join->on('applicants.id', '=', 'latest_cv.applicant_id')
                            ->on('sales.id', '=', 'latest_cv.sale_id');
                    })
                    ->where('applicants.status', 1)
                    ->where('applicants.is_blocked', 0)
                    ->distinct()
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at'    => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name'   => ucwords(strtolower($item->office_name)),
                            'unit_name'     => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category'  => ucwords($item->job_category),
                            'job_type'      => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title'     => strtoupper($item->job_title),
                        ];
                    });

            case 'emailsOpen':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-opened%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 1); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as open_date'
                    )
                    ->where('sales.status', 1)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-opened%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'open_date' => $item->open_date ? Carbon::parse($item->open_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });

            case 'emailsClose':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-closed%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 0); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as closed_date'
                    )
                    ->where('sales.status', 0)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-closed%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'closed_date' => $item->closed_date ? Carbon::parse($item->closed_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });
                
            case 'noLatLong':
                return Sale::select(
                        'sales.id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'sales.lat as sale_lat',
                        'sales.lng as sale_lng',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    )
                    ->whereIn('sales.lat', ['0', '', null])
                    ->whereIn('sales.lng', ['0', '', null])
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'sale_lat' => $item->sale_lat,
                            'sale_lng' => $item->sale_lng,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

                
            case 'all':
                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_name',
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'contacts.contact_note',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    )
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'contact_note' => $item->contact_note,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'allOpen':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-opened%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 1); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_name',
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'contacts.contact_note',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as open_date'
                    )
                    ->where('sales.status', 1)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                    ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-opened%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'contact_note' => $item->contact_note,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'open_date' => $item->open_date ? Carbon::parse($item->open_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });

            case 'allClose':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-closed%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 0); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_name',
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'contacts.contact_note',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as closed_date'
                    )
                    ->where('sales.status', 0)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', function ($join) {
                        $this->constrainVisibleUnitContacts($join);
                    })
                     ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-closed%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->tap(function ($query) {
                        $this->excludeHiddenSaleSources($query);
                        $this->applyListFilters($query);
                    })
                    ->toBase()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'contact_note' => $item->contact_note,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'closed_date' => $item->closed_date ? Carbon::parse($item->closed_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });

            case 'allRejected':
                return $this->rejectedSalesExport(true);

            case 'emailsRejected':
                return $this->rejectedSalesExport(false);

            default:
            return collect(); // Return empty collection instead of null
        }
    }

    /**
     * Date filters of the Open / Closed / Rejected sales pages, copied from
     * SaleController::getOpenSales(), getClosedSales() and getRejectedSales() so an export
     * contains the same sales as the page. All three filter sales.updated_at and default
     * to the last 3 months; their period definitions differ slightly, as on the pages.
     */
    protected function applyPageDateFilters($query): void
    {
        $today = Carbon::today();
        $range = trim((string) ($this->filters['date_range_filter'] ?? ''));
        $between = function ($start, $end) use ($query) {
            $query->whereBetween('sales.updated_at', [$start, $end]);
        };
        $lastThreeMonths = fn () => $between($today->copy()->subMonths(3)->startOfDay(), $today->copy()->endOfDay());

        switch ($this->type) {
            case 'allOpen':
            case 'emailsOpen':
                // Exact range from the date picker: "Y-m-d|Y-m-d".
                if (str_contains($range, '|')) {
                    [$start, $end] = explode('|', $range, 2);
                    $between(trim($start) . ' 00:00:00', trim($end) . ' 23:59:59');
                    break; // A picked range replaces the period dropdown and its 3-month default.
                }

                switch ($this->filters['date_flock_filter'] ?? '') {
                    case 'last-6-months':
                        $between($today->copy()->subMonths(9)->startOfDay(), $today->copy()->subMonths(3)->endOfDay());
                        break;
                    case 'last-9-months':
                        $between($today->copy()->subMonths(18)->startOfDay(), $today->copy()->subMonths(9)->endOfDay());
                        break;
                    case 'other':
                        $query->where('sales.updated_at', '<', $today->copy()->subMonths(18)->endOfDay());
                        break;
                    default: // 'last-3-months' and no selection
                        $lastThreeMonths();
                        break;
                }
                break;

            case 'allClose':
            case 'emailsClose':
                switch ($range) {
                    case 'last-6-months':
                        $between($today->copy()->subMonths(6)->startOfDay(), $today->copy()->subMonths(3)->endOfDay());
                        break;
                    case 'last-9-months':
                        $between($today->copy()->subMonths(9)->startOfDay(), $today->copy()->subMonths(6)->endOfDay());
                        break;
                    case 'other':
                        $query->where('sales.updated_at', '<', $today->copy()->subMonths(18));
                        break;
                    default:
                        $lastThreeMonths();
                        break;
                }
                break;

            case 'allRejected':
            case 'emailsRejected':
                switch ($range) {
                    case 'last-6-months':
                        $end = $today->copy()->subMonths(3)->endOfDay();
                        $between($end->copy()->subMonths(6)->startOfDay(), $end);
                        break;
                    case 'last-9-months':
                        $end = $today->copy()->subMonths(9)->endOfDay();
                        $between($end->copy()->subMonths(9)->startOfDay(), $end);
                        break;
                    case 'other':
                        $query->where('sales.updated_at', '<', $today->copy()->subMonths(18));
                        break;
                    default:
                        $lastThreeMonths();
                        break;
                }
                break;
        }
    }

    /**
     * Rejected sales (Rejected Sales page): same columns as allClose / emailsClose, but
     * status 3 and the latest reject audit as the date, matching getRejectedSales().
     */
    protected function rejectedSalesExport(bool $allColumns)
    {
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\Sale')
            ->whereIn('message', ['sale-rejected', 'reject'])
            ->groupBy('auditable_id');

        $columns = ['sales.id', 'offices.office_name', 'units.unit_name', 'sales.sale_postcode'];
        $columns = array_merge($columns, $allColumns
            ? ['contacts.contact_name', 'contacts.contact_email', 'contacts.contact_phone', 'contacts.contact_landline', 'contacts.contact_note']
            : ['contacts.contact_email']);
        $columns = array_merge($columns, [
            'job_categories.name as job_category',
            'sales.job_type',
            'job_titles.name as job_title',
            'sales.created_at',
            'audits.created_at as rejected_date',
        ]);

        return Sale::select($columns)
            ->where('sales.status', 3)
            ->where('sales.is_on_hold', 0)
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('contacts', function ($join) {
                $this->constrainVisibleUnitContacts($join);
            })
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                $join->on('audits.auditable_id', '=', 'sales.id')
                    ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                    ->whereIn('audits.message', ['sale-rejected', 'reject'])
                    ->whereIn('audits.id', $latestAuditSub);
            })
            ->tap(function ($query) {
                $this->excludeHiddenSaleSources($query);
                $this->applyListFilters($query);
            })
            ->toBase()
            ->get()
            ->map(function ($item) use ($allColumns) {
                $row = [
                    'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                    'office_name' => ucwords(strtolower((string) $item->office_name)),
                    'unit_name' => ucwords(strtolower((string) $item->unit_name)),
                    'sale_postcode' => strtoupper((string) $item->sale_postcode),
                ];

                if ($allColumns) {
                    $row['contact_name'] = ucwords(strtolower((string) $item->contact_name));
                    $row['contact_email'] = $item->contact_email;
                    $row['contact_phone'] = $item->contact_phone;
                    $row['contact_landline'] = $item->contact_landline;
                    $row['contact_note'] = $item->contact_note;
                } else {
                    $row['contact_email'] = $item->contact_email;
                }

                return $row + [
                    'job_category' => strtoupper((string) $item->job_category),
                    'job_type' => strtoupper((string) $item->job_type),
                    'job_title' => strtoupper((string) $item->job_title),
                    'rejected_date' => $item->rejected_date ? Carbon::parse($item->rejected_date)->format('d M Y, h:i A') : 'N/A',
                ];
            });
    }

    /**
     * Match the sales list filters when they are sent from the list page.
     */
    protected function applyListFilters($query)
    {
        // Never export private data (users without show-private-data). Runs for every export
        // type, before the early return below:
        //  - sales under offices/units with private notes or a hidden job source;
        //  - for the applicant-based types (rejected_cv, declined, paid ...), applicants
        //    with a hidden job source.
        $this->exportExcludeOfficesAndUnits($query);

        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;
        if ($base->from === 'applicants') {
            $this->exportExcludeSourceColumn($query, 'applicants.job_source_id');
        }

        if ($this->filters === []) {
            return $query;
        }

        $query->whereNull('sales.deleted_at')
            ->whereNotIn('sales.status', [4, 5]);

        $listTypes = ['all', 'emails', 'noLatLong'];
        if (in_array($this->type, $listTypes, true)) {
            $status = strtolower(trim((string) ($this->filters['status_filter'] ?? 'open')));
            $normalized = in_array($status, ['closed', 'pending', 'rejected', 'on hold', 'open', 'blacklisted'], true)
                ? $status
                : 'open';

            switch ($normalized) {
                case 'blacklisted':
                    $query->where('sales.is_blacklist', 1);
                    break;
                case 'closed':
                    $query->where('sales.status', 0)
                        ->where('sales.is_blacklist', 0)
                        ->where('sales.is_on_hold', 0);
                    break;
                case 'pending':
                    $query->where('sales.status', 2)
                        ->where('sales.is_blacklist', 0);
                    break;
                case 'rejected':
                    $query->where('sales.status', 3)
                        ->where('sales.is_blacklist', 0);
                    break;
                case 'on hold':
                    $query->where('sales.is_on_hold', true)
                        ->where('sales.is_blacklist', 0);
                    break;
                case 'open':
                default:
                    $query->where('sales.status', 1)
                        ->where('sales.is_blacklist', 0)
                        ->where('sales.is_on_hold', 0);
                    break;
            }
        }

        $typeFilter = strtolower(trim((string) ($this->filters['type_filter'] ?? '')));
        if ($typeFilter === 'specialist' || $typeFilter === 'regular') {
            $query->where('sales.job_type', $typeFilter);
        }

        $officeIds = $this->arrayFilter($this->filters['office_filter'] ?? null);
        if ($officeIds !== []) {
            $query->whereIn('sales.office_id', $officeIds);
        }

        $sourceIds = $this->arrayFilter($this->filters['source_filter'] ?? null);
        if ($sourceIds !== []) {
            $query->whereIn('sales.job_source_id', $sourceIds);
        }

        $categoryIds = $this->arrayFilter($this->filters['category_filter'] ?? null);
        if ($categoryIds !== []) {
            $query->whereIn('sales.job_category_id', $categoryIds);
        }

        $titleIds = $this->arrayFilter($this->filters['title_filter'] ?? null);
        if ($titleIds !== []) {
            $query->whereIn('sales.job_title_id', $titleIds);
        }

        $userIds = $this->arrayFilter($this->filters['user_filter'] ?? null);
        if ($userIds !== []) {
            $query->whereIn('sales.user_id', $userIds);
        }

        // "Blacklisted only" checkbox on the sales pages
        if (!empty($this->filters['blacklist_filter'])) {
            $query->where('sales.is_blacklist', 1);
        }

        $limitCountFilter = strtolower(trim((string) ($this->filters['cv_limit_filter'] ?? '')));
        if (in_array($limitCountFilter, ['max', 'not max', 'zero'], true)) {
            $cvCountSub = DB::table('cv_notes')
                ->selectRaw('sale_id, COUNT(*) as cv_count')
                ->where('status', 1)
                ->groupBy('sale_id');

            $query->leftJoinSub($cvCountSub, 'cv_counts', 'cv_counts.sale_id', '=', 'sales.id');

            switch ($limitCountFilter) {
                case 'max':
                    $query->whereRaw('COALESCE(cv_counts.cv_count, 0) >= sales.cv_limit');
                    break;
                case 'not max':
                    $query->whereRaw('COALESCE(cv_counts.cv_count, 0) > 0 AND COALESCE(cv_counts.cv_count, 0) < sales.cv_limit');
                    break;
                case 'zero':
                    $query->whereRaw('COALESCE(cv_counts.cv_count, 0) = 0');
                    break;
            }
        }

        $this->applyPageDateFilters($query);

        $search = trim((string) ($this->filters['search'] ?? ''));
        if ($search !== '') {
            $saleIds = Sale::search($search)->keys()->toArray();
            $query->leftJoin('job_sources as export_job_sources', 'sales.job_source_id', '=', 'export_job_sources.id')
                ->leftJoin('users as export_users', 'sales.user_id', '=', 'export_users.id');
            $query->where(function ($q) use ($search, $saleIds) {
                if (!empty($saleIds)) {
                    $q->whereIn('sales.id', $saleIds);
                }
                $q->orWhere('offices.office_name', 'LIKE', "%{$search}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$search}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$search}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$search}%")
                    ->orWhere('export_job_sources.name', 'LIKE', "%{$search}%")
                    ->orWhere('export_users.name', 'LIKE', "%{$search}%")
                    ->orWhere('sales.sale_postcode', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }

    protected function arrayFilter($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_filter((array) $value, fn ($item) => $item !== '' && $item !== null));
    }

    public function headings(): array
    {
        switch ($this->type) {
            case 'emails':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'rejected_cv':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'declined':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'not_attended':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'start_date_hold':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'dispute':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'paid':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'emailsOpen':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title', 'Open Date'];
            case 'emailsClose':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title', 'Close Date'];
            case 'noLatLong':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Latitude', 'Longitude', 'Job Category', 'Job Type', 'Job Title'];
            case 'all':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title'];
            case 'allOpen':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title', 'Open Date'];
            case 'allClose':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title', 'Close Date'];
            case 'emailsRejected':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title', 'Rejected Date'];
            case 'allRejected':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title', 'Rejected Date'];
            default:
                return [];
        }
    }
}
