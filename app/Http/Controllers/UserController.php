<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Audit;
use Horsefly\User;
use Horsefly\Role;
use App\Http\Controllers\Controller;
use Horsefly\LoginDetail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Exports\UsersExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:administrator-user-index')->only(['index', 'getUsers']);
        $this->middleware('permission:administrator-user-create')->only(['create', 'store']);
        $this->middleware('permission:administrator-user-edit')->only(['edit', 'update']);
        $this->middleware('permission:administrator-user-view')->only(['show', 'userDetails']);
        $this->middleware('permission:administrator-user-delete')->only(['destroy']);
        $this->middleware('permission:administrator-user-export')->only(['export']);
        $this->middleware('permission:administrator-user-change-status')->only(['changeUserStatus']);
        $this->middleware('permission:administrator-user-activity-log')->only(['activityLogIndex', 'getUserActivityLogs']);
        $this->middleware('permission:report-user-login')->only(['userLogin', 'getUsersLoginReport', 'userLoginHistoryIndex', 'getUserLoginHistory']);
    }
    /**
     * Display a listing of the applicants.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $roles = Role::orderBy('name', 'asc')->get();

        return view('users.list', compact('roles'));
    }
    public function userLogin()
    {
        $roles = Role::orderBy('name', 'asc')->get();

        return view('reports.users-login-report', compact('roles'));
    }
    public function create()
    {
        return view('users.create');
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Get office data
            $userData = $request->only([
                'name',
                'email'
            ]);

            $password = Hash::make($request->password);
            $userData['password'] = $password;

            $user = User::create($userData);

            if ($request->has('role')) {
                $user->syncRoles([$request->role]);
            }

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'redirect' => route('users.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user. Please try again.'
            ], 500);
        }
    }
    public function getUsers(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $model = User::query()
            ->leftJoin('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select('users.*', 'roles.name as role_name'); // Add alias for sorting

        // Filter by status if it's not empty
        switch ($statusFilter) {
            case 'inactive':
                $model->where('users.is_active', 0);
                break;
            default:
            case 'active':
                $model->where('users.is_active', 1);
                break;
        }

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
                    return $user->formatted_name; // Using accessor
                })
                ->addColumn('role_name', function ($user) {
                    $role = $user->role_name; // returns the first (or only) role name
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
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($user) {
                    $name = $user->formatted_name;
                    $email = $user->email;
                    $roleName = ucwords($user->role_name);
                    $status = '';

                    if ($user->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }
                    $html = '';
                    $html .= '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>';
                    if (Gate::allows('administrator-user-edit')) {
                        $html .= ' <a class="dropdown-item" href="javascript:void(0);" onclick="showEditModal(
                                                \'' . (int) $user->id . '\',
                                                \'' . addslashes(htmlspecialchars($name)) . '\',
                                                \'' . addslashes(htmlspecialchars($email)) . '\',
                                                \'' . addslashes(htmlspecialchars($user->is_active)) . '\',
                                                \'' . addslashes(htmlspecialchars($roleName)) . '\'
                                            )">Edit</a>
                                        </li>';
                    }
                    if (Gate::allows('administrator-user-view')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                            \'' . (int) $user->id . '\',
                                            \'' . $user->formatted_created_at . '\',
                                            \'' . addslashes(htmlspecialchars($name)) . '\',
                                            \'' . addslashes(htmlspecialchars($email)) . '\',
                                            \'' . addslashes(htmlspecialchars($roleName)) . '\',
                                            \'' . addslashes(htmlspecialchars($status)) . '\'
                                        )">View</a></li>';
                    }
                    if (Gate::allows('administrator-user-change-status')) {
                        if ($user->is_active == true) {
                            $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeStatusModal(
                                                                \'' . (int) $user->id . '\', \'0\'
                                                            )">Mark as Inctive</a></li>';
                        } else {
                            $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="changeStatusModal(
                                                                \'' . (int) $user->id . '\', \'1\'
                                                            )">Mark as Active</a></li>';
                        }
                    }

                    if (Gate::allows('administrator-user-activity-log')) {
                        $url = route('users.activity_log', ['id' => $user->id]);
                        $html .= '<li><a target="_blank" class="dropdown-item" href="' . e($url) . '">Activity Log</a></li>';
                    }
                    '</ul>
                            </div>';

                    return $html;
                })

                ->rawColumns(['name', 'is_active', 'action', 'role_name'])
                ->make(true);
        }
    }
    public function activityLogIndex($id)
    {
        $user_id = $id;
        return view('users.activity-logs', compact('user_id'));
    }
    public function getUserActivityLogs(Request $request)
    {
        if (!$request->ajax()) {
            abort(400);
        }

        // 🔥 cache status maps per request (NOT per row)
        $statusCache = [];

        $model = Audit::query()
            ->where('user_id', $request->id)
            ->latest('created_at');

        return DataTables::eloquent($model)
            ->addIndexColumn()

            ->addColumn('details', function ($audit) use (&$statusCache) {

                // ---------- SAFE JSON DECODE ----------
                $data = $this->decodeAuditData($audit->data);

                $old = is_array($data['old_values'] ?? null) ? $data['old_values'] : [];
                $new = is_array($data['new_values'] ?? null) ? $data['new_values'] : [];
                $changes = is_array($data['changes_made'] ?? null) ? $data['changes_made'] : [];

                // ---------- STATUS MAP CACHE ----------
                $modelClass = $audit->auditable_type;

                if (!isset($statusCache[$modelClass])) {
                    $statusCache[$modelClass] = $this->getStatusMapFromComment($modelClass);
                }

                $statusMap = $statusCache[$modelClass];

                $allowedTags = '<b><strong><i><em><br><ul><ol><li>';

                // ---------- FORMATTER ----------
                $format = function ($key, $value) use ($allowedTags, $statusMap) {

                    // ARRAY
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }

                    // BOOLEAN
                    if (is_bool($value)) {
                        return $value
                            ? '<span class="badge bg-success">True</span>'
                            : '<span class="badge bg-danger">False</span>';
                    }

                    // NULL / EMPTY
                    if ($value === null || $value === '') {
                        return '<span class="text-muted">-</span>';
                    }

                    // STATUS
                    if ($key === 'status' && !empty($statusMap)) {
                        return '<span class="badge bg-primary">'
                            . ($statusMap[$value] ?? $value)
                            . '</span>';
                    }

                    // URL
                    if (filter_var($value, FILTER_VALIDATE_URL)) {
                        return "<a href='{$value}' target='_blank' class='btn btn-sm btn-primary'>Open</a>";
                    }

                    // DATE (strict pattern check first)
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                        try {
                            return \Carbon\Carbon::parse($value)->format('d M Y, h:i A');
                        } catch (\Exception $e) {
                        }
                    }

                    return strip_tags((string) $value, $allowedTags);
                };

                $rows = '';

                // ---------- OLD vs NEW ----------
                if (!empty($old) && !empty($new)) {

                    $rows .= '<h5><strong>Changes</strong></h5>';

                    foreach ($new as $key => $newVal) {

                        $oldVal = $old[$key] ?? null;

                        if ($oldVal == $newVal)
                            continue;

                        $label = ucwords(str_replace('_', ' ', $key));

                        $rows .= "
                        <div class='mb-3 text-start'>
                            <strong>{$label}</strong><br>
                            <span class='text-danger'>Old: {$format($key,$oldVal)}</span><br>
                            <span class='text-success'>New: {$format($key,$newVal)}</span>
                        </div>
                    ";
                    }
                } elseif (!empty($changes) && is_array($changes)) {

                    $rows .= '<h5><strong>Changes</strong></h5>';

                    foreach ($changes as $key => $val) {

                        $label = ucwords(str_replace('_', ' ', $key));

                        $rows .= "
                        <div class='mb-2 text-start'>
                            <strong>{$label}</strong><br>
                            {$format($key,$val)}
                        </div>
                    ";
                    }
                } else {

                    $rows .= '<h5><strong>Details</strong></h5>';

                    foreach ($data as $key => $val) {

                        $label = ucwords(str_replace('_', ' ', $key));

                        $rows .= "
                        <div class='mb-2 text-start'>
                            <strong>{$label}</strong><br>
                            {$format($key,$val)}
                        </div>
                    ";
                    }
                }

                return "
                <a href='#' data-bs-toggle='modal' data-bs-target='#modal_{$audit->id}'>
                    <iconify-icon icon='solar:square-arrow-right-up-bold' class='text-info fs-24'></iconify-icon>
                </a>

                <div id='modal_{$audit->id}' class='modal fade'>
                    <div class='modal-dialog modal-dialog-scrollable modal-dialog-top modal-lg'>
                        <div class='modal-content'>

                            <div class='modal-header'>
                                <h5 class='modal-title'>Audit Details</h5>
                                <button type='button' class='btn-close' data-bs-dismiss='modal'></button>
                            </div>

                            <div class='modal-body text-start'>
                                {$rows}
                            </div>

                            <div class='modal-footer'>
                                <button class='btn btn-dark' data-bs-dismiss='modal'>Close</button>
                            </div>

                        </div>
                    </div>
                </div>
            ";
            })

            ->addColumn(
                'created_at',
                fn($audit) =>
                $audit->created_at->format('d M Y, h:i A')
            )

            ->addColumn(
                'auditable_type',
                fn($audit) =>
                class_basename($audit->auditable_type)
            )

            ->rawColumns(['details'])
            ->make(true);
    }
    private function decodeAuditData($data): array
    {
        if (is_array($data))
            return $data;

        if (!is_string($data))
            return [];

        $decoded = json_decode($data, true);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }
    private function getStatusMapFromComment($modelClass)
    {
        if (!class_exists($modelClass))
            return [];

        $table = (new $modelClass)->getTable();

        return cache()->remember("status_map_{$table}", 3600, function () use ($table) {

            $result = DB::select("
            SELECT COLUMN_COMMENT 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'status'
        ", [$table]);

            if (empty($result))
                return [];

            $comment = $result[0]->COLUMN_COMMENT ?? '';

            $map = [];

            foreach (explode(',', $comment) as $pair) {
                if (str_contains($pair, '=')) {
                    [$key, $value] = explode('=', $pair);
                    $map[trim($key)] = trim($value);
                }
            }

            return $map;
        });
    }
    public function changeUserStatus(Request $request)
    {
        $user_id = $request->input('user_id');

        $user = User::findOrFail($user_id);
        $user->update(['is_active' => $request->status]);

        return response()->json(['success' => true, 'message' => 'User status updated successfully.']);
    }
    public function getUsersLoginReport(Request $request)
    {
        $dateFilter = $request->input('date_filter', '');
        $filterDate = $dateFilter ? Carbon::parse($dateFilter)->toDateString() : Carbon::now()->toDateString();

        // Subquery for latest login per user (FAST)
        $latestLogin = LoginDetail::selectRaw('MAX(id) as latest_id')
            ->whereDate('created_at', $filterDate)
            ->groupBy('user_id');

        // Join it efficiently
        $model = LoginDetail::query()
            ->joinSub($latestLogin, 'latest', function ($join) {
                $join->on('login_details.id', '=', 'latest.latest_id');
            })
            ->join('users', 'users.id', '=', 'login_details.user_id')
            ->select([
                'login_details.*',
                'users.name as user_name',
            ]);

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            $validColumn = in_array($orderColumn, ['user_name', 'login_details.created_at', 'login_details.login_at', 'login_details.logout_at']);

            $model->orderBy($validColumn ? $orderColumn : 'login_details.created_at', $orderDirection);
        } else {
            $model->orderBy('login_details.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->editColumn('user_name', fn($row) => e($row->user_name))
                ->editColumn('created_at', fn($row) => Carbon::parse($row->created_at)->format('d M Y'))
                ->addColumn('credit_hours', function ($row) {
                    if ($row->login_at && $row->logout_at) {
                        try {
                            // Parse times as Carbon instances, assuming the same day
                            $login = Carbon::parse($row->login_at);
                            $logout = Carbon::parse($row->logout_at);

                            // If logout < login, assume logout is on the next day
                            if ($logout->lessThan($login)) {
                                $logout->addDay();
                            }

                            // Difference in total minutes (login -> logout; Carbon 3 returns a signed value)
                            $diffInMinutes = (int) $login->diffInMinutes($logout);
                            $hours = intdiv($diffInMinutes, 60);
                            $minutes = $diffInMinutes % 60;

                            return "{$hours}h {$minutes}m";
                        } catch (\Exception $e) {
                            return '0h 0m';
                        }
                    }
                    return '0h 0m';
                })

                ->addColumn('action', function ($row) {
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="' . route('reports.userLoginHistory', ['id' => $row->user_id]) . '">View History</a></li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'credit_hours', 'created_at', 'user_name'])
                ->make(true);
        }
    }
    public function getUserLoginHistory(Request $request)
    {
        $id = $request->input('id');

        // Base query
        $model = LoginDetail::query()
            ->join('users', 'users.id', '=', 'login_details.user_id')
            ->select('login_details.*', 'users.name as user_name')
            ->where('login_details.user_id', $id);

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            $validColumns = ['user_name', 'login_details.created_at', 'login_details.login_at', 'login_details.logout_at'];
            if (in_array($orderColumn, $validColumns)) {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('login_details.created_at', 'desc');
            }
        } else {
            $model->orderBy('login_details.created_at', 'desc');
        }

        // Search Filter
        if ($request->has('search.value') && $search = trim($request->input('search.value'))) {
            $model->where(function ($query) use ($search) {
                $formattedDate = null;

                try {
                    $parsed = Carbon::createFromFormat('d M Y', $search);
                    $formattedDate = $parsed->format('Y-m-d');
                } catch (\Exception $e) {
                    try {
                        $parsed = Carbon::parse($search);
                        $formattedDate = $parsed->format('Y-m-d');
                    } catch (\Exception $ex) {
                        $formattedDate = null;
                    }
                }

                $query->where(function ($sub) use ($search, $formattedDate) {
                    // text search
                    $sub->orWhere('users.name', 'LIKE', "%{$search}%")
                        ->orWhere('login_details.created_at', 'LIKE', "%{$search}%")
                        ->orWhere('login_details.login_at', 'LIKE', "%{$search}%")
                        ->orWhere('login_details.logout_at', 'LIKE', "%{$search}%");

                    // date search (prefer this)
                    if ($formattedDate) {
                        $sub->orWhereDate('login_details.created_at', $formattedDate)
                            ->orWhereDate('login_details.login_at', $formattedDate)
                            ->orWhereDate('login_details.logout_at', $formattedDate);
                    }
                });
            });
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->editColumn('user_name', fn($row) => e($row->user_name))
                ->editColumn('created_at', fn($row) => Carbon::parse($row->created_at)->format('d M Y'))
                ->addColumn('credit_hours', function ($row) {
                    if ($row->login_at && $row->logout_at) {
                        try {
                            // Parse times as Carbon instances, assuming the same day
                            $login = Carbon::parse($row->login_at);
                            $logout = Carbon::parse($row->logout_at);

                            // If logout < login, assume logout is on the next day
                            if ($logout->lessThan($login)) {
                                $logout->addDay();
                            }

                            // Difference in total minutes (login -> logout; Carbon 3 returns a signed value)
                            $diffInMinutes = (int) $login->diffInMinutes($logout);
                            $hours = intdiv($diffInMinutes, 60);
                            $minutes = $diffInMinutes % 60;

                            return "{$hours}h {$minutes}m";
                        } catch (\Exception $e) {
                            return '0h 0m';
                        }
                    }
                    return '0h 0m';
                })


                ->rawColumns(['created_at', 'user_name', 'credit_hours'])
                ->make(true);
        }
    }

    public function userLoginHistoryIndex($id)
    {
        $user = User::findOrFail($id);
        $history = LoginDetail::where('user_id', $id)->get();

        return view('reports.login-history', compact('history', 'user'));
    }
    public function userDetails($id)
    {
        $user = User::findOrFail($id);
        return view('users.details', compact('user'));
    }
    public function edit($id)
    {
        return view('users.edit');
    }
    public function update(Request $request)
    {
        // Get user ID from request
        $id = $request->input('id');

        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($id),
            ],
            'role' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form',
            ], 422);
        }

        try {
            // Find the user
            $user = User::findOrFail($id);

            // Prepare data
            $userData = $request->only(['name', 'email']);

            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }

            $userData['is_active'] = $request->status;

            // Update user
            $user->update($userData);

            // Update role
            if ($request->has('role')) {
                $user->syncRoles([$request->role]);
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'redirect' => route('users.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return redirect()->route('users.list')->with('success', 'User deleted successfully');
    }
    public function show($id)
    {
        $user = User::findOrFail($id);
        return view('users.show', compact('user'));
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided

        return Excel::download(new UsersExport($type), "users_{$type}.csv");
    }
}
