<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Support\CvStorage;

use Horsefly\Sale;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\SaleNote;
use Horsefly\Setting;
use Horsefly\QualityNotes;
use Horsefly\History;
use Horsefly\CVNote;
use Horsefly\CrmNote;
use Horsefly\Applicant;
use Horsefly\RevertStage;
use Horsefly\SmsTemplate;
use Horsefly\JobSource;
use Horsefly\JobCategory;
use Horsefly\JobTitle;
use Horsefly\ModuleNote;
use Horsefly\User;

use App\Traits\SendEmails;
use App\Traits\SendSMS;
use App\Traits\HidesPrivateContacts;

use App\Support\DialLink;

use App\Observers\ActionObserver;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Yajra\DataTables\Facades\DataTables;
use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class QualityController extends Controller
{
    use SendEmails, SendSMS, HidesPrivateContacts;

    private const QUALITY_RESOURCE_TABS = [
        'requested cvs' => [
            'permission' => 'quality-assurance-resources-tab-requested-cvs',
            'label' => 'Requested CVs',
        ],
        'open cvs' => [
            'permission' => 'quality-assurance-resources-tab-open-cvs',
            'label' => 'Open CVs',
        ],
        'no job cvs' => [
            'permission' => 'quality-assurance-resources-tab-no-job-cvs',
            'label' => 'No Job CVs',
        ],
        'rejected cvs' => [
            'permission' => 'quality-assurance-resources-tab-rejected-cvs',
            'label' => 'Rejected CVs',
        ],
        'cleared cvs' => [
            'permission' => 'quality-assurance-resources-tab-cleared-cvs',
            'label' => 'Cleared CVs',
        ],
    ];

    public function __construct()
    {
        $this->middleware('permission:quality-assurance-resource-index,quality-assurance-resources-tab-requested-cvs,quality-assurance-resources-tab-open-cvs,quality-assurance-resources-tab-no-job-cvs,quality-assurance-resources-tab-rejected-cvs,quality-assurance-resources-tab-cleared-cvs')->only(['resourceIndex']);
        $this->middleware('permission:quality-assurance-resources-tab-requested-cvs,quality-assurance-resources-tab-open-cvs,quality-assurance-resources-tab-no-job-cvs,quality-assurance-resources-tab-rejected-cvs,quality-assurance-resources-tab-cleared-cvs')->only(['getResourcesByTypeAjaxRequest']);
        $this->middleware('permission:quality-assurance-sale-index')->only(['saleIndex', 'getSalesByTypeAjaxRequest']);
        $this->middleware('permission:quality-assurance-sale-clear')->only(['clearRejectSale']);
        $this->middleware('permission:quality-assurance-resource-open-cv,quality-assurance-resource-clear-cv,quality-assurance-resource-reject-cv,quality-assurance-resource-revert-cv')->only(['updateApplicantStatusByQuality']);
        $this->middleware('permission:quality-assurance-resource-view-notes-history,quality-assurance-sale-view-notes-history')->only(['getQualityNotesHistory']);
    }

    public function resourceIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        $qualityResourceTabs = $this->allowedQualityResourceTabs();
        $defaultQualityResourceTab = array_key_first($qualityResourceTabs) ?? '';
        $defaultQualityResourceTabLabel = $qualityResourceTabs[$defaultQualityResourceTab] ?? 'Requested CVs';

        return view('quality.resources', compact(
            'jobCategories',
            'jobTitles',
            'jobSources',
            'qualityResourceTabs',
            'defaultQualityResourceTab',
            'defaultQualityResourceTabLabel'
        ));
    }
    public function saleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name', 'asc')->get();
        $users = User::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('quality.sales', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }

    public function getResourcesByTypeAjaxRequest(Request $request)
    {
        $typeFilter     = $request->input('type_filter', '');
        $categoryFilter = $request->input('category_filter', []);
        $titleFilter    = $request->input('title_filter', []);
        $sourceFilter   = $request->input('source_filter', []);
        $statusFilter   = strtolower(trim(preg_replace('/\s+/', ' ', (string) $request->input('status_filter', ''))));
        $allowedTabs = $this->allowedQualityResourceTabs();

        if (in_array($statusFilter, ['', 'all'], true)) {
            $statusFilter = array_key_first($allowedTabs) ?? '';
        }

        if ($statusFilter === '' || !isset($allowedTabs[$statusFilter])) {
            abort(403, 'You do not have permission to view this quality resources tab.');
        }

        $commonSaleSelect = [
            'offices.office_name as office_name',
            'sales.id as sale_id',
            'sales.job_category_id as sale_category_id',
            'sales.job_title_id as sale_title_id',
            'sales.sale_postcode',
            'sales.job_type as sale_job_type',
            'sales.timing',
            'sales.salary',
            'sales.experience as sale_experience',
            'sales.qualification as sale_qualification',
            'sales.benefits',
            'sales.office_id as sale_office_id',
            'sales.unit_id as sale_unit_id',
            'sales.position_type',
            'sales.status as sale_status',
            'sales.job_source_id',
            'sales.is_on_hold',           // was referenced in addColumn('action') but never selected — bug fix, not perf
            'units.unit_name',
            'units.unit_postcode',
            'units.unit_website',
            'users.name as user_name',
        ];

        $model = Applicant::query()
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.applicant_email',
                'applicants.applicant_email_secondary',
                'applicants.applicant_phone',
                'applicants.applicant_phone_secondary',
                'applicants.applicant_postcode',
                'applicants.applicant_landline',
                'applicants.is_blocked',
                'applicants.applicant_cv',
                'applicants.updated_cv',
                'applicants.job_category_id',
                'applicants.job_title_id',
                'applicants.job_type',

                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'sale_job_sources.name as sale_job_source_name',
            ])
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->leftJoin('job_titles',     'applicants.job_title_id',    '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id');

        $this->applyStatusFilterJoins($model, $statusFilter, $commonSaleSelect);

        // Join after applyStatusFilterJoins() so `sales` is already in the query.
        $model->leftJoin('job_sources as sale_job_sources', 'sales.job_source_id', '=', 'sale_job_sources.id');

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // Hide rows whose office/unit notes contain a private word (users without show-private-data).
        $this->excludePrivateOfficeUnitNotes($model);

        // Hide rows whose own notes (the Notes column) contain a private word.
        $this->excludePrivateSelectedNotes($model, 'notes_detail');
        $this->excludePrivateQualityDecisionNotes($model, $statusFilter);

        $model->with(['cv_notes' => fn($q) => $q->select('id', 'applicant_id', 'sale_id', 'status')]);

        $model
            ->when($categoryFilter, fn($q) => $q->whereIn('applicants.job_category_id', $categoryFilter))
            ->when($titleFilter,    fn($q) => $q->whereIn('applicants.job_title_id', $titleFilter))
            ->when($sourceFilter,   fn($q) => $q->whereIn('sales.job_source_id', $sourceFilter))
            ->when($typeFilter === 'specialist', fn($q) => $q->where('applicants.job_type', 'specialist'))
            ->when($typeFilter === 'regular',    fn($q) => $q->where('applicants.job_type', 'regular'));

        $this->applySearch($model, $request, $statusFilter);

        if (!$request->ajax()) {
            return response()->json(['error' => 'Invalid request'], 400);
        }

        $jobTitleNamesById = JobTitle::pluck('name', 'id');
        $jobCategoryNamesById = JobCategory::pluck('name', 'id');

        return DataTables::eloquent($model)
            ->skipTotalRecords()
            ->order(fn($query) => $this->applySorting($query, $request))
            ->addIndexColumn()
            ->addColumn('user_name', function ($row) use ($statusFilter) {
                $this->hydrateLazyQualityDecisionData($row, $statusFilter);
                return ucwords($row->user_name ?? '') ?: '-';
            })
            ->filterColumn('users.name', function ($query, $keyword) use ($statusFilter) {
                if (in_array($statusFilter, ['rejected cvs', 'cleared cvs'], true)) {
                    return;
                }
                $query->whereRaw('LOWER(users.name) LIKE ?', ['%' . mb_strtolower($keyword) . '%']);
            })
            ->addColumn('job_title', fn($row) => $row->job_title_name ? strtoupper($row->job_title_name) : '-')
            ->addColumn('job_category', function ($row) {
                $type = $row->sale_job_type;

                $stype = $type === 'specialist'
                    ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                    : '';

                return $row->job_category_name ? ucwords($row->job_category_name) . $stype : '-';
            })
            ->addColumn('sale_job_source', function ($row) {
                return $row->sale_job_source_name
                    ? '<span class="badge bg-light text-dark">' . e(ucwords($row->sale_job_source_name)) . '</span>'
                    : '-';
            })
            ->filterColumn('sale_job_sources.name', function ($query, $keyword) {
                $query->whereRaw('LOWER(sale_job_sources.name) LIKE ?', ['%' . mb_strtolower($keyword) . '%']);
            })
            ->addColumn('applicant_name', function ($row) {
                $name = ucwords($row->applicant_name);
                return '<a class="dropdown-item" target="_blank" title="View Applicant History" href="' . route('applicants.history', ['id' => (int) $row->id]) . '">' . $name . '</a>';
            })
            ->addColumn('job_details', function ($applicant) use ($jobTitleNamesById, $jobCategoryNamesById) {
                $position_type = strtoupper(str_replace('-', ' ', $applicant->position_type ?? ''));
                $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text
                $status = '';
                if ($applicant->sale_status == 1) {
                    $status = '<span class="badge bg-success">Active</span>';
                } elseif ($applicant->sale_status == 0 && $applicant->is_on_hold == 0) {
                    $status = '<span class="badge bg-danger">Closed</span>';
                } elseif ($applicant->sale_status == 2) {
                    $status = '<span class="badge bg-warning">Pending</span>';
                } elseif ($applicant->sale_status == 3) {
                    $status = '<span class="badge bg-danger">Rejected</span>';
                }

                $sale_source_name = '';
                if ($applicant->sale_source_name) {
                    $sale_source_name = '<span class="badge bg-light text-dark">' . e($applicant->sale_source_name) . '</span>';
                }

                $postcode = strtoupper($applicant->sale_postcode);
                $posted_date = Carbon::parse($applicant->sale_posted_date)->format('d M Y, h:i A');
                $office_name = ucwords($applicant->office_name) ?? '-';
                $unit_name = ucwords($applicant->unit_name) ?? '-';
                $jobTitleNameValue = $jobTitleNamesById->get($applicant->sale_title_id);
                $jobTitle = $jobTitleNameValue ? strtoupper($jobTitleNameValue) : '-';
                $stype  = $applicant->sale_job_type && $applicant->sale_job_type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                $jobCategoryNameValue = $jobCategoryNamesById->get($applicant->sale_category_id);
                $jobCategory = $jobCategoryNameValue ? (ucwords($jobCategoryNameValue) . $stype) : '-';

                $jobData = [
                    'sale_id'       => (int)$applicant->sale_id,
                    'posted_date'   => $posted_date,
                    'office_name'   => $office_name,
                    'unit_name'     => $unit_name,
                    'postcode'      => $postcode,
                    'job_category'  => $jobCategory,
                    'job_title'     => $jobTitle,
                    'sale_source_name' => $sale_source_name,
                    'status'        => $status,       // RAW HTML
                    'timing'        => $applicant->timing,
                    'experience'    => $applicant->sale_experience,
                    'salary'        => $applicant->salary,
                    'position'      => $position,     // RAW HTML
                    'qualification' => $applicant->sale_qualification,
                    'benefits'      => $applicant->benefits,
                ];

                $url = route('sales.history', ['id' => (int) $applicant->sale_id]);
                if (Gate::allows('quality-assurance-sale-view')) {
                    return '<a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                        $jobData,
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                    ) . '\'>
                            <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>
                        
                        <a href="' . $url . '" target="_blank" title="View Sale History">
                            <iconify-icon icon="solar:history-bold" class="text-warning fs-24"></iconify-icon>
                        </a>';
                }
            })
            ->addColumn('applicantEmail', fn($row) => $this->renderApplicantEmail($row))
            ->filterColumn('applicantEmail', function ($query, $keyword) {
                $keyword = trim($keyword);
                $query->where('applicants.applicant_email', 'LIKE', "{$keyword}%")
                    ->orWhere('applicants.applicant_email_secondary', 'LIKE', "{$keyword}%");
            })
            ->editColumn('applicant_postcode', fn($row) => $this->renderApplicantPostcode($row))
            ->editColumn('sale_postcode', fn($row) => $this->renderSalePostcode($row))
            ->addColumn('notes_detail', function ($row) use ($statusFilter) {
                $this->hydrateLazyQualityDecisionData($row, $statusFilter);
                return $this->renderNotesDetail($row);
            })
            ->addColumn('applicantPhone', fn($row) => $this->renderApplicantPhone($row))
            ->filterColumn('applicantPhone', function ($query, $keyword) {
                $clean = preg_replace('/[^0-9]/', '', $keyword);
                $query->where(function ($q) use ($clean) {
                    $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                });
            })
            ->addColumn('notes_created_at', function ($row) use ($statusFilter) {
                $this->hydrateLazyQualityDecisionData($row, $statusFilter);
                return Carbon::parse($row->notes_created_at)->format('d M Y, h:iA');
            })
            ->editColumn('applicant_resume', fn($row) => CvStorage::linkCell($row->id, $row->applicant_cv, $row->is_blocked, 'original', 'success'))
            ->addColumn('crm_resume', fn($row) => CvStorage::linkCell($row->id, $row->updated_cv, $row->is_blocked, 'updated', 'primary'))
            ->addColumn('customStatus', fn($row) => $this->renderCustomStatus($row))
            ->addColumn('action', fn($row) => $this->renderActionMenu($row, $statusFilter))
            ->rawColumns(['notes_detail', 'applicant_name', 'notes_created_at', 'job_details', 'sale_postcode', 'applicantEmail', 'applicant_postcode', 'crm_resume', 'applicantPhone', 'job_title', 'applicant_resume', 'customStatus', 'job_category', 'sale_job_source', 'action'])
            ->make(true);
    }

    private function rankedPerApplicantSaleSubquery(
        string $table,
        array $columns,
        ?\Closure $where = null,
        string $direction = 'DESC',
        array $partitionBy = ['applicant_id', 'sale_id']
    ): Builder {
        $allowedPartitionColumns = ['applicant_id', 'sale_id'];
        $partitionBy = array_values(array_intersect($partitionBy, $allowedPartitionColumns)) ?: ['applicant_id', 'sale_id'];
        $partitionSql = implode(', ', $partitionBy);

        $query = DB::table($table)->select(array_merge($columns, [
            DB::raw("ROW_NUMBER() OVER (PARTITION BY {$partitionSql} ORDER BY id {$direction}) as rn"),
        ]));

        if ($where) {
            $where($query);
        }

        return DB::query()->fromSub($query, 'ranked')
            ->where('rn', 1)
            ->select($columns);
    }

    private function currentQualityHistorySubquery(array $targetSubStages, bool $latestPerApplicant = false): Builder
    {
        $decisionSubStages = ['quality_reject', 'quality_cleared', 'quality_cleared_no_job'];
        $partitionBy = $latestPerApplicant ? ['applicant_id'] : ['applicant_id', 'sale_id'];

        return $this->rankedPerApplicantSaleSubquery(
            'history',
            ['applicant_id', 'sale_id', 'sub_stage', 'created_at'],
            fn($q) => $q->whereIn('sub_stage', $decisionSubStages),
            'DESC',
            $partitionBy
        )->whereIn('sub_stage', $targetSubStages);
    }

    private function latestCvNoteByStatusSubquery(): Builder
    {
        return $this->rankedPerApplicantSaleSubquery(
            'cv_notes',
            ['applicant_id', 'sale_id', 'user_id', 'details', 'created_at'],
            fn($q) => $q->where('status', 1)
        );
    }

    private function latestHistorySubquery(array $subStages): Builder
    {
        return $this->rankedPerApplicantSaleSubquery(
            'history',
            ['applicant_id', 'sale_id'],
            fn($q) => $q->whereIn('sub_stage', $subStages)->where('status', 1)
        );
    }

    private function latestRevertStageSubquery(): Builder
    {
        return $this->rankedPerApplicantSaleSubquery(
            'revert_stages',
            ['applicant_id', 'sale_id', 'user_id', 'notes', 'stage', 'updated_at'],
            fn($q) => $q->whereIn('stage', ['quality_note', 'cv_hold', 'no_job_quality_cvs'])
        );
    }

    private function applyQualityDecisionFilter($model, array $historyStages, array $commonSaleSelect, bool $latestPerApplicant = false)
    {
        $saleSelect = array_values(array_filter(
            $commonSaleSelect,
            fn($column) => $column !== 'users.name as user_name'
        ));

        return $model
            ->joinSub($this->currentQualityHistorySubquery($historyStages, $latestPerApplicant), 'lh', fn($j) => $j
                ->on('applicants.id', '=', 'lh.applicant_id'))
            ->leftJoin('sales', 'sales.id', '=', 'lh.sale_id')
            ->leftJoin('offices', 'offices.id', '=', 'sales.office_id')
            ->leftJoin('units', 'units.id', '=', 'sales.unit_id')
            ->addSelect(array_merge($saleSelect, [
                'lh.sale_id as cvnote_sale_id',
                'lh.created_at as notes_created_at',
                'sales.job_title_id as sale_title_id',
                'sales.job_category_id as sale_category_id',
            ]));
    }

    /** quality_notes.moved_tab_to values whose latest note the cleared/rejected tabs display. */
    private function qualityDecisionNoteStatuses(string $statusFilter): array
    {
        return $statusFilter === 'rejected cvs' ? ['rejected'] : ['cleared', 'cleared_no_job'];
    }

    /**
     * Cleared/rejected tabs load their note per row (hydrateLazyQualityDecisionData), so hide
     * rows whose note contains a private word here in SQL: the same latest matching quality note.
     */
    private function excludePrivateQualityDecisionNotes($model, string $statusFilter): void
    {
        if (!in_array($statusFilter, ['rejected cvs', 'cleared cvs'], true)) {
            return;
        }

        $noteStatuses = $this->qualityDecisionNoteStatuses($statusFilter);

        foreach ($this->privateNoteLikePatterns() as $like) {
            $model->whereNotExists(function ($q) use ($noteStatuses, $like) {
                $q->select(DB::raw(1))
                    ->from('quality_notes as private_qn')
                    ->whereColumn('private_qn.applicant_id', 'applicants.id')
                    ->whereColumn('private_qn.sale_id', 'lh.sale_id')
                    ->where('private_qn.details', 'LIKE', $like)
                    ->where('private_qn.id', function ($latest) use ($noteStatuses) {
                        $latest->selectRaw('MAX(latest_qn.id)')
                            ->from('quality_notes as latest_qn')
                            ->whereColumn('latest_qn.applicant_id', 'private_qn.applicant_id')
                            ->whereColumn('latest_qn.sale_id', 'private_qn.sale_id')
                            ->whereIn('latest_qn.moved_tab_to', $noteStatuses);
                    });
            });
        }
    }

    private function hydrateLazyQualityDecisionData($applicant, string $statusFilter): void
    {
        if (!in_array($statusFilter, ['rejected cvs', 'cleared cvs'], true)) {
            return;
        }

        if ($applicant->getAttribute('_lazy_decision_loaded')) {
            return;
        }

        $applicant->setAttribute('_lazy_decision_loaded', true);
        $applicant->notes_detail = '';

        $noteStatuses = $this->qualityDecisionNoteStatuses($statusFilter);

        $note = DB::table('quality_notes')
            ->where('applicant_id', $applicant->id)
            ->where('sale_id', $applicant->cvnote_sale_id)
            ->whereIn('moved_tab_to', $noteStatuses)
            ->orderByDesc('id')
            ->first(['details', 'created_at']);

        if ($note) {
            $applicant->notes_detail = $note->details ?? '';
            $applicant->notes_created_at = $note->created_at;
        }

        if ($statusFilter === 'cleared cvs') {
            $cvNoteUserId = DB::table('cv_notes')
                ->where('applicant_id', $applicant->id)
                ->where('sale_id', $applicant->cvnote_sale_id)
                ->orderByDesc('id')      // latest cv_notes record
                ->value('user_id');
        } else {
            $cvNoteUserId = DB::table('cv_notes')
                ->where('applicant_id', $applicant->id)
                ->where('sale_id', $applicant->cvnote_sale_id)
                ->orderBy('id')      // latest cv_notes record
                ->value('user_id');
        }

        $applicant->user_name = $cvNoteUserId
            ? User::where('id', $cvNoteUserId)->value('name')
            : null;
    }

    private function applyStatusFilterJoins($model, string $statusFilter, array $commonSaleSelect): void
    {
        match ($statusFilter) {
            'open cvs' => $model
                ->joinSub($this->latestCvNoteByStatusSubquery(), 'lcn', fn($j) => $j->on('applicants.id', '=', 'lcn.applicant_id'))
                ->join('sales', 'sales.id', '=', 'lcn.sale_id')
                ->join('offices', 'offices.id', '=', 'sales.office_id')
                ->join('units', 'units.id', '=', 'sales.unit_id')
                ->joinSub($this->latestHistorySubquery(['quality_cvs_hold']), 'lh', fn($j) => $j
                    ->on('lcn.applicant_id', '=', 'lh.applicant_id')
                    ->on('lcn.sale_id', '=', 'lh.sale_id'))
                ->joinSub($this->latestRevertStageSubquery(), 'lrs', fn($j) => $j
                    ->on('applicants.id', '=', 'lrs.applicant_id')
                    ->on('sales.id', '=', 'lrs.sale_id'))
                ->join('users', 'users.id', '=', 'lrs.user_id')
                ->addSelect(array_merge($commonSaleSelect, [
                    'lrs.notes as notes_detail',
                    'lrs.stage as revert_stage',
                    'lrs.updated_at as notes_created_at',
                    'lcn.sale_id as cvnote_sale_id',
                    'sales.job_title_id as sale_title_id',
                    'sales.job_category_id as sale_category_id',
                ])),

            'no job cvs' => $model
                ->joinSub($this->latestCvNoteByStatusSubquery(), 'lcn', fn($j) => $j->on('applicants.id', '=', 'lcn.applicant_id'))
                ->join('sales', 'sales.id', '=', 'lcn.sale_id')
                ->join('offices', 'offices.id', '=', 'sales.office_id')
                ->join('units', 'units.id', '=', 'sales.unit_id')
                ->joinSub($this->latestHistorySubquery(['no_job_quality_cvs']), 'lh', fn($j) => $j
                    ->on('lcn.applicant_id', '=', 'lh.applicant_id')
                    ->on('lcn.sale_id', '=', 'lh.sale_id'))
                ->join('users', 'users.id', '=', 'lcn.user_id')
                ->addSelect(array_merge($commonSaleSelect, [
                    'lcn.details as notes_detail',
                    'lcn.created_at as notes_created_at',
                    'lcn.sale_id as cvnote_sale_id',
                    'sales.job_title_id as sale_title_id',
                    'sales.job_category_id as sale_category_id',
                ])),

            'rejected cvs' => $this->applyQualityDecisionFilter(
                $model,
                ['quality_reject'],
                $commonSaleSelect
            ),

            'cleared cvs' => $this->applyQualityDecisionFilter(
                $model,
                ['quality_cleared', 'quality_cleared_no_job'],
                $commonSaleSelect
            ),

            'requested cvs' => $model
                ->joinSub($this->latestCvNoteByStatusSubquery(), 'lcn', fn($j) => $j->on('applicants.id', '=', 'lcn.applicant_id'))
                ->join('sales', 'sales.id', '=', 'lcn.sale_id')
                ->join('offices', 'offices.id', '=', 'sales.office_id')
                ->join('units', 'units.id', '=', 'sales.unit_id')
                ->joinSub($this->latestHistorySubquery(['quality_cvs']), 'lh', fn($j) => $j
                    ->on('lcn.applicant_id', '=', 'lh.applicant_id')
                    ->on('lcn.sale_id', '=', 'lh.sale_id'))
                ->join('users', 'users.id', '=', 'lcn.user_id')
                ->addSelect(array_merge($commonSaleSelect, [
                    'lcn.details as notes_detail',
                    'lcn.created_at as notes_created_at',
                    'lcn.sale_id as cvnote_sale_id',
                    'sales.job_title_id as sale_title_id',
                    'sales.job_category_id as sale_category_id',
                ])),

            default => $model
                ->joinSub($this->latestCvNoteByStatusSubquery(), 'lcn', fn($j) => $j->on('applicants.id', '=', 'lcn.applicant_id'))
                ->join('sales', 'sales.id', '=', 'lcn.sale_id')
                ->join('offices', 'offices.id', '=', 'sales.office_id')
                ->join('units', 'units.id', '=', 'sales.unit_id')
                ->joinSub($this->latestHistorySubquery(['quality_cvs']), 'lh', fn($j) => $j
                    ->on('lcn.applicant_id', '=', 'lh.applicant_id')
                    ->on('lcn.sale_id', '=', 'lh.sale_id'))
                ->join('users', 'users.id', '=', 'lcn.user_id')
                ->addSelect(array_merge($commonSaleSelect, [
                    'lcn.details as notes_detail',
                    'lcn.created_at as notes_created_at',
                    'lcn.sale_id as cvnote_sale_id',
                    'sales.job_title_id as sale_title_id',
                    'sales.job_category_id as sale_category_id',
                ])),
        };
    }

    private function allowedQualityResourceTabs(): array
    {
        if (!Auth::check()) {
            return [];
        }

        $tabs = [];
        foreach (self::QUALITY_RESOURCE_TABS as $status => $tab) {
            if (Gate::allows($tab['permission'])) {
                $tabs[$status] = $tab['label'];
            }
        }

        return $tabs;
    }

    private function applySorting($model, Request $request): void
    {
        $orderColumn    = null;
        $orderDirection = 'asc';

        if ($request->has('order')) {
            $orderColumn    = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
        }

        match (true) {
            $orderColumn === 'sale_job_source' => $model->orderBy('sales.job_source_id', $orderDirection),
            $orderColumn === 'job_source'      => $model->orderBy('sales.job_source_id', $orderDirection),
            $orderColumn === 'job_category'    => $model->orderBy('applicants.job_category_id', $orderDirection),
            $orderColumn === 'job_title'       => $model->orderBy('applicants.job_title_id', $orderDirection),
            $orderColumn && $orderColumn !== 'DT_RowIndex' => $model->orderBy($orderColumn, $orderDirection),
            default => $model->orderBy('notes_created_at', 'desc'),
        };
    }

    private function applySearch($model, Request $request, string $statusFilter = ''): void
    {
        $searchTerm = trim((string) $request->input('search.value', ''));

        if ($searchTerm === '') {
            return;
        }

        // "Rejected CVs" / "Cleared CVs" don't join `users` in SQL anymore
        // (see applyQualityDecisionFilter's doc comment) — the "sent by"
        // user is resolved lazily per displayed row instead, so it can't be
        // searched at the SQL level for these two filters.
        $hasUsersJoin = !in_array($statusFilter, ['rejected cvs', 'cleared cvs'], true);

        // NOTE: all four whereHas() calls removed. job_titles, job_categories,
        // sale_job_sources, and users are already LEFT/INNER JOINed into the
        // query — filtering their already-aliased columns directly avoids four
        // redundant correlated EXISTS subqueries per search request.
        $model->where(function ($query) use ($searchTerm, $hasUsersJoin) {
            $query->where('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                ->orWhere('applicants.applicant_email', 'LIKE', "%{$searchTerm}%")
                ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%")
                ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                ->orWhere('applicants.applicant_experience', 'LIKE', "%{$searchTerm}%")
                ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%")
                ->orWhereRaw('LOWER(sales.sale_postcode) LIKE ?', ["%{$searchTerm}%"])
                ->orWhereRaw('LOWER(offices.office_name) LIKE ?', ["%{$searchTerm}%"])
                ->orWhereRaw('LOWER(units.unit_name) LIKE ?', ["%{$searchTerm}%"])
                ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                ->orWhere('sale_job_sources.name', 'LIKE', "%{$searchTerm}%");

            if ($hasUsersJoin) {
                $query->orWhere('users.name', 'LIKE', "%{$searchTerm}%");
            }
        });
    }

    private function renderApplicantEmail($applicant): string
    {
        $email = $applicant->applicant_email ?? '';
        $secondaryEmail = $applicant->applicant_email_secondary ?? '';

        if ($email && $secondaryEmail) {
            return e($email) . '<br>' . e($secondaryEmail);
        }

        return e($email ?: $secondaryEmail ?: '-');
    }

    private function renderApplicantPostcode($applicant): string
    {
        $status_value = 'open';
        if ($applicant->paid_status == 'close') {
            $status_value = 'paid';
        } else {
            foreach ($applicant->cv_notes as $key => $value) {
                if ($value->status == 'active') {
                    $status_value = 'sent';
                    break;
                } elseif ($value->status == 'disable') {
                    $status_value = 'reject';
                }
            }
        }

        $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                        data-postcode="' . e($applicant->applicant_postcode) . '" title="Copy Postcode">
                                        <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                    </button>';

        if ($applicant->lat != null && $applicant->lng != null && $status_value == 'open' || $status_value == 'reject' && !$applicant->is_blocked) {
            $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
            $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $applicant->formatted_postcode . '</a>';
            return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
        } else {
            return '<div class="d-flex align-items-center justify-content-between"><span>' . $applicant->formatted_postcode . '</span>' . $copyBtn . '</div>';
        }
    }

    private function renderSalePostcode($applicant): string
    {
        $salePostcode = strtoupper($applicant->sale_postcode);
        $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($salePostcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';


        return '<div class="d-flex align-items-center justify-content-between"><span>' . $salePostcode . '</span>' . $copyBtn . '</div>';
    }

    private function renderNotesDetail($applicant): string
    {
        $fullHtml = $applicant->notes_detail; // HTML from Summernote
        $id = 'qua-' . $applicant->id . "-" . $applicant->cvnote_sale_id;
        $copyId = "copy-quality-resources-notes-" . $applicant->id . "-" . $applicant->cvnote_sale_id;

        // 1. Preserve line breaks from Summernote before removing its markup
        $withBreaks = preg_replace(
            '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
            "\n",
            (string) $fullHtml
        );
        $plainText = strip_tags($withBreaks);
        $plainText = html_entity_decode($plainText); // decode &nbsp; &amp; etc
        $plainText = preg_replace("/[\r\n]+/", "\n", $plainText); // normalize newlines
        $plainText = trim($plainText);

        // 2. Generate short preview (first 100 chars) for table
        $shortPreview = Str::limit($plainText, 100);
        $shortPreviewHtml = nl2br(e($shortPreview)); // preserve line breaks safely

        return '
        <div>
            <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#' . $id . '">
                ' . $shortPreviewHtml . '
            </a>
            <br>

            <!-- Hidden full plain text for copy -->
            <div id="' . $copyId . '" class="d-none">' . e($plainText) . '</div>

            <!-- Copy button under short note -->
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2 copy-quality-resource-notes-btn" data-copy-quality-resource-notes-target="#' . $copyId . '">
                Copy Notes
            </button>
        </div>

        <!-- Modal showing full formatted HTML notes -->
        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" style="color:#5d7186" id="' . $id . '-label">Notes Detail</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="color:#5d7186">
                        ' . $fullHtml . '
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>';
    }

    private function renderApplicantPhone($applicant): string
    {
        if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
            return "<span class='badge bg-dark'>Blocked</span>";
        }

        $showBlockedData = $applicant->is_blocked
            && Gate::allows('applicant-show-blocked-data');

        $class = $showBlockedData ? 'show_hidden_phone' : '';

        $parts = [];

        if (!empty($applicant->applicant_phone)) {
            $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
        }

        if (!empty($applicant->applicant_phone_secondary)) {
            $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
        }

        if (!empty($applicant->applicant_landline)) {
            $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
        }

        $phones = implode('<br>', $parts) ?: '-';

        // Only blocked + permission gets black background + white text
        if ($showBlockedData) {
            return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                . $phones
                . '</div>';
        }

        return $phones;
    }

    private function renderCustomStatus($applicant): string
    {
        $status_value = 'open';
        $color_class = 'bg-success';
        if ($applicant->paid_status == 'close') {
            $status_value = 'paid';
            $color_class = 'bg-info';
        } else {
            foreach ($applicant->cv_notes as $key => $value) {
                if ($value->status == 'active') {
                    $status_value = 'sent';
                    $color_class = 'bg-success';
                    break;
                } elseif ($value->status == 'disable') {
                    $status_value = 'reject';
                    $color_class = 'bg-danger';
                }
            }
        }

        $status = '';
        $status .= '<span class="badge ' . $color_class . '">';
        $status .= strtoupper($status_value);
        $status .= '</span>';
        return $status;
    }

    private function renderActionMenu($row, $statusFilter)
    {
        $html = '<div class="btn-group dropstart"> 
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"> 
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon> </button> 
                                <ul class="dropdown-menu">';

        $position_type = strtoupper(str_replace('-', ' ', $row->position_type ?? ''));
        $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text

        // sale status
        if ($row->sale_status == 1) {
            $status = '<span class="badge bg-success">Active</span>';
        } elseif ($row->sale_status == 0 && $row->is_on_hold == 0) {
            $status = '<span class="badge bg-danger">Closed</span>';
        } elseif ($row->sale_status == 2) {
            $status = '<span class="badge bg-warning">Pending</span>';
        } elseif ($row->sale_status == 3) {
            $status = '<span class="badge bg-danger">Rejected</span>';
        } else {
            $status = '<span class="badge bg-secondary">Unknown</span>';
        }

        $saleJobTitle = JobTitle::where('id', $row->sale_title_id)->first('name');
        $saleJobCategory = JobCategory::where('id', $row->sale_category_id)->first('name');

        $jobData = [
            'sale_id'       => (int) $row->sale_id,
            'office_name'   => ucwords($row->office_name ?? ''),
            'unit_name'     => ucwords($row->unit_name ?? ''),
            'postcode'      => strtoupper($row->sale_postcode ?? ''),
            'job_category'  => ucwords($saleJobCategory->name ?? ''),
            'job_title'     => strtoupper($saleJobTitle->name ?? ''),
            'job_source'    => ucwords($row->sale_job_source_name ?? ''),
            'status'        => $status,       // RAW HTML
            'timing'        => $row->timing ?? '',
            'experience'    => $row->sale_experience ?? '',
            'salary'        => $row->salary ?? '',
            'position'      => $position,     // RAW HTML
            'qualification' => $row->sale_qualification ?? '',
            'benefits'      => $row->benefits ?? '',
        ];

        $html .= '<li>
                        <a href="javascript:void(0);"
                        class="dropdown-item job-details"
                        data-job=\'' . json_encode(
            $jobData,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) . '\'>
                        Job Details
                        </a>
                    </li>';


        // Status-specific actions
        switch ($statusFilter) {
            case 'active cvs':
                if (Gate::allows('quality-assurance-resource-clear-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"cleared\", \"Mark Clear CV\")'>Mark Clear CV</a></li>";
                }
                if (Gate::allows('quality-assurance-resource-reject-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                }
                if (Gate::allows('quality-assurance-resource-open-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"opened\", \"Mark Open CV\")'>Mark Open CV</a></li>";
                }
                break;
            case 'open cvs':
                if (Gate::allows('quality-assurance-resource-revert-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ",\"revert\", \"Mark Revert CV\")'>Mark Revert CV</a></li>";
                }
                if (Gate::allows('quality-assurance-resource-reject-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ",\"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                }
                break;
            case 'no job cvs':
                if (Gate::allows('quality-assurance-resource-clear-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"cleared_no_job\", \"Mark Clear CV\")'>Mark Clear CV</a></li>";
                }
                if (Gate::allows('quality-assurance-resource-reject-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                }
                break;
            case 'rejected cvs':
                if (Gate::allows('quality-assurance-resource-revert-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"revert\", \"Mark Revert As Active\")'>Mark Revert As Active</a></li>";
                }
                break;
            case 'cleared cvs':
                $html .= "";
                break;
            default:
                if (Gate::allows('quality-assurance-resource-clear-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"cleared\", \"Mark Clear CV\")'>Mark Clear CV</a></li>";
                }
                if (Gate::allows('quality-assurance-resource-reject-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                }
                if (Gate::allows('quality-assurance-resource-open-cv')) {
                    $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(" . (int)$row->id . ", " . (int)$row->sale_id . ", \"opened\", \"Mark Open CV\")'>Mark Open CV</a></li>";
                }
                break;
        }
        if (Gate::allows('quality-assurance-resource-upload-resume')) {
            $html .= '<li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="triggerCrmFileInput(' . (int)$row->id . ')">Upload CRM Resume</a>
                                    <!-- Hidden File Input -->
                                    <input type="file" id="crmfileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="crmuploadFile()">
                                </li>';
        }
        // Common actions
        if (Gate::allows('applicant-view-history') || Gate::allows('applicant-view-notes-history')) {
            $html .= '<li><hr class="dropdown-divider"></li>';
        }
        if (Gate::allows('applicant-view-history')) {
            $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . (int)$row->id . ', ' . (int)$row->sale_id . ')">Notes History</a></li>';
        }
        if (Gate::allows('applicant-view-notes-history')) {
            $html .= '<li><a class="dropdown-item manager-details-link" href="javascript:void(0);" data-manager-unit-id="' . (int)$row->sale_unit_id . '" onclick="viewManagerDetails(' . (int)$row->sale_unit_id . ')">Manager Details</a></li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    /**** sale part */
    public function getSalesByTypeAjaxRequest_old(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',

                // ADD THESE — fields from latest sale note
                'updated_notes.id as latest_note_id',
                'updated_notes.sale_note as latest_note',
                'updated_notes.created_at as latest_note_time',
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')

            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')
            ->selectRaw(DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) as no_of_sent_cv"));

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    $likeSearch = "%{$searchTerm}%";

                    $query->whereRaw('LOWER(sales.sale_postcode) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.experience) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.timing) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_description) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.position_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.cv_limit) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.salary) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.benefits) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.qualification) LIKE ?', [$likeSearch]);

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($likeSearch) {
                        $q->where('job_titles.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($likeSearch) {
                        $q->where('job_categories.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('unit', function ($q) use ($likeSearch) {
                        $q->where('units.unit_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by status if it's not empty
        switch ($statusFilter) {
            case 'requested sales':
                $model->where(function ($query) {
                    $query->where('sales.status', 2)
                        /**1=open, 2=pending */
                        ->orWhere('is_re_open', 2);
                    /** re-open requested */
                });
                break;

            case 'rejected sales':
                $model->where('sales.status', 3);
                /**rejected */
                break;

            case 'cleared sales':
                $model->whereIn('sales.status', [0, 1]);
                /**0=disabled,1=active */
                break;
            default:
                $model->where(function ($query) {
                    $query->where('sales.status', 2)
                        /**1=open, 2=pending */
                        ->orWhere('is_re_open', 2);
                    /** re-open requested */
                });
                break;
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        // Filter by category if it's not empty
        if ($limitCountFilter) {
            if ($limitCountFilter == 'zero') {
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
            } elseif ($limitCountFilter == 'not max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
            } elseif ($limitCountFilter == 'max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
            }
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? ucwords($office->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? ucwords($unit->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('qualification', function ($sale) {
                    $fullHtml = $sale->qualification; // HTML from Summernote
                    $id = 'qua-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Qualification</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('experience', function ($sale) {
                    $fullHtml = $sale->experience; // HTML from Summernote
                    $id = 'exp-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('salary', function ($sale) {
                    $fullHtml = $sale->salary; // HTML from Summernote
                    $id = 'slry-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale`s Salary</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $rawPostcode = trim($sale->sale_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $sale->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = $sale->sale_notes ?: $sale->latest_note;

                    $id = 'note-' . $sale->id;
                    $copyId = "quality-sales-copy-notes-" . $sale->id;

                    // 1. Convert HTML to readable plain text for copying
                    $plainText = strip_tags($notesIndex); // remove all HTML
                    $plainText = html_entity_decode($plainText); // decode &nbsp; &amp; etc
                    $plainText = preg_replace("/[\r\n]+/", "\n", $plainText); // normalize newlines
                    $plainText = trim($plainText);

                    // 2. Generate short preview (first 200 chars) for table
                    $shortPreview = Str::limit($plainText, 200);
                    $shortPreviewHtml = nl2br(e($shortPreview));

                    return '
                    <div>
                        <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#' . $id . '">
                            ' . $shortPreviewHtml . '
                        </a>
                        <br>

                        <!-- Hidden full plain text for copy -->
                        <div id="' . $copyId . '" class="d-none">' . e($plainText) . '</div>

                        <!-- Copy button under short note -->
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2 copy-quality-sales-notes-btn" data-copy-quality-sales-notes-target="#' . $copyId . '">
                            Copy Notes
                        </button>
                    </div>

                    <!-- Modal showing full formatted HTML notes -->
                    <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="' . $id . '-label">Notes Detail</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    ' . $notesIndex . '
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>';
                })
                ->addColumn('job_details', function ($sale) {
                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text
                    $status = '';
                    if ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 0 && $sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';

                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    $jobData = [
                        'sale_id'       => (int)$sale->id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $jobCategory,
                        'job_title'     => $jobTitle,
                        'status'        => $status,       // RAW HTML
                        'timing'        => $sale->timing,
                        'experience'    => $sale->experience,
                        'salary'        => $sale->salary,
                        'position'      => $position,     // RAW HTML
                        'qualification' => $sale->qualification,
                        'benefits'      => $sale->benefits,
                    ];

                    if (Gate::allows('quality-assurance-sale-view')) {
                        return '<a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                            $jobData,
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                        ) . '\'>
                            <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                            </a>';
                    }
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Open</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($sale) use ($statusFilter) {
                    $action = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">';

                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text
                    $status = '';
                    if ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 0 && $sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';

                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    $jobData = [
                        'sale_id'       => (int)$sale->id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $jobCategory,
                        'job_title'     => $jobTitle,
                        'status'        => $status,       // RAW HTML
                        'timing'        => $sale->timing,
                        'experience'    => $sale->experience,
                        'salary'        => $sale->salary,
                        'position'      => $position,     // RAW HTML
                        'qualification' => $sale->qualification,
                        'benefits'      => $sale->benefits,
                    ];
                    if (Gate::allows('sale-edit')) {
                        $action .= '<li><a class="dropdown-item" href="' . route('sales.edit', ['id' => (int)$sale->id]) . '">Edit</a></li>';
                    }
                    if (Gate::allows('quality-assurance-sale-view')) {
                        $action .= '<li>
                            <a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                            $jobData,
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                        ) . '\'>
                            View Details
                            </a>
                        </li>';
                    }

                    // Filter by status if it's not empty
                    switch ($statusFilter) {
                        case 'active sales':
                            // Filter by status if it's not empty
                            if (in_array($sale->status, [1, 2]) || $sale->is_re_open == true) {
                                if (Gate::allows('quality-assurance-sale-change-status')) {
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'clear\')">Mark Clear Sale</a></li>';
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'reject\')">Mark Reject Sale</a></li>';
                                }
                            }
                            break;

                        case 'rejected sales':
                            $action .= '';
                            break;

                        case 'cleared sales':
                            $action .= '';
                            break;
                        default:
                            // Filter by status if it's not empty
                            if (in_array($sale->status, [1, 2]) || $sale->is_re_open == true) {
                                if (Gate::allows('quality-assurance-sale-change-status')) {
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'clear\')">Mark Clear Sale</a></li>';
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'reject\')">Mark Reject Sale</a></li>';
                                }
                            }
                            break;
                    }

                    $action .= '<li><hr class="dropdown-divider"></li>';

                    $url = route('sales.history', ['id' => (int) $sale->id]);
                    $action .= '<li><a class="dropdown-item" target="_blank" href="' . $url . '">View History</a></li>';

                    if (Gate::allows('quality-assurance-sale-view-documents')) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>';
                    }

                    if (Gate::allows('quality-assurance-sale-view-notes-history')) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>';
                    }
                    if (Gate::allows('quality-assurance-sale-manager-details')) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>';
                    }
                    $action .= '</ul>
                        </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'job_details', 'sale_postcode', 'experience', 'salary', 'qualification', 'cv_limit', 'open_date', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getSalesByTypeAjaxRequest(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', []); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', []); // Default is empty (no filter)
        $sourceFilter = $request->input('source_filter', []); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', []); // Default is empty (no filter)

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',

                // ADD THESE — fields from latest sale note
                'updated_notes.id as latest_note_id',
                'updated_notes.sale_note as latest_note',
                'updated_notes.created_at as latest_note_time',

                'job_sources.name as job_source_name',
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')

            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')
            ->leftJoin('job_sources', 'sales.job_source_id', '=', 'job_sources.id')
            ->selectRaw(DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) as no_of_sent_cv"));

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // Hide sales whose office/unit notes contain a private word (users without show-private-data).
        $this->excludePrivateOfficeUnitNotes($model);

        // Hide sales whose notes (as shown in the Notes column) contain a private word:
        // sales.sale_notes, or the latest sale_notes row when that is empty.
        $this->excludePrivateNotes($model, DB::raw("COALESCE(NULLIF(sales.sale_notes, ''), updated_notes.sale_note)"));

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    $likeSearch = "%{$searchTerm}%";

                    $query->whereRaw('LOWER(sales.sale_postcode) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.experience) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.timing) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_description) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.position_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.cv_limit) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.salary) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.benefits) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.qualification) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(job_sources.name) LIKE ?', [$likeSearch]);

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($likeSearch) {
                        $q->where('job_titles.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($likeSearch) {
                        $q->where('job_categories.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('unit', function ($q) use ($likeSearch) {
                        $q->where('units.unit_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by status if it's not empty
        switch ($statusFilter) {
            case 'requested sales':
                $model->where(function ($query) {
                    $query->where('sales.status', 2)
                        /**1=open, 2=pending */
                        ->orWhere('is_re_open', 2);
                    /** re-open requested */
                });
                break;

            case 'rejected sales':
                $model->where('sales.status', 3);
                /**rejected */
                break;

            case 'cleared sales':
                $model->whereIn('sales.status', [0, 1]);
                /**0=disabled,1=active */
                break;
            default:
                $model->where(function ($query) {
                    $query->where('sales.status', 2)
                        /**1=open, 2=pending */
                        ->orWhere('is_re_open', 2);
                    /** re-open requested */
                });
                break;
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }

        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        // Filter by category if it's not empty
        if ($limitCountFilter) {
            if ($limitCountFilter == 'zero') {
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
            } elseif ($limitCountFilter == 'not max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
            } elseif ($limitCountFilter == 'max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
            }
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Filter by source if it's not empty
        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', $sourceFilter);
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? ucwords($office->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? ucwords($unit->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('qualification', function ($sale) {
                    $fullHtml = $sale->qualification; // HTML from Summernote
                    $id = 'qua-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Qualification</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('experience', function ($sale) {
                    $fullHtml = $sale->experience; // HTML from Summernote
                    $id = 'exp-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('salary', function ($sale) {
                    $fullHtml = $sale->salary; // HTML from Summernote
                    $id = 'slry-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale`s Salary</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($sale) {
                    return $sale->job_source_name
                        ? '<span class="badge bg-light text-dark">' . e(ucwords($sale->job_source_name)) . '</span>'
                        : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $rawPostcode = trim($sale->sale_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $sale->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = $sale->sale_notes ?: $sale->latest_note;

                    $id = 'note-' . $sale->id;
                    $copyId = "quality-sales-copy-notes-" . $sale->id;

                    // 1. Convert HTML to readable plain text for copying
                    $plainText = strip_tags($notesIndex); // remove all HTML
                    $plainText = html_entity_decode($plainText); // decode &nbsp; &amp; etc
                    $plainText = preg_replace("/[\r\n]+/", "\n", $plainText); // normalize newlines
                    $plainText = trim($plainText);

                    // 2. Generate short preview (first 200 chars) for table
                    $shortPreview = Str::limit($plainText, 200);
                    $shortPreviewHtml = nl2br(e($shortPreview));

                    return '
                    <div>
                        <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#' . $id . '">
                            ' . $shortPreviewHtml . '
                        </a>
                        <br>

                        <!-- Hidden full plain text for copy -->
                        <div id="' . $copyId . '" class="d-none">' . e($plainText) . '</div>

                        <!-- Copy button under short note -->
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2 copy-quality-sales-notes-btn" data-copy-quality-sales-notes-target="#' . $copyId . '">
                            Copy Notes
                        </button>
                    </div>

                    <!-- Modal showing full formatted HTML notes -->
                    <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="' . $id . '-label">Notes Detail</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    ' . $notesIndex . '
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>';
                })
                ->addColumn('job_details', function ($sale) {
                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text
                    $status = '';
                    if ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 0 && $sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';

                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    $jobData = [
                        'sale_id'       => (int)$sale->id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $jobCategory,
                        'job_title'     => $jobTitle,
                        'status'        => $status,       // RAW HTML
                        'timing'        => $sale->timing,
                        'experience'    => $sale->experience,
                        'salary'        => $sale->salary,
                        'position'      => $position,     // RAW HTML
                        'qualification' => $sale->qualification,
                        'benefits'      => $sale->benefits,
                    ];

                    $url = route('sales.history', ['id' => (int) $sale->id]);
                    if (Gate::allows('quality-assurance-sale-view')) {
                        return '<a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                            $jobData,
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                        ) . '\'>
                            <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>
                        
                        <a href="' . $url . '" target="_blank" title="View Sale History">
                            <iconify-icon icon="solar:history-bold" class="text-warning fs-24"></iconify-icon>
                        </a>';
                    }
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Open</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($sale) use ($statusFilter) {
                    $action = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">';

                    $position_type = strtoupper(str_replace('-', ' ', $sale->position_type ?? ''));
                    $position = '<span class="badge bg-primary">' . e($position_type) . '</span>'; // only escape text
                    $status = '';
                    if ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 0 && $sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';

                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    $jobData = [
                        'sale_id'       => (int)$sale->id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $jobCategory,
                        'job_title'     => $jobTitle,
                        'status'        => $status,       // RAW HTML
                        'timing'        => $sale->timing,
                        'experience'    => $sale->experience,
                        'salary'        => $sale->salary,
                        'position'      => $position,     // RAW HTML
                        'qualification' => $sale->qualification,
                        'benefits'      => $sale->benefits,
                    ];
                    if (Gate::allows('sale-edit')) {
                        $action .= '<li><a class="dropdown-item" href="' . route('sales.edit', ['id' => (int)$sale->id]) . '">Edit</a></li>';
                    }
                    if (Gate::allows('quality-assurance-sale-view')) {
                        $action .= '<li>
                            <a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                            $jobData,
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                        ) . '\'>
                            View Details
                            </a>
                        </li>';
                    }

                    // Filter by status if it's not empty
                    switch ($statusFilter) {
                        case 'active sales':
                            // Filter by status if it's not empty
                            if (in_array($sale->status, [1, 2]) || $sale->is_re_open == true) {
                                if (Gate::allows('quality-assurance-sale-change-status')) {
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'clear\')">Mark Clear Sale</a></li>';
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'reject\')">Mark Reject Sale</a></li>';
                                }
                            }
                            break;

                        case 'rejected sales':
                            $action .= '';
                            break;

                        case 'cleared sales':
                            $action .= '';
                            break;
                        default:
                            // Filter by status if it's not empty
                            if (in_array($sale->status, [1, 2]) || $sale->is_re_open == true) {
                                if (Gate::allows('quality-assurance-sale-change-status')) {
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'clear\')">Mark Clear Sale</a></li>';
                                    $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeSaleStatus(' . $sale->id . ', \'reject\')">Mark Reject Sale</a></li>';
                                }
                            }
                            break;
                    }

                    $action .= '<li><hr class="dropdown-divider"></li>';

                    if (Gate::allows('quality-assurance-sale-view-documents')) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>';
                    }
                    if (Gate::allows('quality-assurance-sale-view-notes-history')) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>';
                    }
                    if (Gate::allows('quality-assurance-sale-manager-details')) {
                        $action .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>';
                    }
                    $action .= '</ul>
                        </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'job_source', 'job_details', 'sale_postcode', 'experience', 'salary', 'qualification', 'cv_limit', 'open_date', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function clearRejectSale(Request $request)
    {
        $user = Auth::user();

        $id = $request->input('sale_id');
        $notes = $request->input('details') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');
        $status = $request->input('status');

        try {
            $sale = Sale::findOrFail($id);

            // Validate and determine new status value
            $status_value = null;
            if ($status === 'clear') {
                $status_value = 1;
            } elseif ($status === 'reject') {
                $status_value = 3;
            }

            // Update sale based on status
            if ($sale->status == 1) {
                if ($status === 'reject') {
                    $sale->update(['status' => 3]);
                } else {
                    $sale->update(['is_re_open' => 1]);
                }
            } else {
                $sale->update([
                    'status' => ($status == 'clear') ? 1 : 3
                ]);
            }

            // Disable previous module note
            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Sale'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $notes,
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Sale',
                'user_id' => $user->id,
            ]);

            $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

            // Log audit
            $audit = new ActionObserver();
            $audit->changeSaleStatus($sale, ['status' => $status_value]);

            // Invalidate existing notes
            SaleNote::where('sale_id', $sale->id)
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new note and update UID
            $sale_note = new SaleNote([
                'sale_id' => $id,
                'user_id' => $user->id,
                'sale_note' => $notes,
            ]);
            $sale_note->save();

            $sale_note->sales_notes_uid = md5($sale_note->id);
            $sale_note->save();

            return response()->json([
                'success' => true,
                'message' => 'Sale status changed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An error occurred while updating the sale. Please try again.'
            ], 500);
        }
    }
    public function updateApplicantStatusByQuality(Request $request)
    {
        $applicant_id = $request->input('applicant_id');
        $sale_id = $request->input('sale_id');
        $notes = $request->input('details');
        $status = $request->input('status');

        $user = Auth::user();
        $details = $notes . " --- " . ucwords($status) . " By: " . $user->name;

        try {
            if ($status == 'rejected') {
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_cv_in_quality_reject' => true,
                        'is_cv_in_quality' => false
                    ]);

                CVNote::where([
                    'sale_id' => $sale_id,
                    'applicant_id' => $applicant_id
                ])->update(['status' => 0]);
            } elseif ($status == 'cleared') {
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_interview_confirm' => true,
                        'is_cv_in_quality_clear' => true,
                        'is_cv_in_quality' => false,
                        'is_cv_in_quality_reject' => false,
                    ]);

                CrmNote::where([
                    'applicant_id' => $applicant_id,
                    'sale_id' => $sale_id
                ])->update(['status' => 0]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user->id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "cv_sent";
                $crm_notes->save();

                /** Update UID */
                $crm_notes->crm_notes_uid = md5($crm_notes->id);
                $crm_notes->save();

                $formattedMessage = '';
                // Fetch SMS template from the database
                $sms_template = SmsTemplate::where('slug', 'quality_cleared')
                    ->where('status', 1)
                    ->first();

                if ($sms_template && !empty($sms_template->template)) {
                    $sms_template_text = $sms_template->template;
                    $applicant = Applicant::find($applicant_id);
                    $sale = Sale::find($sale_id);
                    $unit = $sale ? Unit::find($sale->unit_id) : null;

                    $replace = [
                        $applicant ? $applicant->applicant_name : '',
                        $unit ? $unit->unit_name : ''
                    ];
                    $prev_val = ['(applicant_name)', '(unit_name)'];

                    $newPhrase = str_replace($prev_val, $replace, $sms_template_text);
                    $formattedMessage = nl2br($newPhrase);

                    if ($applicant && $applicant->applicant_phone) {
                        $is_save = $this->saveSMSDB($applicant->applicant_phone, $formattedMessage, $applicant->id);
                        if (!$is_save) {
                            Log::warning('SMS saved to DB failed for applicant ID: ' . $applicant->id);
                            throw new \Exception('SMS is not stored in DB');
                        }
                    }
                }
            } elseif ($status == 'cleared_no_job') {
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_interview_confirm' => true,
                        'is_cv_in_quality_clear' => true,
                        'is_cv_in_quality' => false,
                        'is_cv_in_quality_reject' => false,
                    ]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user->id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "cv_sent_no_job";
                $crm_notes->save();

                /** Update UID */
                $crm_notes->crm_notes_uid = md5($crm_notes->id);
                $crm_notes->save();
            } elseif ($status == 'revert') { //Revert from Open Cv
                $cv_count = CVNote::where([
                    'cv_notes.sale_id' => $sale_id,
                    'cv_notes.status' => 1
                ])->count();

                $sale_cv_count = Sale::select('cv_limit')
                    ->where('id', $sale_id)->first();

                if ($cv_count >=  $sale_cv_count->cv_limit) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Sale cv limit exceeded',
                    ]);
                }

                CVNote::where([
                    'sale_id' => $sale_id,
                    'applicant_id' => $applicant_id
                ])->update(['status' => 0]);

                $cv_note = new CVNote();
                $cv_note->sale_id = $sale_id;
                $cv_note->applicant_id = $applicant_id;
                $cv_note->user_id = $user->id;
                $cv_note->details = $details;
                $cv_note->save();

                /** Update UID */
                $cv_note->cv_uid = md5($cv_note->id);
                $cv_note->save();
            }

            $audit_data['action'] = $status;
            $audit_data['sale'] = $sale_id;
            $audit_data['details'] = $details;
            $audit_data['applicant'] = $applicant_id;

            $qualityStatus = null;
            if ($status == "opened") {
                $qualityStatus = "cv_hold";
            } else {
                $qualityStatus = $status;
            }

            QualityNotes::where([
                'applicant_id' => $applicant_id,
                'sale_id' => $sale_id,
            ])->update(['status' => 0]);

            if ($status != 'revert') {
                $quality_notes = new QualityNotes();
                $quality_notes->applicant_id = $applicant_id;
                $quality_notes->user_id = $user->id;
                $quality_notes->sale_id = $sale_id;
                $quality_notes->details = $details;
                $quality_notes->moved_tab_to = $qualityStatus;
                $quality_notes->save();

                /** Update UID */
                $quality_notes->quality_notes_uid = md5($quality_notes->id);
                $quality_notes->save();
            }

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])
                ->update(["status" => 0]);

            $historyStatus = null;
            if ($status == 'rejected') {
                $historyStatus = 'quality_reject';
            } elseif ($status == 'cleared') {
                $historyStatus = 'quality_cleared';
            } elseif ($status == 'opened') {
                $historyStatus = 'quality_cvs_hold';
            } elseif ($status == 'revert') {
                $historyStatus = 'quality_cvs';
            } elseif ($status == 'cleared_no_job') {
                $historyStatus = 'quality_cleared_no_job';
            }

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user->id;
            $history->sale_id = $sale_id;
            $history->stage = 'quality';
            $history->sub_stage = $historyStatus;
            $history->save();

            /** Update UID */
            $history->history_uid = md5($history->id);
            $history->save();

            if ($status != 'cleared') {
                $revertStatus = null;
                if ($status == "opened") {
                    $revertStatus = "cv_hold";
                } elseif ($status == "rejected") {
                    $revertStatus = 'quality_note';
                } elseif ($status == "revert") {
                    $revertStatus = 'quality_revert';
                }

                RevertStage::create([
                    'applicant_id' => $applicant_id,
                    'sale_id' => $sale_id,
                    'stage' => $revertStatus,
                    'user_id' => $user->id,
                    'notes' => $details,
                ]);
            }

            //send sms
            if ($status == 'cleared') {
                // $unit_name = Sale::join('units', 'sales.unit_id', '=', 'units.id')
                //         ->where('sales.id', '=', $sale_id)
                //         ->select('units.unit_name')
                //         ->first();

                // $applicant_number = $applicant_phone;
                // $applicant_message = 'Hi Thank you for your time over the phone. I am sharing your resume details with the manager of ' . $unit_name . ' for the discussed vacancy. If you have any questions, feel free to reach out. Thank you for choosing Kingbury to represent you. Best regards, CRM TEAM T: 01494211220 E: crm@kingsburypersonnel.com';

                // $applicant_message_encoded = urlencode($applicant_message);
                // $query_string = 'http://milkyway.tranzcript.com:1008/sendsms?username=admin&password=admin&phonenumber=' . $applicant_number . '&message=' . $applicant_message_encoded . '&port=1&report=JSON&timeout=0';

                // $sms_res = $this->sendQualityClearSms($query_string);
                // $smsSaveRes = '';
                // if ($sms_res['result'] == 'success') {
                //     $userData = json_decode($sms_res['data'], true);
                //     $message = $userData['message'];
                //     $phone = $userData['report'][0][1][0]['phonenumber'];
                //     $timeString = $userData['report'][0][1][0]['time'];
                //     $sms_response = $this->saveQualityClearSendMessage($message, $phone, $timeString);
                //     if ($sms_response) {
                //         $smsSaveRes = 'success';
                //     } else {
                //         $smsSaveRes = 'error';
                //     }
                //     $smsResult = 'Successfuly!';
                // } else {
                //     $smsResult = 'Error';
                // }
            }

            return response()->json([
                'success' => true,
                'message' => 'Resource ' . $status . ' successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An error occurred while updating the record. Please try again.'
            ], 500);
        }
    }
    public function getQualityNotesHistory(Request $request)
    {
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'applicant_id' => 'required',  // Assuming 'module_notes' is the table name and 'id' is the primary key
                'sale_id' => 'required',  // Assuming 'module_notes' is the table name and 'id' is the primary key
            ]);

            // Fetch the module notes by the given ID
            $qualityNotes = QualityNotes::where('applicant_id', $request->applicant_id)
                ->where('sale_id', $request->sale_id)
                ->latest()->get();

            // Check if the module note was found
            if (!$qualityNotes) {
                return response()->json(['error' => 'Quality note not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $qualityNotes,
                'success' => true
            ]);
        } catch (\Exception $e) {
            // If an error occurs, catch it and return a meaningful error message
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again later.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500); // Internal server error
        }
    }
}
