<?php

namespace App\Http\Controllers;

use App\Support\ApplicantAgeWindows;
use Illuminate\Http\Request;
use App\Support\CvStorage;

use Horsefly\User;
use Horsefly\Sale;
use Horsefly\Applicant;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\Message;
use Horsefly\Audit;
use Horsefly\CVNote;
use Horsefly\Setting;
use Horsefly\CrmNote;
use Horsefly\QualityNotes;
use Horsefly\ApplicantNote;
use Horsefly\History;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\Notification;
use Horsefly\ClearedRevertCv;

use App\Support\DialLink;
use App\Traits\HidesPrivateContacts;

use App\Http\Controllers\Controller;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    use HidesPrivateContacts;

    // "Current Statistics" stat key => bucket produced by currentSaleBucketSql()
    private const CURRENT_SALE_BUCKETS = [
        'current_active_sales'          => 'active',
        'current_reopen_sales'          => 're_open',
        'current_onhold_sales'          => 'on_hold',
        'current_pending_onhold_sales'  => 'pending_on_hold',
        'current_pending_sales'         => 'pending',
        'current_rejected_sales'        => 'rejected',
        'current_closed_sales'          => 'closed',
        'current_blacklisted_sales'     => 'blacklisted',
    ];

    public function __construct()
    {
        $this->middleware('permission:dashboard')->only(['index']);
        $this->middleware('permission:dashboard-top-stats')->only(['getCounts']);
        $this->middleware('permission:dashboard-users')->only(['getUsersForDashboard']);
        $this->middleware('permission:dashboard-users-stats')->only(['getUserStatistics', 'getUserStatisticsDetail']);
        $this->middleware('permission:dashboard-sales-weekly-analytics')->only(['getWeeklySales']);
        $this->middleware('permission:dashboard-sales-analytics-chart')->only(['getSalesAnalytic']);
        $this->middleware('permission:dashboard-weekly-analytics,dashboard-monthly-analytics,dashboard-yearly-analytics,dashboard-daily-analytics,dashboard-agents-stats,dashboard-agents-chart')
            ->only(['getStats', 'getStatisticsDetails', 'getChartData', 'getStatusDetails', 'statisticsReportIndex', 'getStatisticsApplicants']);
    }

    public function index()
    {
        $user = Auth::user();

        // if ($user->hasRole(['super_admin', 'admin'])) {
        return view('dashboards.index');
        // } elseif ($user->hasRole(['sales', 'sale and crm', 'quality'])) {
        //     return view('dashboards.sales');
        // } else {
        //     return view('dashboards.agents');
        // }
    }
    public function notificationsIndex()
    {
        return view('dashboards.notifications');
    }
    public function getCounts()
    {


        // Preload counts that are quick
        $applicantQuery = Applicant::where('status', 1)->whereNull('deleted_at');
        $officeQuery    = Office::where('status', 1)->whereNull('deleted_at');
        $unitQuery      = Unit::where('status', 1)->whereNull('deleted_at');
        $saleQuery      = Sale::where('status', 1)->whereNull('deleted_at')->where('is_on_hold', 0);

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
            $applicantQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('job_source_id', $sourceIds)
                    ->orWhereNull('job_source_id');
            });

            $officeQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('job_source_id', $sourceIds)
                    ->orWhereNull('job_source_id');
            });

            $unitQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('job_source_id', $sourceIds)
                    ->orWhereNull('job_source_id');
            });

            $saleQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('job_source_id', $sourceIds)
                    ->orWhereNull('job_source_id');
            });
        }

        // Records hidden by private notes on the list pages are left out of the counts too.
        $this->excludePrivateNotes($officeQuery, 'offices.office_notes');
        $this->excludePrivateNotes($unitQuery, 'units.unit_notes');
        $this->excludePrivateSales($saleQuery, 'sales.id');

        $applicantsCount = $applicantQuery->count();
        $officesCount = $officeQuery->count();
        $unitsCount = $unitQuery->count();
        $salesCount = $saleQuery->count();

        // 🧠 Optimize by getting applicant IDs that exist in pivot table once
        $linkedApplicantIds = DB::table('applicants_pivot_sales')->distinct()->pluck('applicant_id');

        // Cache those IDs in memory for all 3 queries
        $unlinkedApplicants = Applicant::query()
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $linkedApplicantIds);

        // Use clones to avoid re-query building overhead
        // The same buckets as the Resources tabs these cards are named after (they were a day
        // off for 21 days, and "3 months" counted everything older than 36 days).
        $last7DaysCount = ApplicantAgeWindows::apply(clone $unlinkedApplicants, 'updated_at', 'last-7-days')->count();
        $last21DaysCount = ApplicantAgeWindows::apply(clone $unlinkedApplicants, 'updated_at', 'last-21-days')->count();
        $last3MonthsCount = ApplicantAgeWindows::apply(clone $unlinkedApplicants, 'updated_at', 'last-3-months')->count();

        return response()->json([
            'applicantsCount'  => $applicantsCount,
            'officesCount'     => $officesCount,
            'unitsCount'       => $unitsCount,
            'salesCount'       => $salesCount,
            'last7DaysCount'   => $last7DaysCount,
            'last21DaysCount'  => $last21DaysCount,
            'last3MonthsCount' => $last3MonthsCount,
        ]);
    }
    public function getUsersForDashboard(Request $request)
    {
        $model = User::query()->where('is_active', 1)
            ->whereNotIn('users.id', [1, 101, 190]) // Exclude super admin
            ->leftJoin('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select('users.*', 'roles.name as role_name'); // Add alias for sorting

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn === 'role') {
                $model->orderBy('role_name', $orderDirection);
            } elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('users.created_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('users.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('name', function ($user) {
                    $path = asset('/images/users/user.png') ?? asset('/images/users/default.jpg');

                    return '
                        <div class="d-flex align-items-center">
                            <img src="' . $path . '" class="avatar-sm rounded-circle me-2" alt="user">
                            <span>' . e($user->formatted_name) . '</span>
                        </div>
                    ';
                })
                ->addColumn('role_name', function ($user) {
                    $role = str_replace('_', ' ', $user->role_name); // returns the first (or only) role name
                    return $role ? ucwords($role) : '-';
                })
                ->addColumn('created_at', function ($user) {
                    return $user->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($user) {
                    return $user->formatted_updated_at; // Using accessor
                })
                ->addColumn('is_active', function ($user) {
                    $status = '';
                    if ($user->is_active) {
                        $status = '<span class="badge bg-success-subtle text-success py-1 px-2 fs-12">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger-subtle text-danger py-1 px-2 fs-12">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($user) {
                    $name = $user->formatted_name;
                    $email = $user->email;
                    $roleName = ucwords(str_replace('_', ' ', $user->role_name));
                    $status = '';

                    if ($user->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }
                    $html = '';
                    $html .= '<div class="d-flex gap-2 align-items-center">
                                <a href="#!" class="btn btn-light btn-sm" onclick="showDetailsModal(
                                        \'' . (int)$user->id . '\',
                                        \'' . addslashes(htmlspecialchars($name)) . '\',
                                        \'' . addslashes(htmlspecialchars($email)) . '\',
                                        \'' . addslashes(htmlspecialchars($roleName)) . '\',
                                        \'' . addslashes(htmlspecialchars($status)) . '\'
                                    )">
                                    <iconify-icon icon="solar:eye-broken"
                                                class="align-middle fs-18"></iconify-icon>
                                </a>';
                    if (Gate::allows('dashboard-users-stats')) {
                        $html .= '<a href="#!" class="btn btn-light btn-sm" onclick="showStatisticsModal(
                                            \'' . (int)$user->id . '\'
                                        )">
                                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info align-middle fs-18"></iconify-icon>
                                    </a>
                                </div>';
                    }
                    return $html;
                })
                ->rawColumns(['name', 'is_active', 'action', 'role_name'])
                ->make(true);
        }
    }
    public function getUserStatistics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_key'          => ['required', 'exists:users,id'],
            'date_range_filter' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}\|\d{4}-\d{2}-\d{2}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        try {

            [$start_date, $end_date] = explode('|', $request->date_range_filter);

            $startDate = Carbon::createFromFormat('Y-m-d', $start_date)->startOfDay();
            $endDate   = Carbon::createFromFormat('Y-m-d', $end_date)->endOfDay();

            $user_id = $request->user_key;

            $user = User::query()
                ->with('roles:id,name,type')
                ->select('id', 'name')
                ->findOrFail($user_id);

            $role = $user->roles->first();

            $role_type = $role->type ?? 'agent';
            $user_role = ucwords($role->name ?? '');
            $user_name = $user->name;

            $quality_agent_stats = [
                'cvs_cleared'   => 0,
                'cvs_rejected'  => 0,
                'cvs_opened'    => 0,
            ];

            $quality_stats['cvs_requested'] = 0;
            $quality_stats['cvs_cleared']   = 0;
            $quality_stats['cvs_rejected']  = 0;
            $quality_stats['cvs_opened']    = 0;
            $quality_stats['cvs_cleared_reverted'] = 0;

            $crm_stats = array_fill_keys([
                'CRM_sent_cvs',
                'CRM_rejected_cv',
                'CRM_request',
                'CRM_rejected_by_request',
                'CRM_confirmation',
                'CRM_rebook',
                'CRM_attended',
                'CRM_not_attended',
                'CRM_start_date',
                'CRM_start_date_hold',
                'CRM_declined',
                'CRM_invoice',
                'CRM_dispute',
                'CRM_paid',
            ], 0);

            $sales_stats = array_fill_keys([
                'open_sales',
                'reopen_sales',
                'updated_sales',
                'onhold_sales',
                'pending_sales',
                'rejected_sales',
                'close_sales',
            ], 0);

            $current_sales_stats = array_fill_keys(
                array_merge(['current_total_sales'], array_keys(self::CURRENT_SALE_BUCKETS)),
                0
            );

            $data_entry_stats = [
                'applicants_created' => 0,
                'applicants_updated' => 0,
            ];

            $prev_user_stats = [
                'start_date' => 0,
                'invoice'    => 0,
                'paid'       => 0,
            ];

            if ($role_type === 'sales') {

                $buildStat = function (string $messageLike, array $saleWheres, ?string $excludeMessageLike = null) use ($user_id, $startDate, $endDate) {
                    $latestAuditIds = Audit::query()
                        ->selectRaw('MAX(id) as latest_id')
                        ->where('auditable_type', 'Horsefly\\Sale')
                        ->where('user_id', $user_id)
                        ->where('message', 'LIKE', $messageLike)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->groupBy('auditable_id');

                    $query = Audit::query()
                        ->joinSub($latestAuditIds, 'latest', function ($join) {
                            $join->on('latest.latest_id', '=', 'audits.id');
                        })
                        ->join('sales', 'sales.id', '=', 'audits.auditable_id');

                    foreach ($saleWheres as $column => $value) {
                        $query->where("sales.$column", $value);
                    }

                    if ($excludeMessageLike) {
                        $this->excludeSalesWithUserAudit($query, $excludeMessageLike, $user_id, $startDate, $endDate);
                    }

                    return $query->count('audits.id');
                };

                $sales_stats['open_sales'] = $buildStat('%has been created%', [
                    // 'status'      => 1,
                    // 'is_re_open'  => 0,
                    // 'is_on_hold'  => 0,
                ]);

                $sales_stats['reopen_sales'] = $buildStat('%open%', [
                    // 'status'      => 1,
                    // 'is_re_open'  => 1,
                ]);

                // Reopening also logs a "has been updated" audit, so leave out
                // sales this user reopened in the range (counted in reopen_sales)
                $sales_stats['updated_sales'] = $buildStat('%has been updated%', [
                    'status'      => 1,
                    // 'is_re_open'  => 0,
                    // 'is_on_hold'  => 0,
                ], '%open%');

                $sales_stats['pending_sales'] = $buildStat('%has been created%', [
                    'status'      => 2,
                    // 'is_re_open'  => 0,
                    // 'is_on_hold'  => 0,
                ]);

                $sales_stats['onhold_sales'] = $buildStat('%sale-onhold%', [
                    // 'status'      => 1,
                    // 'is_on_hold'  => 1,
                    // 'is_re_open'  => 0,
                ]);

                $sales_stats['rejected_sales'] = $buildStat('%reject%', [
                    // 'status'      => 3,
                    // 'is_re_open'  => 0,
                    // 'is_on_hold'  => 0,
                ]);

                $sales_stats['close_sales'] = $buildStat('%close%', [
                    // 'status'      => 0,
                    // 'is_re_open'  => 0,
                    // 'is_on_hold'  => 0,
                ]);

                // CURRENT STATISTICS — where the sales this user added/updated stand right now
                $buckets = $this->userHandledSalesQuery($user_id, $startDate, $endDate)
                    ->selectRaw($this->currentSaleBucketSql() . ' as bucket, COUNT(*) as total')
                    ->groupBy('bucket')
                    ->pluck('total', 'bucket');

                foreach (self::CURRENT_SALE_BUCKETS as $key => $bucket) {
                    $current_sales_stats[$key] = (int) ($buckets[$bucket] ?? 0);
                }
                $current_sales_stats['current_total_sales'] = (int) $buckets->sum();
            } elseif ($role_type === 'data_entry') {

                $data_entry_stats['applicants_created'] = Audit::query()
                    ->where('auditable_type', Applicant::class)
                    ->where('user_id', $user_id)
                    ->where('message', 'LIKE', '%has been created%')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count();

                $data_entry_stats['applicants_updated'] = Audit::query()
                    ->where('auditable_type', Applicant::class)
                    ->where('user_id', $user_id)
                    ->where('message', 'LIKE', '%has been updated%')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count();
            } elseif ($role_type === 'quality') {
                $qualityNotes = QualityNotes::query()
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                $pairs = $qualityNotes
                    ->unique(fn($x) => $x->applicant_id . '-' . $x->sale_id)
                    ->values();

                if ($pairs->isNotEmpty()) {
                    $applicantIds = $pairs->pluck('applicant_id')->unique()->values();
                    $saleIds      = $pairs->pluck('sale_id')->unique()->values();

                    // ── created_at-based history: single creation-event sub_stages ──────
                    $histories = History::query()
                        ->whereIn('applicant_id', $applicantIds)
                        ->whereIn('sale_id', $saleIds)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->whereIn('sub_stage', [
                            'quality_cvs_hold',
                            'quality_reject',
                            'quality_cleared',
                        ])
                        ->orderBy('id')
                        ->get()
                        ->groupBy(fn($x) => $x->applicant_id . '-' . $x->sale_id);


                    foreach ($pairs as $pair) {

                        $pairKey = $pair->applicant_id . '-' . $pair->sale_id;

                        $pairHistory = collect($histories->get($pairKey, []))
                            ->keyBy('sub_stage');

                        if (isset($pairHistory['quality_cleared'])) {
                            $quality_agent_stats['cvs_cleared']++;
                        } elseif (isset($pairHistory['quality_reject'])) {
                            $quality_agent_stats['cvs_rejected']++;
                        } elseif (isset($pairHistory['quality_cvs_hold'])) {
                            $quality_agent_stats['cvs_opened']++;
                        }
                    }
                }
            } else {
                $cvNotes = CVNote::query()
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get()
                    ->unique(fn($x) => $x->applicant_id . '-' . $x->sale_id)
                    ->values();

                $quality_stats['cvs_requested'] = $cvNotes->count();

                $clearedRevertedCvs = ClearedRevertCv::query()
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get()
                    ->groupBy(fn($x) => $x->applicant_id . '-' . $x->sale_id);

                if ($clearedRevertedCvs->isNotEmpty()) {
                    $quality_stats['cvs_cleared_reverted'] += $clearedRevertedCvs->count();
                }

                if ($cvNotes->isNotEmpty()) {
                    $pairs = $cvNotes->map(fn($x) => [$x->applicant_id, $x->sale_id]);
                    $applicantIds = $pairs->pluck(0)->unique()->values();
                    $saleIds      = $pairs->pluck(1)->unique()->values();



                    $histories = History::query()
                        ->whereIn('applicant_id', $applicantIds)
                        ->whereIn('sale_id', $saleIds)
                        ->whereIn('sub_stage', [
                            'quality_cleared',
                            'quality_cvs_hold',
                            'quality_reject',
                            'crm_reject',
                            'crm_request',
                            'crm_request_confirm',
                            'crm_reebok',
                            'crm_interview_attended',
                            'crm_interview_not_attended',
                            'crm_start_date',
                            'crm_start_date_back',
                            'crm_start_date_hold',
                            'crm_invoice',
                            'crm_dispute',
                            'crm_paid',
                            'crm_request_reject',
                            'crm_declined',
                        ])
                        ->orderBy('id') // ascending -> chronological order per pair
                        ->get()
                        // only keep rows whose (applicant_id, sale_id) is a REAL pair from cvNotes
                        ->filter(fn($x) => $pairs->contains(fn($p) => $p[0] == $x->applicant_id && $p[1] == $x->sale_id))
                        ->groupBy(fn($x) => $x->applicant_id . '-' . $x->sale_id);

                    $crmNotes = CrmNote::query()
                        ->where('moved_tab_to', 'cv_sent')
                        ->whereIn('applicant_id', $applicantIds)
                        ->whereIn('sale_id', $saleIds)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->latest('id')
                        ->get()
                        ->groupBy(fn($x) => $x->applicant_id . '-' . $x->sale_id);

                    foreach ($cvNotes as $pair) {
                        $pairKey = $pair->applicant_id . '-' . $pair->sale_id;

                        // events for this pair, already chronological (ascending id)
                        $pairEvents  = collect($histories->get($pairKey, []));
                        $pairHistory = $pairEvents->keyBy('sub_stage');
                        $pairCrmNote = collect($crmNotes->get($pairKey, []))->first();

                        /*
                        |--------------------------------------------------------------------------
                        | QUALITY OUTCOME — use the LATEST quality event, not isset() priority
                        |--------------------------------------------------------------------------
                        */
                        $latestQualityEvent = $pairEvents
                            ->whereIn('sub_stage', ['quality_cleared', 'quality_reject', 'quality_cvs_hold'])
                            ->last();

                        if ($latestQualityEvent) {
                            switch ($latestQualityEvent->sub_stage) {
                                case 'quality_cleared':
                                    $quality_stats['cvs_cleared']++;
                                    $crm_stats['CRM_sent_cvs']++;
                                    break;
                                case 'quality_reject':
                                    $quality_stats['cvs_rejected']++;
                                    break;
                                case 'quality_cvs_hold':
                                    $quality_stats['cvs_opened']++;
                                    break;
                            }
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CRM REJECTED
                        |--------------------------------------------------------------------------
                        */
                        if (
                            isset($pairHistory['crm_reject']) &&
                            $pairHistory['crm_reject']->status == 1
                        ) {
                            $crm_stats['CRM_rejected_cv']++;
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | CRM REQUEST
                        |--------------------------------------------------------------------------
                        */
                        if (!isset($pairHistory['crm_request'])) {
                            continue;
                        }

                        if (
                            !$pairCrmNote ||
                            !Carbon::parse($pairHistory['crm_request']->created_at)
                                ->gt($pairCrmNote->created_at)
                        ) {
                            continue;
                        }

                        $crm_stats['CRM_request']++;

                        if (isset($pairHistory['crm_request_confirm'])) {
                            $crm_stats['CRM_confirmation']++;
                        }
                        if (isset($pairHistory['crm_reebok'])) {
                            $crm_stats['CRM_rebook']++;
                        }
                        if (isset($pairHistory['crm_interview_attended'])) {
                            $crm_stats['CRM_attended']++;
                        }
                        if (isset($pairHistory['crm_interview_not_attended'])) {
                            $crm_stats['CRM_not_attended']++;
                        }
                        if (
                            isset($pairHistory['crm_start_date']) ||
                            isset($pairHistory['crm_start_date_back'])
                        ) {
                            $crm_stats['CRM_start_date']++;
                        }
                        if (isset($pairHistory['crm_start_date_hold'])) {
                            $crm_stats['CRM_start_date_hold']++;
                        }
                        if (isset($pairHistory['crm_declined'])) {
                            $crm_stats['CRM_declined']++;
                        }
                        if (isset($pairHistory['crm_invoice'])) {
                            $crm_stats['CRM_invoice']++;
                        }
                        if (isset($pairHistory['crm_dispute'])) {
                            $crm_stats['CRM_dispute']++;
                        }
                        if (isset($pairHistory['crm_paid'])) {
                            $crm_stats['CRM_paid']++;
                        }
                        if (isset($pairHistory['crm_request_reject'])) {
                            $crm_stats['CRM_rejected_by_request']++;
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | PREVIOUS MONTH STATS
                |--------------------------------------------------------------------------
                */

                $prevCvNotes = CVNote::query()
                    ->where('user_id', $user_id)
                    ->whereDate('created_at', '<', $startDate)
                    ->whereBetween('updated_at', [$startDate, $endDate])
                    ->get()
                    ->unique(fn($x) => $x->applicant_id . '-' . $x->sale_id)
                    ->values();

                if ($prevCvNotes->isNotEmpty()) {

                    $prevApplicantIds = $prevCvNotes->pluck('applicant_id')->unique()->values();

                    $prevSaleIds = $prevCvNotes->pluck('sale_id')->unique()->values();

                    $prevHistory = History::query()
                        ->whereIn('applicant_id', $prevApplicantIds)
                        ->whereIn('sale_id', $prevSaleIds)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->whereIn('sub_stage', [
                            'crm_start_date',
                            'crm_start_date_back',
                            'crm_invoice',
                            'crm_paid',
                        ])
                        ->orderBy('id')
                        ->get()
                        ->groupBy(fn($x) => $x->applicant_id . '-' . $x->sale_id);

                    foreach ($prevCvNotes as $pair) {

                        $pairKey = $pair->applicant_id . '-' . $pair->sale_id;

                        $history = collect($prevHistory->get($pairKey, []))
                            ->keyBy('sub_stage');

                        if (
                            isset($history['crm_start_date']) ||
                            isset($history['crm_start_date_back'])
                        ) {
                            $prev_user_stats['start_date']++;
                        }

                        if (isset($history['crm_invoice'])) {
                            $prev_user_stats['invoice']++;
                        }

                        if (isset($history['crm_paid'])) {
                            $prev_user_stats['paid']++;
                        }
                    }
                }
            }

            return response()->json([
                'user_name'        => $user_name,
                'user_role'        => $user_role,
                'user_role_type'   => $role_type,
                'quality_stats'    => $quality_stats,
                'quality_agent_stats'    => $quality_agent_stats,
                'user_stats'       => $crm_stats,
                'prev_user_stats'  => $prev_user_stats,
                'data_entry_stats' => $data_entry_stats,
                'sales_stats'      => $sales_stats,
                'current_sales_stats' => $current_sales_stats,
                'start_date'       => $startDate->format('d M Y'),
                'end_date'         => $endDate->format('d M Y'),
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getUserStatisticsDetail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_key'          => ['required', 'exists:users,id'],
            'stat_key'          => ['required', 'string'],
            'date_range_filter' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}\|\d{4}-\d{2}-\d{2}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        [$start_date, $end_date] = explode('|', $request->input('date_range_filter'));
        $startDate = Carbon::createFromFormat('Y-m-d', $start_date)->startOfDay();
        $endDate   = Carbon::createFromFormat('Y-m-d', $end_date)->endOfDay();
        $user_id   = $request->input('user_key');
        $stat_key  = $request->input('stat_key');

        // ── Shared eager-load set for History → sale relationships ────────────────
        $saleWith = ['applicant', 'sale.jobTitle', 'sale.office', 'sale.unit'];

        try {
            $columns = [];
            $rows    = [];

            // ══════════════════════════════════════════════════════════════════════
            // SALES STATS
            // ══════════════════════════════════════════════════════════════════════
            $salesStatMap = [
                'open_sales'     => ['message' => '%has been created%'],
                'reopen_sales'   => ['message' => '%open%'],
                'updated_sales'  => ['message' => '%has been updated%', 'status' => 1, 'exclude' => '%open%'],
                'pending_sales'  => ['message' => '%has been created%', 'status' => 2],
                'onhold_sales'   => ['message' => '%sale-onhold%'],
                'rejected_sales' => ['message' => '%reject%'],
                'close_sales'    => ['message' => '%close%'],
            ];

            /*** for the sale */
            if (isset($salesStatMap[$stat_key])) {
                $map = $salesStatMap[$stat_key];

                // Mirrors: SELECT auditable_id, MAX(id) AS latest_id ... GROUP BY auditable_id
                $latestAuditIds = Audit::query()
                    ->selectRaw('MAX(id) as latest_id')
                    ->where('auditable_type', 'Horsefly\\Sale')
                    ->where('user_id', $user_id)
                    ->where('message', 'LIKE', $map['message'])
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->groupBy('auditable_id');

                $audits = Audit::query()
                    ->joinSub($latestAuditIds, 'latest', function ($join) {
                        $join->on('latest.latest_id', '=', 'audits.id');
                    })
                    ->join('sales', 'sales.id', '=', 'audits.auditable_id')
                    ->when(isset($map['status']) && !is_null($map['status']), function ($q) use ($map) {
                        $q->where('sales.status', $map['status']);
                    })
                    ->when(isset($map['is_re_open']) && !is_null($map['is_re_open']), function ($q) use ($map) {
                        $q->where('sales.is_re_open', $map['is_re_open']);
                    })
                    ->when(isset($map['is_on_hold']) && !is_null($map['is_on_hold']), function ($q) use ($map) {
                        $q->where('sales.is_on_hold', $map['is_on_hold']);
                    })
                    ->when(!empty($map['exclude']), function ($q) use ($map, $user_id, $startDate, $endDate) {
                        $this->excludeSalesWithUserAudit($q, $map['exclude'], $user_id, $startDate, $endDate);
                    })
                    ->with(['auditable' => fn($q) => $q->with(['jobCategory', 'jobTitle', 'office', 'unit'])])
                    ->select('audits.*')
                    ->orderByDesc('audits.created_at')
                    ->get();

                $columns = ['#', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Date'];

                foreach ($audits as $i => $audit) {
                    $sale   = $audit->auditable;
                    $rows[] = [
                        $i + 1,
                        $sale->jobCategory->name   ?? '—',
                        $sale->jobTitle->name      ?? '—',
                        $sale->sale_postcode       ?? '—',
                        $sale->office->office_name ?? '—',
                        $sale->unit->unit_name     ?? '—',
                        $audit->created_at->format('d M Y h:i A'),
                    ];
                }

                /*** current statistics: sales the user added/updated, by their current state */
            } elseif ($stat_key === 'current_total_sales' || isset(self::CURRENT_SALE_BUCKETS[$stat_key])) {

                $bucketSql = $this->currentSaleBucketSql();

                $sales = $this->userHandledSalesQuery($user_id, $startDate, $endDate)
                    ->select('sales.*')
                    ->selectRaw("$bucketSql as bucket")
                    ->selectSub(
                        Audit::query()
                            ->selectRaw('MAX(audits.created_at)')
                            ->where('auditable_type', 'Horsefly\\Sale')
                            ->whereColumn('audits.auditable_id', 'sales.id')
                            ->where('user_id', $user_id)
                            ->whereBetween('created_at', [$startDate, $endDate]),
                        'last_action_at'
                    )
                    ->when($stat_key !== 'current_total_sales', function ($q) use ($bucketSql, $stat_key) {
                        $q->whereRaw("$bucketSql = ?", [self::CURRENT_SALE_BUCKETS[$stat_key]]);
                    })
                    ->with(['jobCategory', 'jobTitle', 'office', 'unit'])
                    ->orderByDesc('last_action_at')
                    ->get();

                $badges = [
                    'blacklisted'     => ['dark', 'Blacklisted'],
                    'on_hold'         => ['warning', 'On Hold'],
                    'pending_on_hold' => ['warning', 'Pending On Hold'],
                    're_open'         => ['dark', 'Re-Open'],
                    'active'          => ['success', 'Active'],
                    'pending'         => ['info', 'Pending'],
                    'rejected'        => ['danger', 'Rejected'],
                    'closed'          => ['danger', 'Closed'],
                ];

                $columns = ['#', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Current Status', 'Last Action'];

                foreach ($sales as $i => $sale) {
                    [$badgeClass, $badgeText] = $badges[$sale->bucket] ?? ['secondary', 'Other'];
                    $rows[] = [
                        $i + 1,
                        $sale->jobCategory->name   ?? '—',
                        $sale->jobTitle->name      ?? '—',
                        $sale->sale_postcode       ?? '—',
                        $sale->office->office_name ?? '—',
                        $sale->unit->unit_name     ?? '—',
                        '<span class="badge bg-' . $badgeClass . '">' . $badgeText . '</span>',
                        $sale->last_action_at ? Carbon::parse($sale->last_action_at)->format('d M Y h:i A') : '—',
                    ];
                }

                // ══════════════════════════════════════════════════════════════════════
                // CVS REQUESTED
                // Counter  : CVNote::where(user_id)->whereBetween(created_at)->count()
                // Detail   : all rows — no unique needed (counter counts all rows too)
                // ══════════════════════════════════════════════════════════════════════
            } elseif ($stat_key === 'cvs_requested') {

                $cvNotes = CVNote::query()
                    ->with(['applicant', 'sale.jobCategory', 'sale.jobTitle', 'sale.office', 'sale.unit'])
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->latest('created_at') // newest first
                    ->get()
                    ->unique(fn($cv) => $cv->applicant_id . '-' . $cv->sale_id)
                    ->values();

                // Fetch the latest history record for each applicant_id/sale_id pair in one query
                $histories = History::query()
                    ->where(function ($query) use ($cvNotes) {
                        foreach ($cvNotes as $cv) {
                            $query->orWhere(function ($q) use ($cv) {
                                $q->where('applicant_id', $cv->applicant_id)
                                    ->where('sale_id', $cv->sale_id);
                            });
                        }
                    })
                    ->orderByDesc('created_at')
                    ->get()
                    // rows are already newest-first, so unique() keeps the latest per pair
                    ->unique(fn($h) => $h->applicant_id . '-' . $h->sale_id)
                    ->keyBy(fn($h) => $h->applicant_id . '-' . $h->sale_id);

                $columns = ['#', 'Applicant', 'PostCode', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Status', 'Date'];

                foreach ($cvNotes as $i => $cv) {
                    $historyKey = $cv->applicant_id . '-' . $cv->sale_id;
                    $status = $histories->get($historyKey)?->sub_stage
                        ? ucwords(str_replace('_', ' ', $histories->get($historyKey)->sub_stage))
                        : '—';

                    $rows[] = [
                        $i + 1,
                        $cv->applicant->applicant_name  ?? '—',
                        $cv->applicant->applicant_postcode ?? '—',
                        $cv->sale->jobCategory->name    ?? '—',
                        $cv->sale->jobTitle->name       ?? '—',
                        $cv->sale->sale_postcode        ?? '—',
                        $cv->sale->office->office_name  ?? '—',
                        $cv->sale->unit->unit_name      ?? '—',
                        $status,
                        $cv->created_at->format('d M Y h:i A'),
                    ];
                }

                // ══════════════════════════════════════════════════════════════════════
                // CVS CLEARED
                // Counter  : unique pairs → isNotEmpty() on quality_cleared → 1 per pair
                // Detail   : first() per pair to match that 1-per-pair count
                // ══════════════════════════════════════════════════════════════════════
            } elseif (in_array($stat_key, ['cvs_cleared', 'cvs_rejected', 'cvs_opened'])) {

                $cvNotes = CVNote::query()
                    ->with(['applicant', 'sale.jobCategory', 'sale.jobTitle', 'sale.office', 'sale.unit'])
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->latest('created_at')
                    ->get()
                    ->unique(fn($cv) => $cv->applicant_id . '-' . $cv->sale_id)
                    ->values();

                $columns = ['#', 'Applicant', 'PostCode', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Date'];

                if ($cvNotes->isNotEmpty()) {
                    $applicantIds = $cvNotes->pluck('applicant_id')->unique()->values()->all();
                    $saleIds      = $cvNotes->pluck('sale_id')->unique()->values()->all();

                    // ONE query, ONE date definition, no whereBetween on the outcome —
                    // an outcome resolved after the report window still counts as the
                    // real outcome. This replaces the old cleared(updated_at) vs
                    // rejected(created_at) vs opened(updated_at) mismatch, which is
                    // what let pairs get dropped or miscategorized.
                    $qualityEvents = History::query()
                        ->with($saleWith)
                        ->whereIn('sub_stage', ['quality_cleared', 'quality_reject', 'quality_cvs_hold'])
                        ->whereIn('applicant_id', $applicantIds)
                        ->whereIn('sale_id', $saleIds)
                        ->orderBy('id') // chronological per pair
                        ->get()
                        ->groupBy(fn($h) => $h->applicant_id . '-' . $h->sale_id);

                    $wantSubStage = [
                        'cvs_cleared'  => 'quality_cleared',
                        'cvs_rejected' => 'quality_reject',
                        'cvs_opened'   => 'quality_cvs_hold',
                    ][$stat_key];

                    foreach ($cvNotes as $cv) {
                        $pairKey = $cv->applicant_id . '-' . $cv->sale_id;

                        // Take the LATEST quality event for this pair — not "first cleared
                        // query that matches", not date-column-dependent — so a pair that
                        // cycled hold -> reject -> cleared is reported as cleared, matching
                        // the fixed counter logic exactly.
                        $latest = collect($qualityEvents->get($pairKey, []))->last();

                        if (!$latest || $latest->sub_stage !== $wantSubStage) {
                            continue;
                        }

                        $rows[] = [
                            count($rows) + 1,
                            $latest->applicant->applicant_name     ?? '—',
                            $latest->applicant->applicant_postcode ?? '—',
                            $latest->sale->jobCategory->name       ?? '—',
                            $latest->sale->jobTitle->name          ?? '—',
                            $latest->sale->sale_postcode           ?? '—',
                            $latest->sale->office->office_name     ?? '—',
                            $latest->sale->unit->unit_name         ?? '—',
                            $latest->created_at->format('d M Y h:i A'),
                        ];
                    }
                }
            } elseif (str_starts_with($stat_key, 'CRM_')) {

                $columns = ['#', 'Applicant', 'PostCode', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Stage', 'Date'];

                $cvNotes = CVNote::query()
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select('applicant_id', 'sale_id')
                    ->latest('created_at')
                    ->get()
                    ->unique(fn($cv) => $cv->applicant_id . '-' . $cv->sale_id)
                    ->values();

                if ($cvNotes->isEmpty()) {
                    return response()->json([
                        'stat_key' => $stat_key,
                        'columns'  => $columns,
                        'rows'     => [],
                        'total'    => 0,
                    ]);
                }

                $applicantIds = $cvNotes->pluck('applicant_id')->unique()->values()->all();
                $saleIds      = $cvNotes->pluck('sale_id')->unique()->values()->all();

                // No whereBetween(created_at) here — same reasoning as above: the CV
                $allHistory = History::query()
                    ->whereIn('sub_stage', [
                        'quality_cleared',
                        'quality_cvs_hold',
                        'quality_reject',
                        'crm_reject',
                        'crm_request',
                        'crm_request_confirm',
                        'crm_reebok',
                        'crm_interview_attended',
                        'crm_interview_not_attended',
                        'crm_start_date',
                        'crm_start_date_back',
                        'crm_start_date_hold',
                        'crm_invoice',
                        'crm_dispute',
                        'crm_paid',
                        'crm_request_reject',
                        'crm_declined',
                    ])
                    ->whereIn('applicant_id', $applicantIds)
                    ->whereIn('sale_id', $saleIds)
                    ->with($saleWith)
                    ->orderBy('id')
                    ->get()
                    ->groupBy(fn($h) => $h->applicant_id . '-' . $h->sale_id);

                $allCrmNotes = CrmNote::query()
                    ->where('moved_tab_to', 'cv_sent')
                    ->whereIn('applicant_id', $applicantIds)
                    ->whereIn('sale_id', $saleIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy(fn($n) => $n->applicant_id . '-' . $n->sale_id);

                $downstreamMap = [
                    'CRM_rejected_by_request' => 'crm_request_reject',
                    'CRM_confirmation'        => 'crm_request_confirm',
                    'CRM_rebook'              => 'crm_reebok',
                    'CRM_attended'            => 'crm_interview_attended',
                    'CRM_not_attended'        => 'crm_interview_not_attended',
                    'CRM_start_date'          => ['crm_start_date', 'crm_start_date_back'],
                    'CRM_start_date_hold'     => 'crm_start_date_hold',
                    'CRM_declined'            => 'crm_declined',
                    'CRM_invoice'             => 'crm_invoice',
                    'CRM_dispute'             => 'crm_dispute',
                    'CRM_paid'                => 'crm_paid',
                ];

                foreach ($cvNotes as $cv) {
                    $pairKey     = $cv->applicant_id . '-' . $cv->sale_id;
                    $pairEvents  = collect($allHistory->get($pairKey, [])); // chronological
                    $pairHistory = $pairEvents->keyBy('sub_stage');
                    $pairCrmNote = $allCrmNotes->get($pairKey, collect())->first();

                    // ── CRM_sent_cvs ──────────────────────────────────────────────
                    // FIX: was referencing the removed $pairCleared variable (undefined
                    // var bug). Now derives "cleared" the same way as cvs_cleared above:
                    // latest quality event for this pair must be quality_cleared.
                    if ($stat_key === 'CRM_sent_cvs') {
                        $latestQuality = $pairEvents
                            ->whereIn('sub_stage', ['quality_cleared', 'quality_reject', 'quality_cvs_hold'])
                            ->last();

                        if (!$latestQuality || $latestQuality->sub_stage !== 'quality_cleared') {
                            continue;
                        }

                        $rows[] = [
                            count($rows) + 1,
                            $latestQuality->applicant->applicant_name     ?? '—',
                            $latestQuality->applicant->applicant_postcode ?? '—',
                            $latestQuality->sale->jobCategory->name       ?? '—',
                            $latestQuality->sale->jobTitle->name          ?? '—',
                            $latestQuality->sale->sale_postcode           ?? '—',
                            $latestQuality->sale->office->office_name     ?? '—',
                            $latestQuality->sale->unit->unit_name         ?? '—',
                            'CRM Sent CVs',
                            $latestQuality->created_at->format('d M Y h:i A'),
                        ];
                        continue;
                    }

                    // ── CRM_rejected_cv ───────────────────────────────────────────
                    if ($stat_key === 'CRM_rejected_cv') {
                        if (
                            isset($pairHistory['crm_reject']) &&
                            $pairHistory['crm_reject']->status == 1
                        ) {
                            $h      = $pairHistory['crm_reject'];
                            $rows[] = [
                                count($rows) + 1,
                                $h->applicant->applicant_name  ?? '—',
                                $h->applicant->applicant_postcode ?? '—',
                                $h->sale->jobCategory->name    ?? '—',
                                $h->sale->jobTitle->name       ?? '—',
                                $h->sale->sale_postcode        ?? '—',
                                $h->sale->office->office_name  ?? '—',
                                $h->sale->unit->unit_name      ?? '—',
                                'CRM Rejected CV',
                                $h->created_at->format('d M Y h:i A'),
                            ];
                        }
                        continue;
                    }

                    // ── Gate 1: crm_reject guard ──────────────────────────────────
                    if (
                        isset($pairHistory['crm_reject']) &&
                        $pairHistory['crm_reject']->status == 1
                    ) {
                        continue;
                    }

                    // ── Gate 2: crm_request must exist ───────────────────────────
                    if (!isset($pairHistory['crm_request'])) {
                        continue;
                    }

                    // ── Gate 3: crm_request must be newer than last cv_sent CrmNote
                    if (
                        !$pairCrmNote ||
                        !Carbon::parse($pairHistory['crm_request']->created_at)
                            ->gt($pairCrmNote->created_at)
                    ) {
                        continue;
                    }

                    if ($stat_key === 'CRM_request') {
                        $h      = $pairHistory['crm_request'];
                        $rows[] = [
                            count($rows) + 1,
                            $h->applicant->applicant_name  ?? '—',
                            $h->applicant->applicant_postcode ?? '—',
                            $h->sale->jobCategory->name    ?? '—',
                            $h->sale->jobTitle->name       ?? '—',
                            $h->sale->sale_postcode        ?? '—',
                            $h->sale->office->office_name  ?? '—',
                            $h->sale->unit->unit_name      ?? '—',
                            'CRM Request',
                            $h->created_at->format('d M Y h:i A'),
                        ];
                        continue;
                    }

                    $target = $downstreamMap[$stat_key] ?? null;
                    if (!$target) continue;

                    if (is_array($target)) {
                        $h = null;
                        foreach ($target as $t) {
                            if (isset($pairHistory[$t])) {
                                $h = $pairHistory[$t];
                                break;
                            }
                        }
                    } else {
                        $h = $pairHistory[$target] ?? null;
                    }

                    if (!$h) continue;

                    $rows[] = [
                        count($rows) + 1,
                        $h->applicant->applicant_name  ?? '—',
                        $h->applicant->applicant_postcode ?? '—',
                        $h->sale->jobCategory->name    ?? '—',
                        $h->sale->jobTitle->name       ?? '—',
                        $h->sale->sale_postcode        ?? '—',
                        $h->sale->office->office_name  ?? '—',
                        $h->sale->unit->unit_name      ?? '—',
                        ucwords(str_replace('_', ' ', $h->sub_stage)),
                        $h->created_at->format('d M Y h:i A'),
                    ];
                }
            } elseif (str_starts_with($stat_key, 'CRM_')) {

                $columns = ['#', 'Applicant', 'PostCode', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Stage', 'Date'];

                // ── Step 1: same cv_notes base as counter ─────────────────────────
                $cvNotes = CVNote::query()
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select('applicant_id', 'sale_id')
                    ->latest('created_at') // newest first
                    ->get()
                    ->unique(fn($cv) => $cv->applicant_id . '-' . $cv->sale_id)
                    ->values();

                if ($cvNotes->isEmpty()) {
                    return response()->json([
                        'stat_key' => $stat_key,
                        'columns'  => $columns,
                        'rows'     => [],
                        'total'    => 0,
                    ]);
                }

                $applicantIds = $cvNotes->pluck('applicant_id')->unique()->values()->all();
                $saleIds      = $cvNotes->pluck('sale_id')->unique()->values()->all();

                // ── Step 2: bulk-fetch ALL regular history in ONE query ───────────
                // Avoids N queries inside the loop; grouped by pair key
                $allHistory = History::query()
                    ->whereIn('sub_stage', [
                        'quality_cleared',
                        'quality_cvs_hold',
                        'quality_reject',
                        'crm_save',
                        'crm_reject',
                        'crm_request',
                        'crm_request_confirm',
                        'crm_reebok',
                        'crm_interview_attended',
                        'crm_interview_not_attended',
                        'crm_start_date',
                        'crm_start_date_back',
                        'crm_start_date_hold',
                        'crm_invoice',
                        'crm_dispute',
                        'crm_paid',
                        'crm_request_reject',
                        'crm_declined',
                    ])
                    ->whereIn('applicant_id', $applicantIds)
                    ->whereIn('sale_id', $saleIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->with($saleWith)
                    ->get()
                    ->groupBy(fn($h) => $h->applicant_id . '-' . $h->sale_id);

                // ── Step 3: bulk-fetch quality_cleared in ONE query ───────────────
                // $allCleared = History::query()
                //     ->where('sub_stage', 'quality_cleared')
                //     ->whereIn('applicant_id', $applicantIds)
                //     ->whereIn('sale_id', $saleIds)
                //     ->whereBetween('updated_at', [$startDate, $endDate])
                //     ->whereColumn('created_at', '!=', 'updated_at')
                //     ->with($saleWith)
                //     ->get()
                //     ->groupBy(fn($h) => $h->applicant_id . '-' . $h->sale_id);

                // ── Step 4: bulk-fetch CrmNotes for crm_request gate ─────────────
                $allCrmNotes = CrmNote::query()
                    ->where('moved_tab_to', 'cv_sent')
                    ->whereIn('applicant_id', $applicantIds)
                    ->whereIn('sale_id', $saleIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy(fn($n) => $n->applicant_id . '-' . $n->sale_id);

                // ── Downstream stat → sub_stage map ──────────────────────────────
                $downstreamMap = [
                    'CRM_rejected_by_request' => 'crm_request_reject',
                    'CRM_confirmation'        => 'crm_request_confirm',
                    'CRM_rebook'              => 'crm_reebok',
                    'CRM_attended'            => 'crm_interview_attended',
                    'CRM_not_attended'        => 'crm_interview_not_attended',
                    'CRM_start_date'          => ['crm_start_date', 'crm_start_date_back'], // either qualifies
                    'CRM_start_date_hold'     => 'crm_start_date_hold',
                    'CRM_declined'            => 'crm_declined',
                    'CRM_invoice'             => 'crm_invoice',
                    'CRM_dispute'             => 'crm_dispute',
                    'CRM_paid'                => 'crm_paid',
                ];

                // ── Step 5: process each unique pair ─────────────────────────────
                foreach ($cvNotes as $cv) {
                    $pairKey     = $cv->applicant_id . '-' . $cv->sale_id;
                    $pairHistory = collect($allHistory->get($pairKey, []))->keyBy('sub_stage');
                    // $pairCleared = $allCleared->get($pairKey, collect());
                    $pairCrmNote = $allCrmNotes->get($pairKey, collect())->first();

                    // ── CRM_sent_cvs ──────────────────────────────────────────────
                    // Counter increments BEFORE the crm_reject guard, so no guard here.
                    // 1 per pair — mirrors isNotEmpty() in counter.
                    if ($stat_key === 'CRM_sent_cvs') {
                        if (
                            isset($pairHistory['crm_save']) &&
                            $pairHistory['crm_save']->status == 1
                        ) {
                            $h      = $pairHistory->first();
                            $rows[] = [
                                count($rows) + 1,
                                $h->applicant->applicant_name  ?? '—',
                                $h->applicant->applicant_postcode ?? '—',
                                $h->sale->jobCategory->name    ?? '—',
                                $h->sale->jobTitle->name       ?? '—',
                                $h->sale->sale_postcode        ?? '—',
                                $h->sale->office->office_name  ?? '—',
                                $h->sale->unit->unit_name      ?? '—',
                                'CRM Sent CVs',
                                $h->updated_at->format('d M Y h:i A'),
                            ];
                        }
                        continue;
                    }

                    // ── CRM_rejected_cv ───────────────────────────────────────────
                    // This IS the reject stat — no crm_reject guard against itself.
                    // 1 per pair — mirrors keyBy() + status check in counter.
                    if ($stat_key === 'CRM_rejected_cv') {
                        if (
                            isset($pairHistory['crm_reject']) &&
                            $pairHistory['crm_reject']->status == 1
                        ) {
                            $h      = $pairHistory['crm_reject'];
                            $rows[] = [
                                count($rows) + 1,
                                $h->applicant->applicant_name  ?? '—',
                                $h->applicant->applicant_postcode ?? '—',
                                $h->sale->jobCategory->name    ?? '—',
                                $h->sale->jobTitle->name       ?? '—',
                                $h->sale->sale_postcode        ?? '—',
                                $h->sale->office->office_name  ?? '—',
                                $h->sale->unit->unit_name      ?? '—',
                                'CRM Rejected CV',
                                $h->created_at->format('d M Y h:i A'),
                            ];
                        }
                        continue;
                    }

                    // ── Gate 1: crm_reject guard ──────────────────────────────────
                    // Mirrors the `continue` in getUserStatistics() that skips all
                    // downstream CRM stats when crm_reject status == 1.
                    if (
                        isset($pairHistory['crm_reject']) &&
                        $pairHistory['crm_reject']->status == 1
                    ) {
                        continue;
                    }

                    // ── Gate 2: crm_request must exist ───────────────────────────
                    if (!isset($pairHistory['crm_request'])) {
                        continue;
                    }

                    // ── Gate 3: crm_request must be newer than last cv_sent CrmNote
                    if (
                        !$pairCrmNote ||
                        !Carbon::parse($pairHistory['crm_request']->created_at)
                            ->gt($pairCrmNote->created_at)
                    ) {
                        continue;
                    }

                    // ── CRM_request ───────────────────────────────────────────────
                    // All 3 gates passed — 1 per pair mirrors keyBy() in counter.
                    if ($stat_key === 'CRM_request') {
                        $h      = $pairHistory['crm_request'];
                        $rows[] = [
                            count($rows) + 1,
                            $h->applicant->applicant_name  ?? '—',
                            $h->applicant->applicant_postcode ?? '—',
                            $h->sale->jobCategory->name    ?? '—',
                            $h->sale->jobTitle->name       ?? '—',
                            $h->sale->sale_postcode        ?? '—',
                            $h->sale->office->office_name  ?? '—',
                            $h->sale->unit->unit_name      ?? '—',
                            'CRM Request',
                            $h->created_at->format('d M Y h:i A'),
                        ];
                        continue;
                    }

                    // ── All downstream CRM stats ──────────────────────────────────
                    // Only reachable after all 3 gates — exactly mirrors counter.
                    // isset() on keyed history = at most 1 row per pair.
                    $target = $downstreamMap[$stat_key] ?? null;

                    if (!$target) continue;

                    // CRM_start_date accepts either crm_start_date or crm_start_date_back
                    if (is_array($target)) {
                        $h = null;
                        foreach ($target as $t) {
                            if (isset($pairHistory[$t])) {
                                $h = $pairHistory[$t];
                                break;
                            }
                        }
                    } else {
                        $h = $pairHistory[$target] ?? null;
                    }

                    if (!$h) continue;

                    $rows[] = [
                        count($rows) + 1,
                        $h->applicant->applicant_name  ?? '—',
                        $h->applicant->applicant_postcode ?? '—',
                        $h->sale->jobCategory->name    ?? '—',
                        $h->sale->jobTitle->name       ?? '—',
                        $h->sale->sale_postcode        ?? '—',
                        $h->sale->office->office_name  ?? '—',
                        $h->sale->unit->unit_name      ?? '—',
                        ucwords(str_replace('_', ' ', $h->sub_stage)),
                        $h->created_at->format('d M Y h:i A'),
                    ];
                }

                // ══════════════════════════════════════════════════════════════════════
                // DATA ENTRY STATS
                // Both scoped to user_id and use created_at (not updated_at) to match
                // the counter in getUserStatistics().
                // ══════════════════════════════════════════════════════════════════════
            } elseif ($stat_key === 'applicants_created') {

                $audits  = Audit::query()
                    ->with('auditable')
                    ->where('auditable_type', Applicant::class)
                    ->where('user_id', $user_id)
                    ->where('message', 'LIKE', '%has been created%')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                $columns = ['#', 'Applicant Name', 'PostCode', 'Email', 'Phone', 'Created At'];

                foreach ($audits as $i => $audit) {
                    $applicant = $audit->auditable;
                    $email = '—';
                    if ($applicant->applicant_name && !$applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email;
                    } elseif (!$applicant->applicant_name && $applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email_secondary;
                    } elseif ($applicant->applicant_name && $applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary;
                    }

                    $phone = '—';
                    if ($applicant->applicant_phone && !$applicant->applicant_landline) {
                        $phone = $applicant->applicant_phone;
                    } elseif (!$applicant->applicant_phone && $applicant->applicant_landline) {
                        $phone = $applicant->applicant_landline;
                    } elseif ($applicant->applicant_phone && $applicant->applicant_landline) {
                        $phone = $applicant->applicant_phone . '<br>' . $applicant->applicant_landline;
                    }

                    $rows[]    = [
                        $i + 1,
                        $applicant->applicant_name ?? '—',
                        $applicant->applicant_postcode ?? '—',
                        $email,
                        $phone,
                        $audit->created_at->format('d M Y h:i A'),
                    ];
                }
            } elseif ($stat_key === 'applicants_updated') {

                $audits  = Audit::query()
                    ->with('auditable')
                    ->where('auditable_type', Applicant::class)
                    ->where('user_id', $user_id)
                    ->where('message', 'LIKE', '%has been updated%')
                    ->whereBetween('created_at', [$startDate, $endDate]) // created_at matches counter
                    ->get();

                $columns = ['#', 'Applicant Name', 'PostCode', 'Email', 'Phone', 'Updated At'];

                foreach ($audits as $i => $audit) {
                    $applicant = $audit->auditable;
                    $email = '—';
                    if ($applicant->applicant_name && !$applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email;
                    } elseif (!$applicant->applicant_name && $applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email_secondary;
                    } elseif ($applicant->applicant_name && $applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary;
                    }

                    $phone = '—';
                    if ($applicant->applicant_phone && !$applicant->applicant_landline) {
                        $phone = $applicant->applicant_phone;
                    } elseif (!$applicant->applicant_phone && $applicant->applicant_landline) {
                        $phone = $applicant->applicant_landline;
                    } elseif ($applicant->applicant_phone && $applicant->applicant_landline) {
                        $phone = $applicant->applicant_phone . '<br>' . $applicant->applicant_landline;
                    }
                    $rows[]    = [
                        $i + 1,
                        $applicant->applicant_name ?? '—',
                        $applicant->applicant_postcode ?? '—',
                        $email,
                        $phone,
                        $audit->created_at->format('d M Y h:i A'),
                    ];
                }

                // ══════════════════════════════════════════════════════════════════════
                // PREVIOUS MONTH STATS
                // CVs created BEFORE the range but whose history falls WITHIN it.
                // unique() on pairs + first() mirrors the counter's keyBy() dedupe.
                // ════════════════════════════════════════════════════════════════════════
            } elseif ($stat_key === 'cvs_cleared_reverted') {

                $columns = ['#', 'Applicant', 'PostCode', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Date'];

                $reverted = DB::table('cleared_revert_cvs as crc')
                    ->leftJoin('applicants as a', 'a.id', '=', 'crc.applicant_id')
                    ->leftJoin('sales as s', 's.id', '=', 'crc.sale_id')
                    ->leftJoin('job_categories as jc', 'jc.id', '=', 's.job_category_id')
                    ->leftJoin('job_titles as jt', 'jt.id', '=', 's.job_title_id')
                    ->leftJoin('offices as o', 'o.id', '=', 's.office_id')
                    ->leftJoin('units as u', 'u.id', '=', 's.unit_id')
                    ->where('crc.user_id', $user_id)
                    ->whereBetween('crc.created_at', [$startDate, $endDate])
                    ->select([
                        'a.applicant_name',
                        'a.applicant_postcode',
                        'jc.name as job_category_name',
                        'jt.name as job_title_name',
                        's.sale_postcode',
                        'o.office_name',
                        'u.unit_name',
                        'crc.created_at',
                    ])
                    ->orderByDesc('crc.created_at')
                    ->get();

                foreach ($reverted as $i => $r) {
                    $rows[] = [
                        $i + 1,
                        $r->applicant_name    ?? '—',
                        $r->applicant_postcode ?? '—',
                        $r->job_category_name ?? '—',
                        $r->job_title_name    ?? '—',
                        $r->sale_postcode     ?? '—',
                        $r->office_name       ?? '—',
                        $r->unit_name         ?? '—',
                        Carbon::parse($r->created_at)->format('d M Y h:i A'),
                    ];
                }
            } elseif (in_array($stat_key, ['start_date', 'invoice', 'paid'])) {

                $subStageMap = [
                    'start_date' => ['crm_start_date', 'crm_start_date_back'],
                    'invoice'    => ['crm_invoice'],
                    'paid'       => ['crm_paid'],
                ];

                $prevCvNotes = CVNote::query()
                    ->where('user_id', $user_id)
                    ->whereDate('created_at', '<', $startDate)           // created before range
                    ->whereBetween('updated_at', [$startDate, $endDate]) // updated within range
                    ->select('applicant_id', 'sale_id')
                    ->get()
                    ->unique(fn($cv) => $cv->applicant_id . '-' . $cv->sale_id) // mirrors counter
                    ->values();

                $columns = ['#', 'Applicant', 'PostCode', 'Job Category', 'Job Title', 'Sale Postcode', 'Office', 'Unit', 'Stage', 'Date'];

                foreach ($prevCvNotes as $cv) {
                    // first() — mirrors keyBy() in counter: 1 row per pair
                    $history = History::query()
                        ->with($saleWith)
                        ->where('applicant_id', $cv->applicant_id)
                        ->where('sale_id', $cv->sale_id)
                        ->whereIn('sub_stage', $subStageMap[$stat_key])
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->first();

                    if ($history) {
                        $rows[] = [
                            count($rows) + 1,
                            $history->applicant->applicant_name  ?? '—',
                            $history->applicant->applicant_postcode  ?? '—',
                            $history->sale->jobCategory->name    ?? '—',
                            $history->sale->jobTitle->name       ?? '—',
                            $history->sale->sale_postcode        ?? '—',
                            $history->sale->office->office_name  ?? '—',
                            $history->sale->unit->unit_name      ?? '—',
                            ucwords(str_replace('_', ' ', $history->sub_stage)),
                            $history->created_at->format('d M Y h:i A'),
                        ];
                    }
                }
            }

            return response()->json([
                'stat_key' => $stat_key,
                'columns'  => $columns,
                'rows'     => $rows,
                'total'    => count($rows),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * Drop sales that have an audit matching $messageLike by the same user within the range.
     */
    private function excludeSalesWithUserAudit($query, string $messageLike, $user_id, Carbon $startDate, Carbon $endDate): void
    {
        $query->whereNotIn('sales.id', Audit::query()
            ->select('auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->where('user_id', $user_id)
            ->where('message', 'LIKE', $messageLike)
            ->whereBetween('created_at', [$startDate, $endDate]));
    }

    /**
     * Distinct (non-deleted) sales the user created or updated within the range.
     */
    private function userHandledSalesQuery($user_id, Carbon $startDate, Carbon $endDate)
    {
        $saleIds = Audit::query()
            ->select('auditable_id')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->where('user_id', $user_id)
            ->where(function ($q) {
                $q->where('message', 'LIKE', '%has been created%')
                    ->orWhere('message', 'LIKE', '%has been updated%');
            })
            ->whereBetween('created_at', [$startDate, $endDate]);

        return Sale::query()->whereIn('sales.id', $saleIds);
    }

    /**
     * Current state of a sale, mutually exclusive. Same precedence as the sale
     * status badge, with blacklisted sales taking priority over everything.
     */
    private function currentSaleBucketSql(): string
    {
        return "CASE
            WHEN sales.is_blacklist = 1 THEN 'blacklisted'
            WHEN sales.status = 1 AND sales.is_on_hold = 1 THEN 'on_hold'
            WHEN sales.status = 1 AND sales.is_on_hold = 2 THEN 'pending_on_hold'
            WHEN sales.status = 1 AND sales.is_re_open = 1 THEN 're_open'
            WHEN sales.status = 1 THEN 'active'
            WHEN sales.status = 2 THEN 'pending'
            WHEN sales.status = 3 THEN 'rejected'
            WHEN sales.status = 0 THEN 'closed'
            ELSE 'other'
        END";
    }

    private function processCrmStats($history, array &$crm_stats, $applicant_id, $sale_id, string $start_date, string $end_date): void
    {
        if (isset($history['crm_request_reject']) && $history['crm_request_reject']->status == 1) {
            $crm_stats['CRM_rejected_by_request']++;
        }

        if (
            isset($history['crm_request_confirm']) && isset($history['crm_request']) &&
            Carbon::parse($history['crm_request_confirm']->created_at)->gt(
                Carbon::parse($history['crm_request']->created_at)
            )
        ) {
            $crm_stats['CRM_confirmation']++;

            if (isset($history['crm_reebok']) && $history['crm_reebok']->status == 1) {
                $crm_stats['CRM_rebook']++;
            }

            if (isset($history['crm_interview_attended'])) {
                $crm_stats['CRM_attended']++;

                if (isset($history['crm_declined']) && $history['crm_declined']->status == 1) {
                    $crm_stats['CRM_declined']++;
                }

                if (isset($history['crm_interview_not_attended']) && $history['crm_interview_not_attended']->status == 1) {
                    $crm_stats['CRM_not_attended']++;
                }

                if (isset($history['crm_start_date']) || isset($history['crm_start_date_back'])) {
                    $crm_stats['CRM_start_date']++;

                    if (isset($history['crm_start_date_hold']) && $history['crm_start_date_hold']->status == 1) {
                        $crm_stats['CRM_start_date_hold']++;
                    }

                    if (isset($history['crm_invoice'])) {
                        $crm_stats['CRM_invoice']++;

                        if (isset($history['crm_dispute']) && $history['crm_dispute']->status == 1) {
                            $crm_stats['CRM_dispute']++;
                        }

                        if (isset($history['crm_paid'])) {
                            $crm_stats['CRM_paid']++;
                        }
                    }
                }
            }
        }
    }
    public function getWeeklySales()
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

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

        /*
        * Weekly chart data
        */
        $dailyCountsQuery = Sale::whereBetween('created_at', [$startOfWeek, $endOfWeek])->whereNotIn('status', [4, 5]); // Exclude closed and rejected sales

        if (count($sourceIds) > 0) {
            $dailyCountsQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('job_source_id', $sourceIds)
                    ->orWhereNull('job_source_id');
            });
        }

        $this->excludePrivateSales($dailyCountsQuery, 'sales.id');

        $dailyCounts = $dailyCountsQuery
            ->select(
                DB::raw('DAYOFWEEK(created_at) as day'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy(DB::raw('DAYOFWEEK(created_at)'))
            ->pluck('total', 'day');

        // Format: 1 = Sunday, 7 = Saturday
        $chartData = [];

        for ($i = 1; $i <= 7; $i++) {
            $chartData[] = $dailyCounts[$i] ?? 0;
        }

        /*
        * Weekly sales details
        */
        $salesDetailsQuery = Sale::with(['office', 'unit'])
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->whereNotIn('status', [4, 5]); // Exclude closed and rejected sales

        if (count($sourceIds) > 0) {
            $salesDetailsQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('job_source_id', $sourceIds)
                    ->orWhereNull('job_source_id');
            });
        }

        $this->excludePrivateSales($salesDetailsQuery, 'sales.id');

        $salesDetails = $salesDetailsQuery->get([
            'id',
            'unit_id',
            'office_id',
            'sale_postcode',
            'created_at',
        ]);

        return response()->json([
            'total'     => array_sum($chartData),
            'chartData' => $chartData,
            'details'   => $salesDetails,
        ]);
    }
    public function getSalesAnalytic(Request $request)
    {
        $range = $request->input('range', 'month');

        if ($range === 'year') {
            $from = now()->startOfYear();
            $to = now()->endOfYear();
            $createdGrouping = 'MONTH(created_at)';
            $updatedGrouping = 'MONTH(updated_at)';
            $rangeLabels = collect(range(1, 12))->map(function ($month) {
                return Carbon::create()->month($month)->format('F');
            });
        } else {
            $from = now()->startOfMonth();
            $to = now()->endOfMonth();
            $createdGrouping = 'DATE(created_at)';
            $updatedGrouping = 'DATE(updated_at)';
            $daysInMonth = now()->daysInMonth;
            $rangeLabels = collect(range(1, $daysInMonth))->map(function ($day) {
                return now()->startOfMonth()->addDays($day - 1)->format('d M');
            });
        }

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

        $applySourceFilter = function ($query) use ($sourceIds) {
            if (count($sourceIds) > 0) {
                $query->where(function ($q) use ($sourceIds) {
                    $q->whereNotIn('job_source_id', $sourceIds)
                        ->orWhereNull('job_source_id');
                });
            }

            $this->excludePrivateSales($query, 'sales.id');

            return $query;
        };

        $keyByLabel = function ($item) use ($range) {
            if ($range === 'year') {
                return Carbon::create()->month((int) $item->label)->format('F');
            }

            return Carbon::parse($item->label)->format('d M');
        };

        // New / pending / reopened / closed / rejected are indexed by created_at.
        // New sales include open and pending creates. Updated sales are real edits
        // only (ignore the sale_uid write that bumps updated_at on insert).
        $untouchedSeconds = 10;
        $isTouched = "TIMESTAMPDIFF(SECOND, created_at, updated_at) > {$untouchedSeconds}";

        $query = Sale::selectRaw("$createdGrouping as label")
            ->selectRaw("SUM(CASE WHEN status = 1 AND is_re_open = 0 THEN 1 ELSE 0 END) as new_added")
            ->selectRaw("SUM(CASE WHEN status = 2 AND is_re_open = 0 AND {$isTouched} THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 1 AND is_re_open = 1 AND {$isTouched} THEN 1 ELSE 0 END) as reopened")
            ->selectRaw("SUM(CASE WHEN status = 0 AND {$isTouched} THEN 1 ELSE 0 END) as closed")
            ->selectRaw("SUM(CASE WHEN status = 3 AND {$isTouched} THEN 1 ELSE 0 END) as rejected")
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', [4, 5])
            ->whereNull('deleted_at');

        $applySourceFilter($query);

        $rawData = $query->groupBy(DB::raw($createdGrouping))
            ->orderBy(DB::raw($createdGrouping))
            ->get()
            ->keyBy($keyByLabel);

        // Updated sales are mutually exclusive from new sales (real edits only)
        // and are indexed on the chart by updated_at, not created_at.
        $updatedQuery = Sale::selectRaw("$updatedGrouping as label")
            ->selectRaw('COUNT(*) as updated')
            ->where('status', 1)
            ->where('is_re_open', 0)
            ->whereRaw($isTouched)
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotIn('status', [4, 5])
            ->whereNull('deleted_at');

        $applySourceFilter($updatedQuery);

        $updatedRawData = $updatedQuery->groupBy(DB::raw($updatedGrouping))
            ->orderBy(DB::raw($updatedGrouping))
            ->get()
            ->keyBy($keyByLabel);

        $labels = [];
        $new = [];
        $reopened = [];
        $closed = [];
        $pending = [];
        $rejected = [];
        $updated = [];

        foreach ($rangeLabels as $label) {
            $labels[] = $label;
            $new[] = isset($rawData[$label]) ? (int) $rawData[$label]->new_added : 0;
            $reopened[] = isset($rawData[$label]) ? (int) $rawData[$label]->reopened : 0;
            $closed[] = isset($rawData[$label]) ? (int) $rawData[$label]->closed : 0;
            $pending[] = isset($rawData[$label]) ? (int) $rawData[$label]->pending : 0;
            $rejected[] = isset($rawData[$label]) ? (int) $rawData[$label]->rejected : 0;
            $updated[] = isset($updatedRawData[$label]) ? (int) $updatedRawData[$label]->updated : 0;
        }

        return response()->json([
            'labels' => $labels,
            'new_added' => $new,
            'updated' => $updated,
            'reopened' => $reopened,
            'closed' => $closed,
            'pending' => $pending,
            'rejected' => $rejected,
        ]);
    }
    public function getUnreadMessages()
    {
        try {
            $messages = Message::query()
                ->where('status', 'incoming')
                ->where('module_type', 'Horsefly\\Applicant')
                ->where('is_read', 0)
                ->with(['user' => fn($query) => $query->select('id', 'name')])
                ->select('id', 'user_id', 'message', 'created_at')
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'user_name' => $message->applicant->applicant_name ?? 'Unknown',
                        'avatar' => asset('images/users/boy.png') ?? asset('images/users/default.jpg'),
                        'message' => Str::limit(strip_tags($message->message), 150),
                        'created_at' => $message->created_at->diffForHumans(),
                    ];
                });

            $unreadCount = Message::where('status', 'incoming')
                ->where('module_type', 'Horsefly\\Applicant')
                ->where('is_read', 0)
                ->count();

            return response()->json([
                'success' => true,
                'messages' => $messages,
                'unread_count' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch messages: ' . $e->getMessage(),
            ], 500);
        }
    }

    /************************ Private Functions ***************/
    private function generateJobDetailsModal($notification)
    {
        $modalId = 'jobDetailsModal_' . $notification->sale_id;  // Unique modal ID for each applicant's job details

        return '<div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . 'Label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-top modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="' . $modalId . 'Label">Job Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body modal-body-text-left">
                                <!-- Job details content will be dynamically inserted here -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>';
    }
    public function getUnreadNotifications()
    {
        try {
            $notifications = Notification::query()
                ->where('user_id', Auth::id())
                // Left join with the 'users' table to get the 'notify_by' user (sender)
                ->leftJoin('users as notify_by_users', 'notifications.notify_by', '=', 'notify_by_users.id')
                // Eager load the other relationships
                ->with([
                    'user' => fn($query) => $query->select('id', 'name'),  // Eager load the 'user' relationship (recipient of the notification)
                    'applicant' => fn($query) => $query->select('id', 'applicant_name'),  // Eager load the 'applicant' relationship
                    'sale' => fn($query) => $query->select('id', 'sale_postcode')  // Eager load the 'sale' relationship
                ])
                // Filter unread notifications
                // ->where('notifications.is_read', 0)
                ->select('notifications.*', 'notify_by_users.name as notify_by_name') // Select the 'name' of the notify_by user from the joined table
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($notification) {
                    return [
                        'id' => $notification->id,
                        'user_name' => $notification->user->name ?? 'Unknown',  // Access the 'name' field of the 'user' relationship
                        'notify_by' => $notification->notify_by_name ?? 'Unknown',  // Access the 'notify_by_name' (name of the user who triggered the notification)
                        'applicant_name' => $notification->applicant->applicant_name ?? 'Unknown',  // Access the 'applicant_name'
                        'sale_postcode' => $notification->sale->sale_postcode ?? 'Unknown',  // Access the 'sale_postcode'
                        'message' => Str::limit(strip_tags($notification->message), 150),
                        'created_at' => $notification->created_at->diffForHumans(),
                    ];
                });

            $unreadCount = Notification::where('user_id', Auth::id())->where('notifications.is_read', 0)
                ->count();

            return response()->json([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch notifications: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function getUserNotifications(Request $request)
    {
        Notification::where('is_read', 0)
            ->where('user_id', Auth::id())
            ->update(['is_read' => 1]);

        $notifications = Notification::query()
            ->where('user_id', Auth::id())
            // Left join with the 'users' table to get the 'notify_by' user (sender)
            ->leftJoin('users as notify_by_users', 'notifications.notify_by', '=', 'notify_by_users.id')
            // Eager load the other relationships for applicants and sales
            ->with([
                'user' => fn($query) => $query->select('id', 'name'), // Eager load the 'user' relationship
                'applicant' => fn($query) => $query->select(
                    'id',
                    'applicant_name',
                    'applicant_email',
                    'applicant_email_secondary',
                    'applicant_phone',
                    'applicant_phone_secondary',
                    'applicant_postcode'
                ), // Eager load the 'applicant' relationship
                'sale' => fn($query) => $query->with('jobCategory', 'jobTitle', 'office', 'unit') // Eager load sale with related jobCategory, jobTitle, office, and unit
            ])
            ->select('notifications.*', 'notify_by_users.name as notify_by_name') // Select 'name' of the notify_by user from the joined table
            ->latest();

        // Applying the search functionality if necessary
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                $notifications->where(function ($query) use ($searchTerm) {
                    $query->whereRaw('LOWER(notifications.message) LIKE ?', ["%{$searchTerm}%"])
                        ->orWhereHas('applicant', function ($q) use ($searchTerm) {
                            $q->whereRaw('LOWER(applicants.applicant_name) LIKE ?', ["%{$searchTerm}%"]);
                        })
                        ->orWhereHas('sale', function ($q) use ($searchTerm) {
                            $q->whereRaw('LOWER(sales.sale_postcode) LIKE ?', ["%{$searchTerm}%"]);
                        });
                });
            }
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $notifications->orderBy($orderColumn, $orderDirection);
            }
        } else {
            $notifications->orderBy('notifications.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($notifications)
                ->addIndexColumn() // Automatically adds a serial number to the rows
                ->addColumn('applicant_name', function ($notification) {
                    return $notification->applicant ? $notification->applicant->applicant_name : '-';
                })
                ->addColumn('applicant_email', function ($notification) {
                    return $notification->applicant ? $notification->applicant->applicant_email : '-';
                })
                ->addColumn('applicant_postcode', function ($notification) {
                    return $notification->applicant ? $notification->applicant->applicant_postcode : '-';
                })
                ->addColumn('sale_postcode', function ($notification) {
                    return $notification->sale ? $notification->sale->sale_postcode : '-';
                })
                ->addColumn('office_name', function ($notification) {
                    return $notification->sale->office ? $notification->sale->office->office_name : '-';
                })
                ->addColumn('unit_name', function ($notification) {
                    return $notification->sale->unit ? $notification->sale->unit->unit_name : '-';
                })
                ->addColumn('job_category', function ($notification) {
                    return $notification->sale && $notification->sale->jobCategory ? $notification->sale->jobCategory->name : '-';
                })
                ->addColumn('job_title', function ($notification) {
                    return $notification->sale && $notification->sale->jobTitle ? $notification->sale->jobTitle->name : '-';
                })
                ->addColumn('notify_by_name', function ($notification) {
                    return ucwords($notification->notify_by_name);
                })
                ->addColumn('notes_detail', function ($notification) {
                    return ucwords($notification->message);
                })
                ->addColumn('created_at', function ($notification) {
                    return Carbon::parse($notification->created_at)->format('d M Y, h:i A');
                })
                ->addColumn('job_details', function ($notification) {
                    $position_type = strtoupper(str_replace('-', ' ', $notification->sale->position_type));
                    $position = '<span class="badge bg-primary">' . htmlspecialchars($position_type, ENT_QUOTES) . '</span>';

                    if ($notification->sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($notification->sale->status == 0 && $notification->sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($notification->sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($notification->sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    // Escape HTML in $status for JavaScript (to prevent XSS)
                    $escapedStatus = htmlspecialchars($status, ENT_QUOTES);

                    // Prepare modal HTML for the "Job Details"
                    $modalHtml = $this->generateJobDetailsModal($notification);

                    // Return the action link with a modal trigger and the modal HTML
                    return '<a href="javascript:void(0);" class="dropdown-item" style="color: blue;" onclick="showDetailsModal('
                        . (int)$notification->sale_id . ','
                        . '\'' . htmlspecialchars(Carbon::parse($notification->sale->created_at)->format('d M Y, h:i A'), ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->office->office_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->unit->unit_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->sale_postcode, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->jobCategory->name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->jobTitle->name, ENT_QUOTES) . '\','
                        . '\'' . $escapedStatus . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->timing, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->experience, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->salary, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$position, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->qualification, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->benefits, ENT_QUOTES) . '\')">
                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>' . $modalHtml;
                })
                ->addColumn('action', function ($notification) {
                    $html = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" 
                                        href="javascript:void(0);" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmMarkRequestConfirmOrRejectModal' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '"
                                        data-applicant-id="' . (int)$notification->applicant_id . '"
                                        data-sale-id="' . (int)$notification->sale_id . '"
                                        onclick="crmMarkRequestConfirmOrRejectModal(' . (int)$notification->applicant_id . ', ' . (int)$notification->sale_id . ')">
                                        Mark Confirm / Reject CV
                                    </a></li>
                                </ul>
                            </div>';

                    // Modal for notification details
                    $html .= '<div class="modal fade" id="notificationDetailsModal' . $notification->id . '" tabindex="-1" aria-labelledby="notificationDetailsModalLabel' . $notification->id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="notificationDetailsModalLabel' . $notification->id . '">Notification Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p><strong>Notification:</strong> ' . $notification->message . '</p>
                                            <p><strong>Applicant Name:</strong> ' . ($notification->applicant ? $notification->applicant->applicant_name : '-') . '</p>
                                            <p><strong>Sale Postcode:</strong> ' . ($notification->sale ? $notification->sale->sale_postcode : '-') . '</p>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                    /** CRM Mark Confirm Or Reject Modal */
                    $html .= '<div id="crmMarkRequestConfirmOrRejectModal' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmMarkRequestConfirmOrRejectModalLabel' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-top">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="crmMarkRequestConfirmOrRejectModalLabel' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '">CRM Mark Request Confirm Or Reject</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body modal-body-text-left">
                                                <div class="notificationAlert' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . ' notification-alert"></div>
                                                <form action="" method="" id="crmMarkRequestConfirmOrRejectForm' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" class="form-horizontal">
                                                    <input type="hidden" name="applicant_id" value="' . (int)$notification->applicant_id . '">
                                                    <input type="hidden" name="sale_id" value="' . (int)$notification->sale_id . '">
                                                    <div class="mb-3">
                                                        <label for="details' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" class="form-label">Notes</label>
                                                        <textarea class="form-control" name="details" id="crmMarkRequestConfirmOrRejectDetails' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" rows="4" required></textarea>
                                                        <div class="invalid-feedback">Please provide details.</div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-primary savecrmMarkRequestButtonConfirm" data-applicant-id="' . (int)$notification->applicant_id . '" data-sale-id="' . (int)$notification->sale_id . '">Confirm</button>
                                                        <button type="button" class="btn btn-primary savecrmMarkRequestButtonReject" data-applicant-id="' . (int)$notification->applicant_id . '" data-sale-id="' . (int)$notification->sale_id . '">Reject</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>';

                    return $html;
                })
                ->rawColumns(['action', 'notify_by_name', 'applicant_name', 'notes_detail', 'job_details', 'unit_name', 'office_name', 'applicant_email', 'applicant_postcode', 'sale_postcode', 'job_category', 'job_title'])
                ->make(true);
        }
    }
    public function markNotificationsAsRead(Request $request)
    {
        // Mark notifications as read
        Notification::where('is_read', 0)
            ->where('user_id', Auth::id())
            ->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }
    public function getStats(Request $request)
    {
        $inputDate = $request->input('date_range');
        $range = $request->input('range');

        // ✅ Validate date format
        $validator = Validator::make(['date_range' => $inputDate, 'range' => $range], [
            'date_range' => 'required',
            'range' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        /* -------------------------
        DATE PARSING (CRITICAL)
        --------------------------*/

        if ($range === 'daily') {
            $startDate = Carbon::createFromFormat('Y-m-d', $inputDate)->startOfDay();
            $endDate   = Carbon::createFromFormat('Y-m-d', $inputDate)->endOfDay();
            $displayDate = $startDate->format('jS F Y');
        } elseif (in_array($range, ['weekly', 'aggregate'])) {
            [$start, $end] = explode(' to ', $inputDate);

            $startDate = Carbon::createFromFormat('Y-m-d', trim($start))->startOfDay();
            $endDate   = Carbon::createFromFormat('Y-m-d', trim($end))->endOfDay();
            $displayDate = $startDate->format('jS F') . ' - ' . $endDate->format('jS F Y');
        } elseif ($range === 'monthly') {
            $startDate = Carbon::createFromFormat('Y-m', $inputDate)->startOfMonth();
            $endDate   = Carbon::createFromFormat('Y-m', $inputDate)->endOfMonth();
            $displayDate = $startDate->format('F Y');
        } elseif ($range === 'yearly') {
            $startDate = Carbon::createFromFormat('Y', $inputDate)->startOfYear();
            $endDate   = Carbon::createFromFormat('Y', $inputDate)->endOfYear();
            $displayDate = $startDate->format('Y');
        }

        /** -------------------------
         *  APPLICANTS SECTION
         *  ------------------------*/
        $job_category_nurse = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        $nurses_created = Applicant::where([
            'status' => 1,
            'job_category_id' => $job_category_nurse->id ?? 0
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $nurses_updated = Applicant::where([
            'status' => 1,
            'job_category_id' => $job_category_nurse->id ?? 0
        ])
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at')
            ->count();

        $non_nurses_created = Applicant::where('status', 1)
            ->when($job_category_nurse, function ($q) use ($job_category_nurse) {
                $q->where('job_category_id', '!=', $job_category_nurse->id);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $non_nurses_updated = Applicant::where('status', 1)
            ->when($job_category_nurse, function ($q) use ($job_category_nurse) {
                $q->where('job_category_id', '!=', $job_category_nurse->id);
            })
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at')
            ->count();

        $callbacks_created = ApplicantNote::where('moved_tab_to', '=', 'callback')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $callbacks_updated = ApplicantNote::where('moved_tab_to', '=', 'callback')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at')
            ->count();

        $not_interested_created = Applicant::join('applicants_pivot_sales', 'applicants_pivot_sales.applicant_id', '=', 'applicants.id')
            ->where('applicants.status', 1)
            ->where('applicants_pivot_sales.is_interested', 0)
            ->whereBetween('applicants_pivot_sales.created_at', [$startDate, $endDate])
            ->count();

        $not_interested_updated = Applicant::join('applicants_pivot_sales', 'applicants_pivot_sales.applicant_id', '=', 'applicants.id')
            ->where('applicants.status', 1)
            ->where('applicants_pivot_sales.is_interested', 0)
            ->whereBetween('applicants_pivot_sales.updated_at', [$startDate, $endDate])
            ->whereColumn('applicants_pivot_sales.updated_at', '!=', 'applicants_pivot_sales.created_at')
            ->count();

        // Leave out sales (and their audits / CVs / history) under offices or units
        // whose notes contain a private word, matching the list pages.
        $hidePrivate = fn ($query, string $saleIdColumn) => $this->excludePrivateSales($query, $saleIdColumn);

        /** -------------------------
         *  SALES SECTION
         *  ------------------------*/
        $open_sales_created = $hidePrivate(Sale::where('status', 1)
            ->whereBetween('created_at', [$startDate, $endDate]), 'sales.id')
            ->count();

        $open_sales_updated = $hidePrivate(Sale::where('status', 1)
            ->where('is_re_open', 0)
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at'), 'sales.id')
            ->count();

        $reopen_sales_updated = $hidePrivate(Sale::where('status', 1)
            ->where('is_re_open', 1)
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at'), 'sales.id')
            ->count();

        $close_sales_created = $hidePrivate(Audit::where('message', 'sale-closed')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereBetween('created_at', [$startDate, $endDate]), 'audits.auditable_id')
            ->count();

        $close_sales_updated = $hidePrivate(Audit::where('message', 'sale-closed')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at'), 'audits.auditable_id')
            ->count();

        $pending_sales_created = $hidePrivate(Sale::where('status', 'pending')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at'), 'sales.id')
            ->count();

        $pending_sales_updated = $hidePrivate(Sale::where('status', 'pending')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at'), 'sales.id')
            ->count();

        $rejected_sales_created = $hidePrivate(Audit::where('message', 'sale-rejected')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereBetween('created_at', [$startDate, $endDate]), 'audits.auditable_id')
            ->count();

        $rejected_sales_updated = $hidePrivate(Audit::where('message', 'sale-rejected')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->whereColumn('updated_at', '!=', 'created_at'), 'audits.auditable_id')
            ->count();

        /** -------------------------
         *  QUALITY SECTION
         *  ------------------------*/
        $requested_cvs = $hidePrivate(CVNote::whereBetween('created_at', [$startDate, $endDate]), 'cv_notes.sale_id')
            ->count();

        $rejected_cvs = $hidePrivate(History::where('sub_stage', 'quality_reject')
            ->where('status', 'active')
            ->whereBetween('created_at', [$startDate, $endDate]), 'history.sale_id')
            ->count();

        $cleared_cvs = $hidePrivate(History::where('sub_stage', 'quality_cleared')
            ->whereBetween('created_at', [$startDate, $endDate]), 'history.sale_id')
            ->count();

        $open_cvs = $hidePrivate(History::where('sub_stage', 'quality_cvs_hold')
            ->whereBetween('created_at', [$startDate, $endDate]), 'history.sale_id')
            ->count();

        /** -------------------------
         *  FINAL RESPONSE
         *  ------------------------*/
        return response()->json([
            'date' => $displayDate,
            'applicants' => [
                'nurses' => [
                    'created' => $nurses_created,
                    'updated' => $nurses_updated,
                ],
                'non_nurses' => [
                    'created' => $non_nurses_created,
                    'updated' => $non_nurses_updated,
                ],
                'callbacks' => [
                    'created' => $callbacks_created,
                    'updated' => $callbacks_updated,
                ],
                'not_interested' => [
                    'created' => $not_interested_created,
                    'updated' => $not_interested_updated,
                ],
            ],
            'sales' => [
                'open' => [
                    'created' => $open_sales_created,
                    'updated' => $open_sales_updated,
                ],
                'reopen' => $reopen_sales_updated,
                'close' => [
                    'created' => $close_sales_created,
                    'updated' => $close_sales_updated,
                ],
                'pending' => [
                    'created' => $pending_sales_created,
                    'updated' => $pending_sales_updated,
                ],
                'rejected' => [
                    'created' => $rejected_sales_created,
                    'updated' => $rejected_sales_updated,
                ],
            ],
            'quality' => [
                'requested_cvs' => $requested_cvs,
                'rejected_cvs' => $rejected_cvs,
                'cleared_cvs' => $cleared_cvs,
                'open_cvs' => $open_cvs,
            ]
        ]);
    }
    public function getStatisticsDetails(Request $request)
    {
        $type = $request->input('type'); // e.g. "nurses", "non_nurses", etc.
        $inputDate = $request->input('date_range');
        $range = $request->input('range');

        // ✅ Validate date format
        $validator = Validator::make(['date_range' => $inputDate, 'range' => $range], [
            'date_range' => 'required',
            'range' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        /* -------------------------
        DATE PARSING (CRITICAL)
        --------------------------*/
        if ($range === 'daily') {
            $startDate = Carbon::createFromFormat('Y-m-d', $inputDate)->startOfDay();
            $endDate   = Carbon::createFromFormat('Y-m-d', $inputDate)->endOfDay();
            $displayDate = $startDate->format('jS F Y');
        } elseif (in_array($range, ['weekly', 'aggregate'])) {
            [$start, $end] = explode(' to ', $inputDate);

            $startDate = Carbon::createFromFormat('Y-m-d', trim($start))->startOfDay();
            $endDate   = Carbon::createFromFormat('Y-m-d', trim($end))->endOfDay();
            $displayDate = $startDate->format('jS F') . ' - ' . $endDate->format('jS F Y');
        } elseif ($range === 'monthly') {
            $startDate = Carbon::createFromFormat('Y-m', $inputDate)->startOfMonth();
            $endDate   = Carbon::createFromFormat('Y-m', $inputDate)->endOfMonth();
            $displayDate = $startDate->format('F Y');
        } elseif ($range === 'yearly') {
            $startDate = Carbon::createFromFormat('Y', $inputDate)->startOfYear();
            $endDate   = Carbon::createFromFormat('Y', $inputDate)->endOfYear();
            $displayDate = $startDate->format('Y');
        }

        /** -------------------------
         *  APPLICANTS SECTION
         *  ------------------------*/
        $job_category_nurse = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        // Get the base query for Applicants filtered by date
        $baseQuery = Applicant::query()
            ->where('status', 1);

        // Filter by applicant type (based on clicked box)
        $job_category_nurse = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        if ($job_category_nurse) {
            switch ($type) {
                case 'nurses-created':
                    $baseQuery->where('job_category_id', $job_category_nurse->id)
                        ->whereBetween('applicants.created_at', [$startDate, $endDate]);
                    break;

                case 'non-nurses-created':
                    $baseQuery->where('job_category_id', '!=', $job_category_nurse->id)
                        ->whereBetween('applicants.created_at', [$startDate, $endDate]);
                    break;

                case 'nurses-updated':
                    $baseQuery->where('job_category_id', $job_category_nurse->id)
                        ->whereBetween('applicants.updated_at', [$startDate, $endDate])
                        ->whereColumn('applicants.updated_at', '!=', 'applicants.created_at');
                    break;

                case 'non-nurses-updated':
                    $baseQuery->where('job_category_id', '!=', $job_category_nurse->id)
                        ->whereBetween('applicants.updated_at', [$startDate, $endDate])
                        ->whereColumn('applicants.updated_at', '!=', 'applicants.created_at');
                    break;
            }
        }

        // ✅ Group by job_type (regular / specialist)
        $jobTypeCounts = (clone $baseQuery)
            ->select('job_type', DB::raw('COUNT(*) as total'))
            ->groupBy('job_type')
            ->pluck('total', 'job_type');


        // ✅ Group by job_source (join job_sources table)
        $jobSources = (clone $baseQuery)
            ->join('job_sources', 'job_sources.id', '=', 'applicants.job_source_id')
            ->select('job_sources.name', DB::raw('COUNT(applicants.id) as total'))
            ->groupBy('job_sources.name')
            ->orderByDesc('total')
            ->pluck('total', 'job_sources.name');


        return response()->json([
            'title' => ucfirst(str_replace('-', ' ', $type)) . ' Applicants',
            'job_types' => [
                'regular' => $jobTypeCounts['regular'] ?? 0,
                'specialist' => $jobTypeCounts['specialist'] ?? 0,
            ],
            'sources' => $jobSources
        ]);
    }

    // Private function returning Builder
    private function getStageQuery(
        $historyStage = null,
        $revertStage = null,
        $crmStage = null,
        $startDate = null,
        $endDate = null,
        $historyActive = null,
        $revertActive = null
    ) {
        $query = Applicant::query();

        // Each stage row is matched only for sales that are not hidden by private
        // office/unit notes, so the chart and its drill-down match the CRM pages.
        if ($historyStage) {
            $query->whereHas('history', function ($q) use ($historyStage, $startDate, $endDate, $historyActive) {
                $q->whereIn('sub_stage', (array)$historyStage)
                    ->when($historyActive !== null, fn($q2) => $q2->where('status', $historyActive))
                    ->when($startDate && $endDate, fn($q2) => $q2->whereBetween('created_at', [$startDate, $endDate]));
                $this->excludePrivateSales($q, $q->qualifyColumn('sale_id'));
            });
        }

        if ($revertStage) {
            $query->whereHas('revertStages', function ($q) use ($revertStage, $startDate, $endDate, $revertActive) {
                $q->whereIn('stage', (array)$revertStage)
                    ->when($revertActive !== null, fn($q2) => $q2->where('status', $revertActive))
                    ->when($startDate && $endDate, fn($q2) => $q2->whereBetween('created_at', [$startDate, $endDate]));
                $this->excludePrivateSales($q, $q->qualifyColumn('sale_id'));
            });
        }

        if ($crmStage) {
            $query->whereHas('crmNotes', function ($q) use ($crmStage, $startDate, $endDate) {
                $q->whereIn('moved_tab_to', (array)$crmStage)
                    ->when($startDate && $endDate, fn($q2) => $q2->whereBetween('created_at', [$startDate, $endDate]));
                $this->excludePrivateSales($q, $q->qualifyColumn('sale_id'));
            });
        }

        return $query; // This is **Eloquent Builder**, not count
    }

    // Private function returning integer count
    private function getStageCount(
        $historyStage = null,
        $revertStage = null,
        $crmStage = null,
        $startDate = null,
        $endDate = null,
        $historyActive = null,
        $revertActive = null
    ) {
        return (int) $this->getStageQuery(
            $historyStage,
            $revertStage,
            $crmStage,
            $startDate,
            $endDate,
            $historyActive,
            $revertActive
        )->distinct()->count('id');
    }

    public function getChartData(Request $request)
    {
        $range = $request->input('range');
        $inputDate = $request->input('date_range');

        $validator = Validator::make($request->all(), [
            'range' => 'required',
            'date_range' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        [$startDate, $endDate, $displayDate] = $this->parseDateRange($range, $inputDate);

        $daily_data = [];

        // CRM counts
        $daily_data['crm_sent_cvs'] = $this->getStageCount('quality_cleared', null, null, $startDate, $endDate);
        $daily_data['crm_open_cvs'] = $this->getStageCount('quality_cvs_hold', null, null, $startDate, $endDate);
        $daily_data['crm_rejected'] = $this->getStageCount('crm_reject', null, null, $startDate, $endDate, true);
        $daily_data['crm_requested'] = $this->getStageCount('crm_request', null, null, $startDate, $endDate);
        $daily_data['crm_request_rejected'] = $this->getStageCount('crm_request_reject', null, null, $startDate, $endDate, true);
        $daily_data['crm_confirmed'] = $this->getStageCount('crm_request_confirm', null, null, $startDate, $endDate);
        $daily_data['crm_rebook'] = $this->getStageCount('crm_rebook', null, null, $startDate, $endDate);
        $daily_data['crm_prestart_attended'] = $this->getStageCount('crm_interview_attended', null, null, $startDate, $endDate);
        $daily_data['crm_declined'] = $this->getStageCount('crm_declined', null, null, $startDate, $endDate, true);
        $daily_data['crm_not_attended'] = $this->getStageCount('crm_interview_not_attended', null, null, $startDate, $endDate, true);
        $daily_data['crm_date_started'] = $this->getStageCount(['crm_start_date', 'crm_start_date_back'], null, null, $startDate, $endDate);
        $daily_data['crm_start_date_hold'] = $this->getStageCount('crm_start_date_hold', null, null, $startDate, $endDate, true);
        $daily_data['crm_invoiced'] = $this->getStageCount('crm_invoice', null, null, $startDate, $endDate);
        $daily_data['crm_disputed'] = $this->getStageCount('crm_dispute', null, null, $startDate, $endDate, true);
        $daily_data['crm_paid'] = $this->getStageCount('crm_paid', null, null, $startDate, $endDate);
        // Revert counts
        $daily_data['crm_revert'] = $this->getStageCount(null, 'crm_revert', null, $startDate, $endDate, null, true);
        $daily_data['quality_revert'] = $this->getStageCount(null, 'quality_revert', null, $startDate, $endDate, null, true);

        // Chart response
        return response()->json([
            'date' => $displayDate,
            'labels' => [
                'Sent CVs',
                'Open CVs',
                'Rejected CVs',
                'Requested CVs',
                'Rejected By Request',
                'Confirmation',
                'Rebook',
                'Attended (Pre-Start)',
                'Declined',
                'Not Attended',
                'Start Date',
                'Start Date Hold',
                'Invoice',
                'Dispute',
                'Paid',
                'Crm Revert',
                'Quality Revert'
            ],
            'series' => array_values($daily_data),
        ]);
    }

    private function getStatusConfig($status)
    {
        return [

            'crm_sent_cvs' => [
                'history' => ['quality_cleared']
            ],

            'crm_open_cvs' => [
                'history' => ['quality_cvs_hold']
            ],

            'crm_rejected' => [
                'history' => ['crm_reject'],
                'history_active' => 1
            ],

            'crm_requested' => [
                'history' => ['crm_request']
            ],

            'crm_request_rejected' => [
                'history' => ['crm_request_reject'],
                'history_active' => 1
            ],

            'crm_confirmed' => [
                'history' => ['crm_request_confirm']
            ],

            'crm_rebook' => [
                'history' => ['crm_rebook']
            ],

            'crm_prestart_attended' => [
                'history' => ['crm_interview_attended']
            ],

            'crm_declined' => [
                'history' => ['crm_declined'],
                'history_active' => 1
            ],

            'crm_not_attended' => [
                'history' => ['crm_interview_not_attended'],
                'history_active' => 1
            ],

            'crm_date_started' => [
                'history' => ['crm_start_date', 'crm_start_date_back']
            ],

            'crm_start_date_hold' => [
                'history' => ['crm_start_date_hold'],
                'history_active' => 1
            ],

            'crm_invoiced' => [
                'history' => ['crm_invoice']
            ],

            'crm_disputed' => [
                'history' => ['crm_dispute'],
                'history_active' => 1
            ],

            'crm_paid' => [
                'history' => ['crm_paid']
            ],

            'crm_revert' => [
                'revert' => ['crm_revert'],
                'revert_active' => 1
            ],

            'quality_revert' => [
                'revert' => ['quality_revert'],
                'revert_active' => 1
            ],

        ][$status] ?? null;
    }

    public function getStatusDetails(Request $request)
    {
        $status = $request->input('status');
        $range = $request->input('range');
        $inputDate = $request->input('date_range');

        [$startDate, $endDate, $displayDate] = $this->parseDateRange($range, $inputDate);

        $nurseCategory = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        if (!$nurseCategory) {
            return response()->json([
                'title' => 'No Nurse Category Found',
                'nurses' => 0,
                'non_nurses' => 0
            ]);
        }

        /**
         * SAME STATUS LOGIC AS getChartData()
         */
        $config = $this->getStatusConfig($status);

        if (!$config) {
            return response()->json([
                'title' => ucfirst(str_replace('_', ' ', $status)),
                'nurses' => 0,
                'non_nurses' => 0
            ]);
        }

        // 🔥 Use your existing reusable function
        $applicantQuery = $this->getStageQuery(
            $config['history'] ?? null,
            $config['revert'] ?? null,
            null,
            $startDate,
            $endDate,
            $config['history_active'] ?? null,
            $config['revert_active'] ?? null
        )->with(['jobCategory', 'jobTitle', 'jobSource']);

        $statsQuery = (clone $applicantQuery)->setEagerLoads([]);

        // Job source stats
        $jobSourceStats = (clone $statsQuery)
            ->join('job_sources', 'job_sources.id', '=', 'applicants.job_source_id')
            ->selectRaw('job_sources.name, COUNT(DISTINCT applicants.id) as total')
            ->groupBy('job_sources.name')
            ->orderByDesc('total')
            ->get();

        $rejectionReasons = [];
        if ($status === 'crm_rejected') {
            // Same date/stage as the header counts: one reason per applicant
            // from the latest crm_rejected_cv that matches a crm_reject history
            // row in this range (applicant + sale). That stops extra reject
            // rows from inflating the table above the distinct applicant totals.
            $latestRejectSub = DB::table('crm_rejected_cv as crc')
                ->join('history as h', function ($join) use ($startDate, $endDate) {
                    $join->on('h.applicant_id', '=', 'crc.applicant_id')
                        ->on('h.sale_id', '=', 'crc.sale_id')
                        ->where('h.sub_stage', '=', 'crm_reject')
                        ->where('h.status', '=', 1)
                        ->whereBetween('h.created_at', [$startDate, $endDate]);
                })
                ->where('crc.status', 1)
                ->select('crc.applicant_id', DB::raw('MAX(crc.id) as latest_id'))
                ->groupBy('crc.applicant_id');

            $reasonRows = (clone $statsQuery)
                ->leftJoinSub($latestRejectSub, 'latest_reject', 'latest_reject.applicant_id', '=', 'applicants.id')
                ->leftJoin('crm_rejected_cv as reject_row', 'reject_row.id', '=', 'latest_reject.latest_id')
                ->select([
                    'reject_row.reason',
                    'applicants.job_type',
                    'applicants.job_category_id',
                    DB::raw('COUNT(DISTINCT applicants.id) as total'),
                ])
                ->groupBy('reject_row.reason', 'applicants.job_type', 'applicants.job_category_id')
                ->get();

            $reasons = [];
            foreach ($reasonRows as $row) {
                $key = $row->reason !== null && $row->reason !== '' ? (string) $row->reason : 'unknown';
                if (!isset($reasons[$key])) {
                    $reasons[$key] = [
                        'reason' => $key,
                        'label' => $this->formatRejectReason($key),
                        'nurses_regular' => 0,
                        'nurses_specialist' => 0,
                        'non_nurses_regular' => 0,
                        'non_nurses_specialist' => 0,
                        'total' => 0,
                    ];
                }

                $isNurse = (int) $row->job_category_id === (int) $nurseCategory->id;
                $isSpecialist = strtolower((string) $row->job_type) === 'specialist';
                $bucket = ($isNurse ? 'nurses_' : 'non_nurses_') . ($isSpecialist ? 'specialist' : 'regular');
                $count = (int) $row->total;
                $reasons[$key][$bucket] += $count;
                $reasons[$key]['total'] += $count;
            }

            $rejectionReasons = array_values($reasons);
            usort($rejectionReasons, fn($a, $b) => $b['total'] <=> $a['total']);
        }

        return response()->json([
            'title' => Str::title(str_replace('_', ' ', $status)) . " ({$displayDate})",
            'crm_status' => $status,

            'nurses_regular' => (clone $statsQuery)
                ->where('applicants.job_category_id', $nurseCategory->id)
                ->where('applicants.job_type', 'regular')
                ->distinct()
                ->count('applicants.id'),

            'nurses_specialist' => (clone $statsQuery)
                ->where('applicants.job_category_id', $nurseCategory->id)
                ->where('applicants.job_type', 'specialist')
                ->distinct()
                ->count('applicants.id'),

            'non_nurses_regular' => (clone $statsQuery)
                ->where(function ($q) use ($nurseCategory) {
                    $q->where('applicants.job_category_id', '!=', $nurseCategory->id)
                        ->orWhereNull('applicants.job_category_id');
                })
                ->where('applicants.job_type', 'regular')
                ->distinct()
                ->count('applicants.id'),

            'non_nurses_specialist' => (clone $statsQuery)
                ->where(function ($q) use ($nurseCategory) {
                    $q->where('applicants.job_category_id', '!=', $nurseCategory->id)
                        ->orWhereNull('applicants.job_category_id');
                })
                ->where('applicants.job_type', 'specialist')
                ->distinct()
                ->count('applicants.id'),

            'job_sources' => $jobSourceStats,
            'rejection_reasons' => $rejectionReasons,
        ]);
    }

    private function formatRejectReason(?string $reason): string
    {
        return match ($reason) {
            'position_filled' => 'Position Filled',
            'agency' => 'Sent By Another Agency',
            'manager' => 'Rejected By Manager',
            'no_response' => 'No Response',
            'update_notes' => 'Update Notes',
            'unknown', '', null => 'Unknown',
            default => Str::title(str_replace('_', ' ', (string) $reason)),
        };
    }

    public function statisticsReportIndex(Request $request)
    {
        $status = $request->input('status');
        $categoryTitle = $request->input('category'); // nurses / non_nurses
        $type = $request->input('type');         // regular / specialist
        $range = $request->input('range');
        $date_range = $request->input('date_range');

        [$startDate, $endDate] = $this->parseDateRange($range, $date_range);

        $formatted_startDate = Carbon::parse($startDate)->format('d M Y');
        $formatted_endDate = Carbon::parse($endDate)->format('d M Y');

        if ($categoryTitle == 'nurses') {
            $jobCategoryID = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->pluck('id')->toArray();
        } else {
            $jobCategoryID = JobCategory::whereRaw('LOWER(name) != ?', ['nurse'])->pluck('id')->toArray();
        }

        $jobTitles = JobTitle::whereIn('job_category_id', $jobCategoryID)->where('type', $type)->where('is_active', 1)->orderBy('name', 'asc')->get();

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

        return view('dashboards.statistics_applicants_list', compact(
            'categoryTitle',
            'jobSources',
            'jobTitles',
            'status',
            'type',
            'range',
            'date_range',
            'formatted_startDate',
            'formatted_endDate'
        ));
    }
    public function getStatisticsApplicants(Request $request)
    {
        $range = $request->input('range', ''); // Default is empty (no filter)
        $dateRange = $request->input('date_range', ''); // Default is empty (no filter)
        $category = $request->input('category', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', []);
        $sourceFilter = $request->input('source_filter', []);
        $status = $request->input('status', ''); // Default is empty (no filter)
        $type = $request->input('type', ''); // Default is empty (no filter)

        // parse dates same as before
        [$startDate, $endDate] = $this->parseDateRange($range, $dateRange);

        $nurseCategory = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        $titleFilter = array_values(array_filter((array) $titleFilter, fn($v) => $v !== '' && $v !== null));
        $sourceFilter = array_values(array_filter((array) $sourceFilter, fn($v) => $v !== '' && $v !== null));

        if (!$nurseCategory) {
            return response()->json(['error' => 'Nurse category not found']);
        }

        $config = $this->getStatusConfig($status) ?? ['history' => ['quality_cleared']];
        $isRevertStatus = isset($config['revert']);

        // One row per applicant: the latest matching event in the range, which also
        // gives the sale. Same stages/active flags as the dashboard counts.
        $eventTable = $isRevertStatus ? 'revert_stages' : 'history';
        $latestEvent = DB::table($eventTable)
            ->select('applicant_id', DB::raw('MAX(id) as event_id'))
            ->whereIn($isRevertStatus ? 'stage' : 'sub_stage', $isRevertStatus ? $config['revert'] : $config['history'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when(!$isRevertStatus && isset($config['history_active']), fn($q) => $q->where('status', $config['history_active']))
            ->groupBy('applicant_id');

        $query = Applicant::query()
            ->joinSub($latestEvent, 'latest_event', 'latest_event.applicant_id', '=', 'applicants.id')
            ->join($eventTable . ' as ev', 'ev.id', '=', 'latest_event.event_id')
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->with(['jobTitle', 'jobCategory', 'jobSource']);

        // Filter by category
        if ($category === 'nurses') {
            $query->where('applicants.job_category_id', $nurseCategory->id);
        } elseif ($category === 'non_nurses') {
            $query->where(function ($q) use ($nurseCategory) {
                $q->where('applicants.job_category_id', '!=', $nurseCategory->id)
                    ->orWhereNull('applicants.job_category_id');
            });
        }

        if ($type) {
            $query->where('applicants.job_type', $type);
        }

        if (!empty($titleFilter)) {
            $query->whereIn('applicants.job_title_id', $titleFilter);
        }

        if (!empty($sourceFilter)) {
            $query->whereIn('applicants.job_source_id', $sourceFilter);
        }

        $query->select([
            'applicants.id',
            'applicants.applicant_name',
            'applicants.applicant_email',
            'applicants.applicant_email_secondary',
            'applicants.applicant_phone',
            'applicants.applicant_phone_secondary',
            'applicants.applicant_landline',
            'applicants.applicant_postcode',
            'applicants.applicant_experience',
            'applicants.is_blocked',
            'applicants.lat',
            'applicants.lng',
            'applicants.job_category_id',
            'applicants.job_title_id',
            'applicants.job_source_id',
            'applicants.job_type',
            'applicants.applicant_notes',
            'applicants.applicant_cv',
            'applicants.updated_cv',
            'applicants.created_at',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'job_sources.name as job_source_name',
        ]);

        if ($isRevertStatus) {
            $notesColumn = 'ev.notes';
            $dateColumn = 'ev.created_at';
            $query->leftJoin('users', 'ev.user_id', '=', 'users.id')
                ->addSelect(['ev.notes as notes_detail', 'users.name as user_name', 'ev.created_at as notes_created_at']);
        } else {
            $crmNoteMap = [
                'crm_sent_cvs' => ['cv_sent', 'cv_sent_saved'],
                'crm_open_cvs' => ['quality_cvs_hold'],
                'crm_rejected' => ['crm_reject'],
                'crm_requested' => ['crm_request'],
                'crm_request_rejected' => ['crm_request_reject'],
                'crm_confirmed' => ['crm_request_confirm'],
                'crm_rebook' => ['crm_rebook'],
                'crm_prestart_attended' => ['crm_interview_attended'],
                'crm_declined' => ['crm_declined'],
                'crm_not_attended' => ['crm_interview_not_attended'],
                'crm_date_started' => ['crm_start_date', 'crm_start_date_back'],
                'crm_start_date_hold' => ['crm_start_date_hold'],
                'crm_invoiced' => ['crm_invoice'],
                'crm_disputed' => ['crm_dispute'],
                'crm_paid' => ['crm_paid'],
            ];
            $crmSubStages = $crmNoteMap[$status] ?? ['cv_sent', 'cv_sent_saved'];

            // Latest CRM note for the same applicant + sale as the event. Correlated
            // per row (uses the applicant_id index) instead of grouping the whole table.
            $latestCrmId = DB::table('crm_notes as cn')
                ->selectRaw('MAX(cn.id)')
                ->whereColumn('cn.applicant_id', 'ev.applicant_id')
                ->whereColumn('cn.sale_id', 'ev.sale_id')
                ->whereIn('cn.moved_tab_to', $crmSubStages)
                ->whereBetween('cn.created_at', [$startDate, $endDate]);

            // Agent who sent the CV for that sale
            $latestCvId = DB::table('cv_notes as cvn')
                ->selectRaw('MAX(cvn.id)')
                ->whereColumn('cvn.applicant_id', 'ev.applicant_id')
                ->whereColumn('cvn.sale_id', 'ev.sale_id');

            $notesColumn = 'crm_notes.details';
            // Date = CRM note time; falls back to the stage event when no note exists
            $dateColumn = 'COALESCE(crm_notes.created_at, ev.created_at)';
            $query->leftJoin('crm_notes', function ($join) use ($latestCrmId) {
                $join->whereRaw('crm_notes.id = (' . $latestCrmId->toSql() . ')', $latestCrmId->getBindings());
            })
                ->leftJoin('cv_notes', function ($join) use ($latestCvId) {
                    $join->whereRaw('cv_notes.id = (' . $latestCvId->toSql() . ')', $latestCvId->getBindings());
                })
                ->leftJoin('users', DB::raw('COALESCE(cv_notes.user_id, ev.user_id)'), '=', 'users.id')
                ->addSelect(['crm_notes.details as notes_detail', 'users.name as user_name', DB::raw($dateColumn . ' as notes_created_at')]);
        }

        $query->leftJoin('sales', 'ev.sale_id', '=', 'sales.id')
            ->leftJoin('offices as sale_offices', 'sales.office_id', '=', 'sale_offices.id')
            ->leftJoin('units as sale_units', 'sales.unit_id', '=', 'sale_units.id')
            ->leftJoin('job_titles as sale_job_titles', 'sales.job_title_id', '=', 'sale_job_titles.id')
            ->leftJoin('job_sources as sale_job_sources', 'sales.job_source_id', '=', 'sale_job_sources.id')
            ->leftJoin('job_categories as sale_job_categories', 'sales.job_category_id', '=', 'sale_job_categories.id')
            ->addSelect([
                'sales.id as sale_id',
                'sale_job_titles.name as sale_job_title',
                'sale_offices.office_name as office_name',
                'sale_units.unit_name as unit_name',
                'sales.sale_postcode as sale_postcode',
                'sale_job_sources.name as sale_source_name',
                'sale_job_categories.name as sale_category_name',
                'sales.position_type as sale_position_type',
                'sales.job_type as sale_job_type',
                'sales.timing as sale_timing',
                'sales.status as sale_status',
                'sales.is_on_hold as sale_is_on_hold',
                'sales.created_at as sale_posted_date',
                'sales.experience as sale_experience',
                'sales.salary as sale_salary',
                'sales.qualification as sale_qualification',
                'sales.benefits as sale_benefits',
            ]);

        // Global search is applied in ->filter() below so DataTables does not try
        // to search computed columns (show_created_at, notes_details, ...) as SQL.
        $applySearch = function ($query) use ($request, $notesColumn) {
            $searchTerm = strtolower(trim((string) $request->input('search.value', '')));
            if ($searchTerm === '') {
                return;
            }
            $likeSearchTerm = '%' . $searchTerm . '%';
            $digits = preg_replace('/[^0-9]/', '', $searchTerm);

            $query->where(function ($searchQuery) use ($likeSearchTerm, $notesColumn, $digits) {
                foreach (
                    [
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_experience',
                        'job_titles.name',
                        'job_categories.name',
                        'job_sources.name',
                        'users.name',
                        'sales.sale_postcode',
                        'sale_job_titles.name',
                        'sale_offices.office_name',
                        'sale_units.unit_name',
                        'sale_job_sources.name',
                        $notesColumn,
                    ] as $column
                ) {
                    $searchQuery->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$likeSearchTerm]);
                }

                if (strlen($digits) >= 3) {
                    foreach (['applicants.applicant_phone', 'applicants.applicant_phone_secondary', 'applicants.applicant_landline'] as $column) {
                        $searchQuery->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%' . $digits . '%']);
                    }
                }
            });
        };

        if ($request->ajax()) {
            $jobTitleNamesById = JobTitle::pluck('name', 'id');
            $jobCategoryNamesById = JobCategory::pluck('name', 'id');

            // Copy icon handled by the global .copy-postcode listener in footer-scripts
            $copyPostcodeBtn = function (?string $postcode): string {
                $postcode = strtoupper(trim((string) $postcode));
                if ($postcode === '') {
                    return '';
                }

                return '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-1 copy-postcode"
                            data-postcode="' . e($postcode) . '" title="Copy Postcode">
                            <iconify-icon icon="solar:copy-linear" class="fs-16 align-middle"></iconify-icon>
                        </button>';
            };

            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->filter($applySearch)
                ->orderColumn('show_created_at', $dateColumn . ' $1')
                ->orderColumn('user_name', 'users.name $1')
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($applicant) {
                    $type = $applicant->job_type;
                    $stype = $type === 'specialist'
                        ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                        : '';

                    if (!$applicant->jobCategory) {
                        return '-';
                    }

                    return e($applicant->jobCategory->name) . $stype;
                })
                ->addColumn('job_details', function ($applicant) {
                    $position_type = strtoupper(str_replace('-', ' ', $applicant->sale_position_type ?? ''));
                    $status = '-';
                    $statusClass = 'bg-secondary';
                    if ($applicant->sale_status == 1) {
                        $status = 'Active';
                        $statusClass = 'bg-success';
                    } elseif ($applicant->sale_status == 0 && $applicant->sale_is_on_hold == 0) {
                        $status = 'Closed';
                        $statusClass = 'bg-danger';
                    } elseif ($applicant->sale_status == 2) {
                        $status = 'Pending';
                        $statusClass = 'bg-warning text-dark';
                    } elseif ($applicant->sale_status == 3) {
                        $status = 'Rejected';
                        $statusClass = 'bg-danger';
                    }

                    $sale_source_name = '';
                    if ($applicant->sale_source_name) {
                        $sale_source_name = '<span class="badge bg-light text-dark">' . e($applicant->sale_source_name) . '</span>';
                    }

                    $postcode = strtoupper($applicant->sale_postcode);
                    $posted_date = Carbon::parse($applicant->sale_posted_date)->format('d M Y, h:i A');
                    $office_name = ucwords($applicant->office_name) ?? '-';
                    $unit_name = ucwords($applicant->unit_name) ?? '-';
                    $jobTitle = $applicant->sale_job_title ? strtoupper($applicant->sale_job_title) : '-';
                    $stype  = $applicant->sale_job_type && $applicant->sale_job_type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $categoryName = $applicant->sale_category_name ? strtoupper($applicant->sale_category_name) . $stype : '-';

                    $jobData = [
                        'sale_id'       => (int)$applicant->sale_id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $categoryName,
                        'job_title'     => $jobTitle,
                        'sale_source_name' => $sale_source_name,
                        'status'        => $status,
                        'status_class'  => $statusClass,
                        'timing'        => $applicant->sale_timing,
                        'experience'    => $applicant->sale_experience,
                        'salary'        => $applicant->sale_salary,
                        'position'      => $position_type,
                        'qualification' => $applicant->sale_qualification,
                        'benefits'      => $applicant->sale_benefits,
                    ];

                    return '<a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                        $jobData,
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                    ) . '\'>
                            <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>';
                })
                ->editColumn('office_name', function ($applicant) {
                    return $applicant->office_name ?: '-';
                })
                ->editColumn('unit_name', function ($applicant) {
                    return $applicant->unit_name ?: '-';
                })
                ->editColumn('sale_postcode', function ($applicant) use ($copyPostcodeBtn) {
                    if (!$applicant->sale_postcode) {
                        return '-';
                    }

                    return '<span class="text-nowrap">' . e(strtoupper($applicant->sale_postcode)) . $copyPostcodeBtn($applicant->sale_postcode) . '</span>';
                })
                ->addColumn('sale_source_name', function ($sale) {
                    if (!$sale->sale_source_name)
                        return '-';
                    return '<span class="badge bg-light text-dark">' . e($sale->sale_source_name) . '</span>';
                })
                ->editColumn('user_name', function ($applicant) {
                    return $applicant->user_name ? $applicant->user_name : '-'; // Using accessor
                })
                ->editColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->editColumn('applicant_experience', function ($applicant) {
                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->editColumn('applicantEmail', function ($applicant) {
                    $email = '';
                    if ($applicant->is_blocked) {
                        $email = "<span class='badge bg-dark'>Blocked</span>";
                    } else {
                        $email = $applicant->applicant_email;

                        if ($applicant->applicant_email_secondary) {
                            $email .= '<br>' . $applicant->applicant_email_secondary;
                        }
                    }

                    return $email; // Using accessor
                })
                // In your DataTable or controller
                ->filterColumn('applicantEmail', function ($query, $keyword) {
                    $keyword = strtolower(trim($keyword));

                    $query->where(function ($q) use ($keyword) {
                        $q->whereRaw('LOWER(applicants.applicant_email) LIKE ?', ["%{$keyword}%"])
                            ->orWhereRaw('LOWER(applicants.applicant_email_secondary) LIKE ?', ["%{$keyword}%"]);
                    });
                })
                ->editColumn('applicant_postcode', function ($applicant) use ($copyPostcodeBtn) {
                    if ($applicant->lat != null && $applicant->lng != null && !$applicant->is_blocked) {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $button = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $applicant->formatted_postcode . '</a>'; // Using accessor
                    } else {
                        $button = $applicant->formatted_postcode;
                    }
                    return '<span class="text-nowrap">' . $button . $copyPostcodeBtn($applicant->applicant_postcode) . '</span>';
                })
                ->addColumn('notes_details', function ($applicant) {
                    $notes_detail = strip_tags((string) ($applicant->notes_detail ?? $applicant->applicant_notes ?? ''));
                    $notes_created_at = $applicant->notes_created_at
                        ? Carbon::parse($applicant->notes_created_at)->format('d M Y, h:i A')
                        : '-';
                    $notes = "<strong>Date: {$notes_created_at}</strong><br>{$notes_detail}";

                    $short = Str::limit($notes, 150);
                    $modalId = 'crm-' . $applicant->id;

                    $name = e($applicant->applicant_name);
                    $postcode = e($applicant->applicant_postcode);
                    $notesEscaped = nl2br(e($notes_detail));
                    $copyId = "copy-notes-" . $applicant->id;

                    return '
                        <div>
                            <a href="javascript:void(0);" class="text-primary" 
                            data-bs-toggle="modal" 
                            data-bs-target="#' . $modalId . '">
                                ' . $short . '
                            </a>

                            <!-- Hidden full notes for copy -->
                            <div id="' . $copyId . '" class="d-none">' . $notesEscaped . '</div><br>

                            <!-- Copy button under short note -->
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2 copy-btn" data-copy-target="#' . $copyId . '">
                                Copy Notes
                            </button>
                        </div>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $modalId . '-label">Applicant\'s CRM Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <p><strong>Name:</strong> ' . $name . '</p>
                                        <p><strong>Postcode:</strong> ' . $postcode . '</p>
                                        <p><strong>Date:</strong> ' . $notes_created_at . '</p>
                                        <p class="notes-content">
                                            <strong>Notes Detail:</strong><br>' . $notesEscaped . '
                                        </p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('applicantPhone', function ($applicant) {

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

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                // In your DataTable or controller
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->editColumn('show_created_at', function ($applicant) {
                    return $applicant->notes_created_at ? Carbon::parse($applicant->notes_created_at)->format('d M Y, g:i A') : '-';
                })
                ->addColumn('applicant_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->applicant_cv, $applicant->is_blocked, 'original', 'success', 'Download CV'))
                ->addColumn('crm_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->updated_cv, $applicant->is_blocked, 'updated', 'primary', 'Download Updated CV'))
                ->addColumn('action', function ($applicant) {
                    $html = '';
                    $html .= '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">';
                    if (Gate::allows('applicant-view-history') || Gate::allows('applicant-view-notes-history')) {
                        $html .= '<li><hr class="dropdown-divider"></li>';
                    }

                    if (Gate::allows('applicant-view-history')) {
                        $html .= '<li><a class="dropdown-item" target="_blank" href="' . route('applicants.history', ['id' => (int)$applicant->id]) . '">View History</a></li>';
                    }
                    if (Gate::allows('applicant-view-notes-history')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . (int)$applicant->id . ')">Notes History</a></li>';
                    }
                    $html .= '</ul>
                        </div>';

                    return $html;
                })
                ->rawColumns(['notes_details', 'sale_source_name', 'sale_postcode', 'user_name', 'job_details', 'show_created_at', 'applicantPhone', 'applicant_postcode', 'job_title', 'applicant_experience', 'applicantEmail', 'applicant_resume', 'crm_resume', 'job_category', 'action'])
                ->make(true);
        }
    }
    private function parseDateRange(string $range, ?string $inputDate = null): array
    {
        $inputDate = trim((string) $inputDate);

        switch ($range) {
            case 'daily':
                if ($inputDate === '') {
                    $inputDate = Carbon::today()->format('Y-m-d');
                }
                $startDate = Carbon::createFromFormat('Y-m-d', $inputDate)->startOfDay();
                $endDate   = Carbon::createFromFormat('Y-m-d', $inputDate)->endOfDay();
                $displayDate = $startDate->format('jS F Y');
                break;

            case 'weekly':
            case 'aggregate':
                [$start, $end] = explode(' to ', $inputDate);

                $startDate = Carbon::createFromFormat('Y-m-d', trim($start))->startOfDay();
                $endDate   = Carbon::createFromFormat('Y-m-d', trim($end))->endOfDay();
                $displayDate = $startDate->format('jS F') . ' - ' . $endDate->format('jS F Y');
                break;

            case 'monthly':
                $startDate = Carbon::createFromFormat('Y-m', $inputDate)->startOfMonth();
                $endDate   = Carbon::createFromFormat('Y-m', $inputDate)->endOfMonth();
                $displayDate = $startDate->format('F Y');
                break;

            case 'yearly':
                $startDate = Carbon::createFromFormat('Y', $inputDate)->startOfYear();
                $endDate   = Carbon::createFromFormat('Y', $inputDate)->endOfYear();
                $displayDate = $startDate->format('Y');
                break;

            default:
                throw new \InvalidArgumentException('Invalid range');
        }

        // ✅ Return as numeric array so destructuring works
        return [$startDate, $endDate, $displayDate];
    }
}
