<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Support\DocumentStorage;

use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\Sale;
use Horsefly\Contact;
use Horsefly\Setting;
use Horsefly\JobSource;
use Horsefly\ModuleNote;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Exports\UnitsExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Traits\Geocode;
use App\Traits\HidesPrivateContacts;
use App\Traits\RecordsBlacklistNotes;
use App\Support\StatusBadge;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use App\Observers\ActionObserver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class UnitController extends Controller
{
    use Geocode, HidesPrivateContacts, RecordsBlacklistNotes;

    public function __construct()
    {
        $this->middleware('permission:unit-index')->only(['index', 'getUnits']);
        $this->middleware('permission:unit-create')->only(['create', 'store']);
        $this->middleware('permission:unit-edit')->only(['edit', 'update', 'destroyDocument', 'restoreDocument']);
        $this->middleware('permission:unit-blacklist')->only(['toggleBlacklist']);
        $this->middleware('permission:unit-view')->only(['show', 'unitDetails']);
        $this->middleware('permission:unit-delete')->only(['destroy']);
        $this->middleware('permission:unit-export')->only(['export']);
        $this->middleware('permission:unit-add-note')->only(['storeUnitShortNotes']);
        $this->middleware('permission:unit-change-status')->only(['changeUnitStatus']);
    }

    /**
     * Display a listing of the applicants.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $offices = Office::where('status', 1)->orderBy('office_name', 'asc')->get();

        $sourceIds = $this->hiddenPrivateSourceIds();

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();
        return view('units.list', compact('offices', 'jobSources'));
    }
    public function create()
    {
        $offices = Office::where('status', 1)
            ->where('is_blacklist', false)
            ->select('id', 'office_name')
            ->get();

        $sourceIds = $this->hiddenPrivateSourceIds();

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('units.create', compact('jobSources', 'offices'));
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => ['required', Rule::exists('offices', 'id')->where('is_blacklist', false)],
            'unit_name' => 'required|string|max:255',
            'unit_postcode' => ['required', 'string', 'min:3', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            'unit_notes' => 'required|string|max:255',

            // Contact person's details (Array validation)
            'contact_name' => 'required|array',
            'contact_name.*' => 'required|string|max:255',

            'contact_email' => 'required|array',
            'contact_email.*' => 'required|email|max:255',

            'contact_phone' => 'nullable|array',
            'contact_phone.*' => 'nullable|string|max:20',

            'contact_landline' => 'nullable|array',
            'contact_landline.*' => 'nullable|string|max:20',

            'contact_note' => 'nullable|array',
            'contact_note.*' => 'nullable|string',

            'contact_job_source_id' => 'required|array',
            'contact_job_source_id.*' => 'required|exists:job_sources,id',
            'attachments' => 'nullable|array',
            'attachments.*' => DocumentStorage::rule(),   // documents and images only (N-10)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $storedDocumentPaths = [];
        DB::beginTransaction();

        try {
            // Get office data
            $unitData = $request->only([
                'office_id',
                'job_source_id',
                'unit_name',
                'unit_postcode',
                'unit_website',
                'unit_notes',
            ]);

            // Format data for office
            $unitData['user_id'] = Auth::id();

            $postcode = preg_replace('/\s+/', '', $request->unit_postcode);
            // 1. Try to find a match in the full postcodes table first
            $postcode_query = DB::table('postcodes')
                ->whereRaw("LOWER(REPLACE(postcode, ' ', '')) = ?", [strtolower($postcode)])
                ->first();

            // 2. Fallback: If not found in full postcodes, check outcodes
            if (!$postcode_query) {
                $postcode_query = DB::table('outcodepostcodes')
                    ->whereRaw("LOWER(REPLACE(outcode, ' ', '')) = ?", [strtolower($postcode)])
                    ->first();
            }

            if (!$postcode_query) {
                try {
                    $result = $this->geocode($postcode);

                    // If geocode fails, throw
                    if (!isset($result['lat']) || !isset($result['lng'])) {
                        throw new \Exception('Geolocation failed. Latitude and longitude not found.');
                    }

                    $unitData['lat'] = $result['lat'];
                    $unitData['lng'] = $result['lng'];
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }
            } else {
                $unitData['lat'] = $postcode_query->lat;
                $unitData['lng'] = $postcode_query->lng;
            }

            $unitData['unit_notes'] = $unit_notes = $request->unit_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            $unit = Unit::create($unitData);

            // Iterate through each contact provided in the request
            foreach ($request->input('contact_name') as $index => $contactName) {
                // Create contact data for each contact in the array
                $contactJobSourceId = $request->input("contact_job_source_id.{$index}");
                $contactData = [
                    'contact_name' => $contactName,
                    'contact_email' => $request->input('contact_email')[$index],
                    'contact_phone' => preg_replace('/[^0-9]/', '', $request->input('contact_phone')[$index]),
                    'contact_landline' => $request->input('contact_landline')[$index]
                        ? preg_replace('/[^0-9]/', '', $request->input('contact_landline')[$index])
                        : null,
                    'contact_note' => $request->input('contact_note')[$index] ?? null,
                    'job_source_id' => $contactJobSourceId !== null && $contactJobSourceId !== ''
                        ? (int) $contactJobSourceId
                        : null,
                ];

                // Create each contact and associate it with the office
                $unit->contacts()->create($contactData);
            }

            // Generate UID
            $unit->update(['unit_uid' => md5($unit->id)]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $unit_notes,
                'module_noteable_id' => $unit->id,
                'module_noteable_type' => 'Horsefly\Unit',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            $this->storeUnitDocuments($request, $unit, $storedDocumentPaths);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit created successfully',
                'redirect' => route('units.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating unit: ' . $e->getMessage());

            DB::rollBack();
            array_map([DocumentStorage::class, 'delete'], $storedDocumentPaths);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function getUnits(Request $request)
    {
        $statusFilter = $request->input('status_filter', '');
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)

        $query = Unit::query()
            ->select('units.*', 'offices.office_name as office_name')
            ->leftJoin('offices', 'units.office_id', '=', 'offices.id')
            ->whereNull('units.deleted_at')
            ->with('office')
            ->whereNotIn('units.status', [4, 5]);

        $this->excludePrivateNotes($query, 'units.unit_notes');

        $query->with(['contacts' => function ($q) {
            $this->excludePrivateContacts($q);
        }]);

        if ($statusFilter === 'active') {
            $query->where('units.status', 1)
                ->where('units.is_blacklist', 0);
        } elseif ($statusFilter === 'inactive') {
            $query->where('units.status', 0)
                ->where('units.is_blacklist', 0);
        } elseif ($statusFilter === 'blacklisted') {
            $query->where('units.is_blacklist', 1);
        }

        // Office filter
        if ($officeFilter !== '') {
            $query->whereIn('units.office_id', $officeFilter);
        }

        // ─── Turbo Search Optimization (Units + Contacts) ───────────────────
        if ($request->filled('search.value')) {
            $search = trim($request->input('search.value'));

            if (strlen($search) >= 2) {
                // 1. Scout search matches unit_name, etc.
                $unitIdsFromElastic = Unit::search($search)->keys()->toArray();

                // 2. Search for Units via Office (Scout search on Office model)
                $officeIds = Office::search($search)->keys()->toArray();
                $unitIdsByOffice = Unit::whereIn('office_id', $officeIds)->pluck('id')->toArray();

                // 3. Still do the Contact SQL check (visible contacts only)
                $contactSearch = Contact::where('contactable_id', '>', 0)
                    ->where('contactable_type', 'Horsefly\\Unit')
                    ->where(function ($q) use ($search) {
                        $q->where('contact_email', 'LIKE', "%{$search}%")
                            ->orWhere('contact_phone', 'LIKE', "%{$search}%");
                    });
                $this->excludePrivateContacts($contactSearch);
                $contactIds = $contactSearch->pluck('contactable_id')->toArray();

                $allIds = array_unique(array_merge($unitIdsFromElastic, $unitIdsByOffice, $contactIds));

                $query->whereIn('units.id', $allIds);
            }
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumnIndex = $request->input('order.0.column');
            $orderColumn = $request->input("columns.$orderColumnIndex.data");
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                // Handle contact.* columns differently if needed
                // $column = str_starts_with($orderColumn, 'contact.') ? 'contacts.' . str_replace('contact.', '', $orderColumn) : 'units.' . $orderColumn;
                $query->orderBy($orderColumn, $orderDirection);
            } else {
                $query->orderBy('units.created_at', 'desc');
            }
        } else {
            $query->orderBy('units.created_at', 'desc');
        }

        /* -------------------------------------------------
        | DataTables Response
        -------------------------------------------------*/
        return DataTables::eloquent($query)
            ->addIndexColumn()
            ->setRowClass(fn ($u) => $u->is_blacklist ? 'table-danger blacklisted-row' : '')

            ->addColumn('office_name', fn($u) => $u->office?->office_name ?? '-')
            ->filterColumn('office_name', function ($query, $keyword) {
                $words = preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($words as $word) {
                    $query->where('offices.office_name', 'LIKE', "%{$word}%");
                }
            })
            ->addColumn('unit_name', fn($u) => $u->formatted_unit_name)
            ->addColumn('unit_postcode', fn($u) => $u->formatted_postcode)
            ->editColumn('unit_postcode', function ($u) {
                $rawPostcode = trim($u->formatted_postcode);
                if (empty($rawPostcode)) return '<div class="text-center w-100">-</div>';

                $postcode = $u->formatted_postcode;
                $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                data-postcode="' . e($u->formatted_postcode) . '" title="Copy Postcode">
                                <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                            </button>';

                return '<div class="d-flex align-items-center justify-content-between">' . $postcode . $copyBtn . '</div>';
            })
            ->addColumn(
                'contact_email',
                fn($u) =>
                $u->contacts->pluck('contact_email')->filter()->implode('<br>') ?: '-'
            )
            ->addColumn(
                'contact_phone',
                fn($u) =>
                $u->contacts->pluck('contact_phone')->filter()->implode('<br>') ?: '-'
            )
            ->addColumn(
                'contact_landline',
                fn($u) =>
                $u->contacts->pluck('contact_landline')->filter()->implode('<br>') ?: '-'
            )
            ->filterColumn('contact_email', function ($query, $keyword) {
                $keyword = trim($keyword);
                $query->whereExists(function ($q) use ($keyword) {
                    $q->select(DB::raw(1))
                        ->from('contacts')
                        ->whereColumn('contacts.contactable_id', 'units.id')
                        ->where('contacts.contactable_type', \Horsefly\Unit::class)
                        ->where('contact_email', 'LIKE', "{$keyword}%");
                    $this->excludePrivateContacts($q);
                });
            })
            ->filterColumn('contact_phone', function ($query, $keyword) {
                $clean = preg_replace('/[^0-9]/', '', $keyword);
                $query->whereExists(function ($q) use ($clean) {
                    $q->select(DB::raw(1))
                        ->from('contacts')
                        ->whereColumn('contacts.contactable_id', 'units.id')
                        ->where('contacts.contactable_type', \Horsefly\Unit::class)
                        ->where('contact_phone', 'LIKE', "{$clean}%");
                    $this->excludePrivateContacts($q);
                });
            })
            ->filterColumn('contact_landline', function ($query, $keyword) {
                $clean = preg_replace('/[^0-9]/', '', $keyword);
                $query->whereExists(function ($q) use ($clean) {
                    $q->select(DB::raw(1))
                        ->from('contacts')
                        ->whereColumn('contacts.contactable_id', 'units.id')
                        ->where('contacts.contactable_type', \Horsefly\Unit::class)
                        ->where('contact_landline', 'LIKE', "{$clean}%");
                    $this->excludePrivateContacts($q);
                });
            })

            ->addColumn('created_at', fn($u) => $u->formatted_created_at)
            ->addColumn('updated_at', fn($u) => $u->formatted_updated_at)
            ->addColumn(
                'unit_notes',
                fn($u) =>
                '<a href="javascript:void(0);" onclick="addShortNotesModal(' . (int)$u->id . ')">'
                    . nl2br(e($u->unit_notes)) . '</a>'
            )
            ->addColumn('status', function ($u) {
                return $this->unitStatusBadges($u);
            })
            ->addColumn('action', function ($u) {
                $postcode    = $u->formatted_postcode;
                $office_name = $u->office?->office_name ?? '-';
                $status      = $this->unitStatusBadges($u);

                $html = '<div class="btn-group dropstart">
                        <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                        </button>
                        <ul class="dropdown-menu">';

                if (Gate::allows('unit-view')) {
                    $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal('
                        . (int)$u->id . ', '
                        . '\'' . e($office_name) . '\', '
                        . '\'' . e($u->unit_name) . '\', '
                        . '\'' . e($postcode) . '\', '
                        . '\'' . e($status) . '\')">View</a></li>';
                }
                if (Gate::allows('unit-edit')) {
                    $html .= '<li><a class="dropdown-item" href="' . route('units.edit', ['id' => $u->id]) . '">Edit</a></li>';
                }
                if (Gate::allows('unit-blacklist')) {
                    $blockLabel = $u->is_blacklist ? 'Remove from Blacklist' : 'Add to Blacklist';
                    $blockStatus = $u->is_blacklist ? 0 : 1;
                    $html .= '<li><a class="dropdown-item ' . ($u->is_blacklist ? 'text-success' : 'text-danger') . '" href="javascript:void(0);" onclick="toggleUnitBlacklist(' . (int)$u->id . ', ' . $blockStatus . ')">' . $blockLabel . '</a></li>';
                }



                if (Gate::allows('unit-change-status')) {
                    $html .= $u->status == 0
                        ? '<li><a class="dropdown-item text-secondary" href="javascript:void(0);" onclick="markUnitDisabledModal(' . (int)$u->id . ', 1)">Mark Enabled</a></li>'
                        : '<li><a class="dropdown-item text-warning" href="javascript:void(0);" onclick="markUnitDisabledModal(' . (int)$u->id . ', 0)">Mark Disabled</a></li>';
                }

                if (
                    Gate::allows('unit-view')
                    || Gate::allows('unit-edit')
                    || Gate::allows('unit-view-notes-history')
                    || Gate::allows('unit-view-manager-details')
                ) {
                    $html .= '<li><hr class="dropdown-divider"></li>';
                }

                if (Gate::allows('unit-view') || Gate::allows('unit-edit')) {
                    $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showUnitDocuments(' . (int) $u->id . ')">Attachments</a></li>';
                }

                if (Gate::allows('unit-view-notes-history')) {
                    $html .= '<li><a class="dropdown-item"href="javascript:void(0);" onclick="viewNotesHistory(' . $u->id . ')">Notes History</a></li>';
                }

                if (Gate::allows('unit-view-manager-details')) {
                    $html .= '<li><a class="dropdown-item"href="javascript:void(0);" onclick="viewManagerDetails(' . $u->id . ')">Manager Details</a></li>';
                }

                $html .= '</ul></div>';

                return $html;
            })

            ->rawColumns([
                'unit_notes',
                'contact_email',
                'contact_phone',
                'contact_landline',
                'status',
                'office_name',
                'unit_name',
                'action',
                'unit_postcode'
            ])
            ->make(true);
    }
    public function storeUnitShortNotes(Request $request)
    {
        $user = Auth::user();

        $unit_id = $request->input('unit_id');
        $details = $request->input('details');
        $unit_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        $updateData = ['unit_notes' => $unit_notes];

        Unit::where('id', $unit_id)->update($updateData);

        // Disable previous module note
        ModuleNote::where([
            'module_noteable_id' => $unit_id,
            'module_noteable_type' => 'Horsefly\Unit'
        ])
            ->orderBy('id', 'desc')
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $unit_notes,
            'module_noteable_id' => $unit_id,
            'module_noteable_type' => 'Horsefly\Unit',
            'user_id' => $user->id,
            'status' => 1,
        ]);

        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        // Log audit
        $unit = Unit::where('id', $unit_id)->select('unit_name', 'unit_notes', 'id')->first();
        $observer = new ActionObserver();
        $observer->customUnitAudit($unit, 'unit_notes');

        return redirect()->to(url()->previous());
    }

    private function unitStatusBadges($unit): string
    {
        $status = $unit->status
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-secondary">Inactive</span>';

        return StatusBadge::withBlacklist($status, (bool) $unit->is_blacklist);
    }

    public function toggleBlacklist(Request $request, $id)
    {
        $noteInput = $request->input('blacklist_note');
        if (is_string($noteInput)) {
            $request->merge(['blacklist_note' => trim($noteInput)]);
        }

        $request->validate([
            'is_blacklist' => ['required', 'boolean'],
            'blacklist_note' => ['required', 'string', 'max:2000'],
        ]);
        $targetBlacklistState = $request->boolean('is_blacklist');
        $blacklistNote = $request->input('blacklist_note');
        $unit = Unit::findOrFail($id);

        if (!$targetBlacklistState && Office::whereKey($unit->office_id)->where('is_blacklist', true)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Remove the office from the blacklist before removing this unit.',
            ], 422);
        }

        DB::transaction(function () use ($unit, $targetBlacklistState, $blacklistNote) {
            $user = Auth::user();
            $note = $blacklistNote . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

            $unit = Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $unit->update(['is_blacklist' => $targetBlacklistState]);
            $this->recordUnitNote($unit, $note, $user->id);

            // Blacklisting cascades to sales. Removing the unit from the blacklist
            // does not: sales are removed manually (the sale toggle refuses while the
            // unit is still blacklisted).
            if (!$targetBlacklistState) {
                return;
            }

            // Skip sales that are already blacklisted so their own notes are kept.
            $sales = Sale::where('unit_id', $unit->id)
                ->where('is_blacklist', false)
                ->lockForUpdate()
                ->get();

            $observer = new ActionObserver();

            foreach ($sales as $sale) {
                $sale->update(['is_blacklist' => true]);
                $this->recordSaleNote($sale, $note, $user->id);
                $observer->saleBlacklistAudit($sale, true);
            }
        });

        return response()->json([
            'success' => true,
            'is_blacklist' => $targetBlacklistState,
            'message' => $targetBlacklistState
                ? 'Unit and its related sales have been added to the blacklist.'
                : 'Unit has been removed from the blacklist. Its sales must be removed manually.',
        ]);
    }

    public function changeUnitStatus(Request $request, $id)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'status' => 'required', // 0 for disabled, 1 for enabled
        ]);

        $user = Auth::user();
        $unit = Unit::findOrFail($id);

        $status = trim($request->input('status'));
        $reason = trim($request->input('reason'));
        $noteText = $reason . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        DB::beginTransaction();

        try {
            $unit->update([
                'status' => $status,
                'unit_notes' => $noteText,
            ]);

            ModuleNote::where([
                'module_noteable_id' => $unit->id,
                'module_noteable_type' => 'Horsefly\Unit'
            ])->where('status', 1)->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $noteText,
                'module_noteable_id' => $unit->id,
                'module_noteable_type' => 'Horsefly\Unit',
                'user_id' => $user->id,
                'status' => 1,
            ]);

            $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit status updated successfully.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating unit status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Unable to update the unit status.'
            ], 500);
        }
    }
    public function unitDetails($id)
    {
        $unit = Unit::findOrFail($id);
        return view('units.details', compact('unit'));
    }
    public function edit($id, Request $request)
    {
        $unit = Unit::find($id);
        if ($unit->status == 4) {
            $offices = Office::where('status', 4)->whereNull('deleted_at')->select('id', 'office_name')->get();
        } else {
            $offices = Office::where('status', 1)->whereNull('deleted_at')->select('id', 'office_name')->get();
        }

        $contactsQuery = Contact::where('contactable_id', $unit->id)
            ->where('contactable_type', 'Horsefly\Unit');

        $this->excludePrivateContacts($contactsQuery);

        $sourceIds = $this->hiddenPrivateSourceIds();

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        $contacts = $contactsQuery->get();

        $redirect_url = $request->input('redirect_url', 'units.list');

        $unit->load('documents');
        $deletedDocuments = $unit->documents()->onlyTrashed()->orderByDesc('deleted_at')->get();

        return view('units.edit', compact('offices', 'unit', 'contacts', 'redirect_url', 'jobSources', 'deletedDocuments'));
    }
    public function update(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => 'required|exists:offices,id',
            'unit_name' => 'required|string|max:255',
            'unit_postcode' => ['required', 'string', 'min:3', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            'unit_notes' => 'required|string|max:255',

            // Contact person's details (Array validation)
            'contact_name' => 'required|array',
            'contact_name.*' => 'required|string|max:255',

            'contact_email' => 'required|array',
            'contact_email.*' => 'required|email|max:255',

            'contact_phone' => 'nullable|array',
            'contact_phone.*' => 'nullable|string|max:20',

            'contact_landline' => 'nullable|array',
            'contact_landline.*' => 'nullable|string|max:20',

            'contact_note' => 'nullable|array',
            'contact_note.*' => 'nullable|string',

            'contact_job_source_id' => 'required|array',
            'contact_job_source_id.*' => 'required|exists:job_sources,id',
            'attachments' => 'nullable|array',
            'attachments.*' => DocumentStorage::rule(),   // documents and images only (N-10)
        ]);

        // A unit may keep its current (blacklisted) office, but cannot be moved under a blacklisted one.
        $validator->after(function ($validator) use ($request) {
            $currentOfficeId = Unit::whereKey($request->input('unit_id'))->value('office_id');
            if (
                (string) $currentOfficeId !== (string) $request->input('office_id') &&
                Office::whereKey($request->input('office_id'))->where('is_blacklist', true)->exists()
            ) {
                $validator->errors()->add('office_id', 'The selected office is blacklisted.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $storedDocumentPaths = [];
        DB::beginTransaction();
        try {
            // Get office data
            $unitData = $request->only([
                'office_id',
                'job_source_id',
                'unit_name',
                'unit_postcode',
                'unit_website',
                'unit_notes',
            ]);

            // Get the office ID from the request
            $id = $request->input('unit_id');

            // Retrieve the office record
            $unit = Unit::find($id);

            // If the applicant doesn't exist, throw an exception
            if (!$unit) {
                throw new \Exception("Unit not found with ID: " . $id);
            }

            $postcode = preg_replace('/\s+/', '', $request->unit_postcode);

            if ($postcode != preg_replace('/\s+/', '', $unit->unit_postcode)) {
                // 1. Try to find a match in the full postcodes table first
                $postcode_query = DB::table('postcodes')
                    ->whereRaw("LOWER(REPLACE(postcode, ' ', '')) = ?", [strtolower($postcode)])
                    ->first();

                // 2. Fallback: If not found in full postcodes, check outcodes
                if (!$postcode_query) {
                    $postcode_query = DB::table('outcodepostcodes')
                        ->whereRaw("LOWER(REPLACE(outcode, ' ', '')) = ?", [strtolower($postcode)])
                        ->first();
                }

                if (!$postcode_query) {
                    try {
                        $result = $this->geocode($postcode);

                        // If geocode fails, throw
                        if (!isset($result['lat']) || !isset($result['lng'])) {
                            throw new \Exception('Geolocation failed. Latitude and longitude not found.');
                        }

                        $unitData['lat'] = $result['lat'];
                        $unitData['lng'] = $result['lng'];
                    } catch (\Exception $e) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to locate address: ' . $e->getMessage()
                        ], 400);
                    }
                } else {
                    $unitData['lat'] = $postcode_query->lat;
                    $unitData['lng'] = $postcode_query->lng;
                }
            }

            $unitData['unit_notes'] = $unit_notes = $request->unit_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            // Update the applicant with the validated and formatted data
            $unit->update($unitData);

            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Unit'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $unit_notes,
                'module_noteable_id' => $unit->id,
                'module_noteable_type' => 'Horsefly\Unit',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            Contact::where('contactable_id', $unit->id)
                ->where('contactable_type', 'Horsefly\Unit')->delete();

            // Iterate through each contact provided in the request
            foreach ($request->input('contact_name') as $index => $contactName) {
                // Create contact data for each contact in the array
                $contactJobSourceId = $request->input("contact_job_source_id.{$index}");
                $contactData = [
                    'contact_name' => $contactName,
                    'contact_email' => $request->input('contact_email')[$index],
                    'contact_phone' => preg_replace('/[^0-9]/', '', $request->input('contact_phone')[$index]),
                    'contact_landline' => $request->input('contact_landline')[$index]
                        ? preg_replace('/[^0-9]/', '', $request->input('contact_landline')[$index])
                        : null,
                    'contact_note' => $request->input('contact_note')[$index] ?? null,
                    'job_source_id' => $contactJobSourceId !== null && $contactJobSourceId !== ''
                        ? (int) $contactJobSourceId
                        : null,
                ];

                // Create each contact and associate it with the office
                $unit->contacts()->create($contactData);
            }

            $this->storeUnitDocuments($request, $unit, $storedDocumentPaths);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Unit updated successfully',
                'redirect' => route($request->redirect_url)
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating unit: ' . $e->getMessage());
            DB::rollBack();
            array_map([DocumentStorage::class, 'delete'], $storedDocumentPaths);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the unit. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $unit = Unit::findOrFail($id);
        $unit->delete();
        return redirect()->route('units.list')->with('success', 'Unit deleted successfully');
    }
    public function show($id)
    {
        $unit = Unit::findOrFail($id);
        return view('units.show', compact('unit'));
    }

    public function documents($id)
    {
        abort_unless(Gate::any(['unit-view', 'unit-edit']), 403);

        $unit = Unit::findOrFail($id);

        return response()->json([
            'documents' => $unit->documents()->orderBy('id')->get()->map(function ($document) use ($unit) {
                return [
                    'name' => $document->document_name,
                    'size' => $document->document_size,
                    'mime_type' => ($file = DocumentStorage::existingPath($document->document_path))
                        ? mime_content_type($file)
                        : null,
                    'url' => route('units.documents.show', [
                        'id' => $unit->id,
                        'documentId' => $document->id,
                    ]),
                ];
            }),
        ]);
    }

    public function showDocument($id, $documentId)
    {
        abort_unless(Gate::any(['unit-view', 'unit-edit']), 403);

        $document = Unit::findOrFail($id)->documents()->withTrashed()->findOrFail($documentId);
        $path = DocumentStorage::existingPath($document->document_path);

        abort_unless($path !== null, 404);

        $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        if (in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return response()->file($path, [
                'Content-Type' => $mimeType,
                'X-Content-Type-Options' => 'nosniff',
            ])->setContentDisposition('inline', $document->document_name);
        }

        return response()->download($path, $document->document_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyDocument(Request $request, $id, $documentId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:2000',
        ]);

        $document = Unit::findOrFail($id)->documents()->findOrFail($documentId);
        DB::transaction(function () use ($document, $validated) {
            $document->delete_reason = trim($validated['reason']);
            $document->deleted_by = Auth::id();
            $document->save();
            $document->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Attachment removed successfully.',
            'deleted_at' => $document->deleted_at?->format('d M Y, h:i A'),
        ]);
    }

    public function restoreDocument($id, $documentId)
    {
        $document = Unit::findOrFail($id)->documents()->onlyTrashed()->findOrFail($documentId);
        abort_unless(DocumentStorage::exists($document->document_path), 410, 'The attachment file has already been permanently deleted.');

        DB::transaction(function () use ($document) {
            $document->delete_reason = null;
            $document->deleted_by = null;
            $document->save();
            $document->restore();
        });

        return response()->json([
            'success' => true,
            'message' => 'Attachment restored successfully.',
        ]);
    }

    private function storeUnitDocuments(Request $request, Unit $unit, array &$storedPaths): void
    {
        // Private storage, random name, the validated extension (N-10): the file is reachable only
        // through the permission-checked unit documents route.
        foreach ($request->file('attachments', []) as $attachment) {
            $extension = strtolower($attachment->getClientOriginalExtension());
            $originalName = $attachment->getClientOriginalName();
            $size = $attachment->getSize();
            $path = DocumentStorage::store($attachment, $extension);

            $storedPaths[] = $path;
            $unit->documents()->create([
                'user_id' => Auth::id(),
                'document_name' => $originalName,
                'document_path' => $path,
                'document_size' => $size,
                'document_extension' => $extension,
            ]);
        }
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        $filters = $this->exportFiltersFromRequest($request);

        return Excel::download(new UnitsExport($type, $filters), "units_{$type}.csv");
    }

    protected function exportFiltersFromRequest(Request $request): array
    {
        $filters = [];
        foreach (['status_filter', 'office_filter', 'search'] as $key) {
            if ($request->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        return $filters;
    }
}
