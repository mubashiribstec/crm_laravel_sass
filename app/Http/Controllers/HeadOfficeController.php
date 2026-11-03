<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Office;
use Horsefly\Unit;
use Horsefly\Sale;
use Horsefly\Contact;
use Horsefly\ModuleNote;
use Horsefly\JobSource;
use Horsefly\Setting;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Exports\HeadOfficesExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Traits\Geocode;
use App\Traits\HidesPrivateContacts;
use App\Traits\RecordsBlacklistNotes;
use App\Support\StatusBadge;
use Illuminate\Support\Facades\Gate;
use App\Observers\ActionObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class HeadOfficeController extends Controller
{
    use Geocode, HidesPrivateContacts, RecordsBlacklistNotes;

    public function __construct()
    {
        $this->middleware('permission:office-index')->only(['index', 'getHeadOffices', 'getHeadOfficesOld']);
        $this->middleware('permission:office-create')->only(['create', 'store']);
        $this->middleware('permission:office-edit')->only(['edit', 'update', 'destroyDocument', 'restoreDocument']);
        $this->middleware('permission:office-blacklist')->only(['toggleBlacklist']);
        $this->middleware('permission:office-view')->only(['show', 'officeDetails', 'getModuleContacts']);
        $this->middleware('permission:office-delete')->only(['destroy']);
        $this->middleware('permission:office-export')->only(['export']);
        $this->middleware('permission:office-add-note')->only(['storeHeadOfficeShortNotes']);
    }

    /**
     * Display a listing of the applicants.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
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

        return view('head-offices.list', compact('jobSources'));
    }

    public function create()
    {
        return view('head-offices.create');
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_name' => 'required|string|max:255',
            'office_type' => 'required',
            'office_postcode' => ['required', 'string', 'min:3', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],

            'office_notes' => 'required|string|max:255',

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
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240',
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
            $officeData = $request->only([
                'office_name',
                'office_type',
                'office_postcode',
                'office_website',
                'office_notes',
            ]);

            $postcode = preg_replace('/\s+/', '', $request->office_postcode);
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

                    $officeData['office_lat'] = $result['lat'];
                    $officeData['office_lng'] = $result['lng'];
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }
            } else {
                $officeData['office_lat'] = $postcode_query->lat;
                $officeData['office_lng'] = $postcode_query->lng;
            }

            // Format data for office
            $officeData['user_id'] = Auth::id();
            $officeData['office_notes'] = $office_notes = $request->office_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            $office = Office::create($officeData);

            // Iterate through each contact provided in the request
            foreach ($request->input('contact_name') as $index => $contactName) {
                // Create contact data for each contact in the array
                $contactData = [
                    'contact_name' => $contactName,
                    'contact_email' => $request->input('contact_email')[$index],
                    'contact_phone' => preg_replace('/[^0-9]/', '', $request->input('contact_phone')[$index]),
                    'contact_landline' => $request->input('contact_landline')[$index]
                        ? preg_replace('/[^0-9]/', '', $request->input('contact_landline')[$index])
                        : null,
                    'contact_note' => $request->input('contact_note')[$index] ?? null,
                ];

                // Create each contact and associate it with the office
                $office->contact()->create($contactData);
            }

            // Generate UID
            $office->update(['office_uid' => md5($office->id)]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $office_notes,
                'module_noteable_id' => $office->id,
                'module_noteable_type' => 'Horsefly\Office',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            $this->storeOfficeDocuments($request, $office, $storedDocumentPaths);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Head Office created successfully',
                'redirect' => route('head-offices.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating head office: ' . $e->getMessage());
            DB::rollBack();
            File::delete(array_map('public_path', $storedDocumentPaths));
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the head office. Please try again.'
            ], 500);
        }
    }
    // public function getHeadOfficesOld(Request $request)
    // {
    //     $statusFilter = $request->input('status_filter', '');

    //     // Query builder with minimal selected columns
    //     $model = Office::query()
    //         ->leftJoin('contacts', function ($join) {
    //             $join->on('contacts.contactable_id', '=', 'offices.id')
    //                 ->where('contacts.contactable_type', 'Horsefly\\Office');
    //         })
    //         ->select(
    //             'offices.id',
    //             'offices.office_name',
    //             'offices.office_postcode',
    //             'offices.office_type',
    //             'offices.office_notes',
    //             'offices.status',
    //             'offices.created_at',
    //             'offices.updated_at'
    //         );

    //     // Apply status filter if provided
    //     switch ($statusFilter) {
    //         case 'active':
    //             $model->where('offices.status', 1);
    //             break;
    //         case 'inactive':
    //             $model->where('offices.status', 0);
    //             break;
    //         default:
    //             // No status filter applied
    //             break;
    //     }

    //     // Global search (now works on contact fields too)
    //     if ($request->filled('search.value')) {
    //         $search = trim($request->input('search.value'));
    //         $model->where(function ($q) use ($search) {
    //             $q->where('offices.office_name', 'LIKE', "%{$search}%")
    //                 ->orWhere('offices.office_postcode', 'LIKE', "%{$search}%")
    //                 ->orWhere('offices.office_type', 'LIKE', "%{$search}%")
    //                 ->orWhere('offices.office_notes', 'LIKE', "%{$search}%")
    //                 // Add contact fields
    //                 ->orWhere('contacts.contact_name', 'LIKE', "%{$search}%")
    //                 ->orWhere('contacts.contact_email', 'LIKE', "%{$search}%")
    //                 ->orWhere('contacts.contact_phone', 'LIKE', "%{$search}%")
    //                 ->orWhere('contacts.contact_landline', 'LIKE', "%{$search}%");
    //         });
    //     }

    //     // Sorting logic
    //     if ($request->has('order')) {
    //         $orderColumnIndex = $request->input('order.0.column');
    //         $orderColumn = $request->input("columns.$orderColumnIndex.data");
    //         $orderDirection = $request->input('order.0.dir', 'asc');

    //         if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
    //             // Handle contact.* columns differently if needed
    //             // $column = str_starts_with($orderColumn, 'contact.') ? 'contacts.' . str_replace('contact.', '', $orderColumn) : 'offices.' . $orderColumn;
    //             $model->orderBy($orderColumn, $orderDirection);
    //         } else {
    //             $model->orderBy('offices.created_at', 'desc');
    //         }
    //     } else {
    //         $model->orderBy('offices.created_at', 'desc');
    //     }

    //     if ($request->ajax()) {
    //         return DataTables::eloquent($model)
    //             ->addIndexColumn()
    //             ->addColumn('office_name', function ($office) {
    //                 return $office->formatted_office_name;
    //             })
    //             ->addColumn('office_postcode', function ($office) {
    //                 return $office->formatted_postcode;
    //             })
    //             ->addColumn('office_type', function ($office) {
    //                 return ucwords(str_replace('_', ' ', $office->office_type));
    //             })
    //             ->addColumn('contact_email', function ($office) {
    //                 return $office->contact->pluck('contact_email')->filter()->implode('<br>') ?: '-';
    //             })
    //             ->addColumn('contact_landline', function ($office) {
    //                 return $office->contact->pluck('contact_landline')->filter()->implode('<br>') ?: '-';
    //             })
    //             ->addColumn('contact_phone', function ($office) {
    //                 return $office->contact->pluck('contact_phone')->filter()->implode('<br>') ?: '-';
    //             })
    //             ->filterColumn('contact_email', function ($query, $keyword) {
    //                 $query->whereRaw("LOWER(contacts.contact_email) LIKE ?", ["%" . strtolower(trim($keyword)) . "%"]);
    //             })

    //             ->filterColumn('contact_phone', function ($query, $keyword) {
    //                 $query->whereRaw("contacts.contact_phone LIKE ?", ["%" . trim($keyword) . "%"]);
    //             })

    //             ->filterColumn('contact_landline', function ($query, $keyword) {
    //                 $query->whereRaw("contacts.contact_landline LIKE ?", ["%" . trim($keyword) . "%"]);
    //             })
    //             ->orderColumn('contact_email', function ($query, $order) {
    //                 $query->orderBy('contacts.contact_email', $order);
    //             })
    //             ->orderColumn('contact_phone', function ($query, $order) {
    //                 $query->orderBy('contacts.contact_phone', $order);
    //             })
    //             ->orderColumn('contact_landline', function ($query, $order) {
    //                 $query->orderBy('contacts.contact_landline', $order);
    //             })
    //             ->addColumn('updated_at', function ($office) {
    //                 return $office->formatted_updated_at;
    //             })
    //             ->addColumn('created_at', function ($office) {
    //                 return $office->formatted_created_at;
    //             })
    //             ->addColumn('office_notes', function ($office) {
    //                 $notes = nl2br(htmlspecialchars($office->office_notes ?? '', ENT_QUOTES, 'UTF-8'));
    //                 return '<a href="javascript:void(0);" title="Add Short Note" style="color:blue" onclick="addShortNotesModal(\'' . (int)$office->id . '\')">' . $notes . '</a>';
    //             })
    //             ->addColumn('status', function ($office) {
    //                 return $office->status ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
    //             })
    //             ->addColumn('action', function ($office) {
    //                 $postcode = $office->formatted_postcode;
    //                 $status = $office->status ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
    //                 $html = '<div class="btn-group dropstart">
    //                                 <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    //                                     <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
    //                                 </button>
    //                                 <ul class="dropdown-menu">';
    //                 if (Gate::allows('office-edit')) {
    //                     $html .= '<li><a class="dropdown-item" href="' . route('head-offices.edit', ['id' => $office->id]) . '">Edit</a></li>';
    //                 }
    //                 if (Gate::allows('office-view')) {
    //                     $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(' . (int)$office->id . ',\'' . addslashes(htmlspecialchars($office->office_name)) . '\',\'' . addslashes(htmlspecialchars($postcode)) . '\',\'' . addslashes(htmlspecialchars($status)) . '\')">View</a></li>';
    //                 }
    //                 if (Gate::allows('office-view-notes-history') || Gate::allows('office-view-manager-details')) {
    //                     $html .= '<li><hr class="dropdown-divider"></li>';
    //                 }
    //                 if (Gate::allows('office-view-notes-history')) {
    //                     $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $office->id . ')">Notes History</a></li>';
    //                 }
    //                 if (Gate::allows('office-view-manager-details')) {
    //                     $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $office->id . ')">Manager Details</a></li>';
    //                 }
    //                 $html .= '</ul></div>';
    //                 return $html;
    //             })
    //             ->rawColumns(['office_notes', 'contact_email', 'contact_phone', 'contact_landline', 'office_type', 'status', 'action'])
    //             ->toJson();
    //     }
    // }
    public function getHeadOffices(Request $request)
    {
        $statusFilter = $request->input('status_filter', '');

        // Base query
        $model = Office::query()
            ->with(['contact']) // Eager load contact relationship to solve N+1 Problem
            ->leftJoin('contacts', function ($join) {
                $join->on('contacts.contactable_id', '=', 'offices.id')
                    ->where('contacts.contactable_type', 'Horsefly\\Office');
            })
            ->select('offices.*')
            ->whereNotIn('offices.status', [4, 5])
            ->whereNull('offices.deleted_at')
            ->distinct();

        $this->excludePrivateNotes($model, 'offices.office_notes');

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $model->with(['contact' => function ($q) use ($hidePrivateData) {
                $q->where(function ($sub) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideValue) {
                        $sub->where(function ($fieldGroup) use ($hideValue) {
                            $fieldGroup->where(function ($nameCheck) use ($hideValue) {
                                $nameCheck->whereNull('contact_name')
                                    ->orWhere('contact_name', 'NOT LIKE', '%' . $hideValue . '%');
                            })
                                ->where(function ($emailCheck) use ($hideValue) {
                                    $emailCheck->whereNull('contact_email')
                                        ->orWhere('contact_email', 'NOT LIKE', '%' . $hideValue . '%');
                                })
                                ->where(function ($noteCheck) use ($hideValue) {
                                    $noteCheck->whereNull('contact_note')
                                        ->orWhere('contact_note', 'NOT LIKE', '%' . $hideValue . '%');
                                });
                        });
                    }
                });
            }]);
        } else {
            $model->with('contact');
        }

        // Apply status filter
        if ($statusFilter === 'active') {
            $model->where('offices.status', 1)
                ->where('offices.is_blacklist', 0);
        } elseif ($statusFilter === 'inactive') {
            $model->where('offices.status', 0)
                ->where('offices.is_blacklist', 0);
        } elseif ($statusFilter === 'blacklisted') {
            $model->where('offices.is_blacklist', 1);
        }

        // Handle search input
        if ($request->filled('search.value')) {
            $search = trim($request->input('search.value'));

            if (strlen($search) >= 2) {
                // Get office IDs matching the search query via Laravel Scout
                $officeIds = Office::search($search)->keys()->toArray();

                // Find contact IDs matching the search for contact fields
                $contactIds = Contact::where('contactable_type', 'Horsefly\\Office')
                    ->where(function ($q) use ($search) {
                        $q->where('contact_email', 'LIKE', "%{$search}%")
                            ->orWhere('contact_phone', 'LIKE', "%{$search}%")
                            ->orWhere('contact_landline', 'LIKE', "%{$search}%");
                    })->pluck('contactable_id')->toArray();

                // Merge and get unique IDs from both searches
                $allMatchingIds = array_unique(array_merge($officeIds, $contactIds));

                // Filter offices based on the combined matching IDs
                if (!empty($allMatchingIds)) {
                    $model->whereIn('offices.id', $allMatchingIds);
                }
            }
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumnIndex = $request->input('order.0.column');
            $orderColumn = $request->input("columns.$orderColumnIndex.data");
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('offices.created_at', 'desc');
            }
        } else {
            $model->orderBy('offices.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->setRowClass(fn ($office) => $office->is_blacklist ? 'table-danger blacklisted-row' : '')
                ->addColumn('office_name', function ($office) {
                    return $office->formatted_office_name;
                })
                ->editColumn('office_postcode', function ($office) {
                    $rawPostcode = trim($office->formatted_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $office->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($office->formatted_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($office->office_lat != null && $office->office_lng != null) {
                        $url = route('applicants.available_job', ['id' => $office->id, 'radius' => 15]);
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('office_type', function ($office) {
                    return ucwords(str_replace('_', ' ', $office->office_type));
                })
                ->addColumn('contact_email', function ($office) {
                    return $office->contact->pluck('contact_email')->filter()->implode('<br>') ?: '-';
                })
                ->addColumn('contact_landline', function ($office) {
                    return $office->contact->pluck('contact_landline')->filter()->implode('<br>') ?: '-';
                })
                ->addColumn('contact_phone', function ($office) {
                    return $office->contact->pluck('contact_phone')->filter()->implode('<br>') ?: '-';
                })
                ->filterColumn('contact_email', function ($query, $keyword) {
                    $query->whereRaw("LOWER(contacts.contact_email) LIKE ?", ["%" . strtolower(trim($keyword)) . "%"]);
                })
                ->filterColumn('contact_phone', function ($query, $keyword) {
                    $query->whereRaw("contacts.contact_phone LIKE ?", ["%" . trim($keyword) . "%"]);
                })
                ->filterColumn('contact_landline', function ($query, $keyword) {
                    $query->whereRaw("contacts.contact_landline LIKE ?", ["%" . trim($keyword) . "%"]);
                })
                ->filterColumn('office_notes', function ($query, $keyword) {
                    $query->whereRaw("LOWER(offices.office_notes) LIKE ?", ["%" . strtolower(trim($keyword)) . "%"]);
                })
                ->orderColumn('contact_email', function ($query, $order) {
                    $query->orderBy('contacts.contact_email', $order);
                })
                ->orderColumn('contact_phone', function ($query, $order) {
                    $query->orderBy('contacts.contact_phone', $order);
                })
                ->orderColumn('contact_landline', function ($query, $order) {
                    $query->orderBy('contacts.contact_landline', $order);
                })
                ->addColumn('updated_at', function ($office) {
                    return $office->formatted_updated_at;
                })
                ->addColumn('created_at', function ($office) {
                    return $office->formatted_created_at;
                })
                ->editColumn('office_notes', function ($office) {
                    $notes = nl2br(htmlspecialchars($office->office_notes ?? '', ENT_QUOTES, 'UTF-8'));
                    return '<a href="javascript:void(0);" title="Add Short Note" style="color:blue" onclick="addShortNotesModal(\'' . (int)$office->id . '\')">' . $notes . '</a>';
                })
                ->addColumn('status', function ($office) {
                    return $this->officeStatusBadges($office);
                })
                ->addColumn('action', function ($office) {
                    $postcode = $office->formatted_postcode;
                    $status = $this->officeStatusBadges($office);
                    $html = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">';
                    if (Gate::allows('office-view')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(' . (int)$office->id . ',\'' . addslashes(htmlspecialchars($office->office_name)) . '\',\'' . addslashes(htmlspecialchars($postcode)) . '\',\'' . addslashes(htmlspecialchars($status)) . '\')">View</a></li>';
                    }
                    if (Gate::allows('office-edit')) {
                        $html .= '<li><a class="dropdown-item" href="' . route('head-offices.edit', ['id' => $office->id]) . '">Edit</a></li>';
                    }
                    if (Gate::allows('office-blacklist')) {
                        $blockLabel = $office->is_blacklist ? 'Remove from Blacklist' : 'Add to Blacklist';
                        $blockStatus = $office->is_blacklist ? 0 : 1;
                        $html .= '<li><a class="dropdown-item ' . ($office->is_blacklist ? 'text-success' : 'text-danger') . '" href="javascript:void(0);" onclick="toggleOfficeBlacklist(' . (int)$office->id . ', ' . $blockStatus . ')">' . $blockLabel . '</a></li>';
                    }

                    if (
                        Gate::allows('office-view')
                        || Gate::allows('office-edit')
                        || Gate::allows('office-view-notes-history')
                        || Gate::allows('office-view-manager-details')
                    ) {
                        $html .= '<li><hr class="dropdown-divider"></li>';
                    }
                    if (Gate::allows('office-view') || Gate::allows('office-edit')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="showOfficeDocuments(' . (int) $office->id . ')">Attachments</a></li>';
                    }
                    if (Gate::allows('office-view-notes-history')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $office->id . ')">Notes History</a></li>';
                    }
                    if (Gate::allows('office-view-manager-details')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewManagerDetails(' . $office->id . ')">Manager Details</a></li>';
                    }
                    $html .= '</ul></div>';
                    return $html;
                })
                ->rawColumns(['office_notes', 'contact_email', 'office_postcode', 'contact_phone', 'contact_landline', 'office_type', 'status', 'action'])
                ->toJson();
        }
    }
    public function storeHeadOfficeShortNotes(Request $request)
    {
        $user = Auth::user();

        $office_id = $request->input('office_id');
        $details = $request->input('details');
        $office_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        $updateData = ['office_notes' => $office_notes];

        Office::where('id', $office_id)->update($updateData);

        // Disable previous module note
        ModuleNote::where([
            'module_noteable_id' => $office_id,
            'module_noteable_type' => 'Horsefly\Office'
        ])
            ->where('status', 1)
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $office_notes,
            'module_noteable_id' => $office_id,
            'module_noteable_type' => 'Horsefly\Office',
            'user_id' => $user->id,
        ]);

        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        // Log audit
        $office = Office::where('id', $office_id)->select('office_name', 'office_notes', 'id')->first();
        $observer = new ActionObserver();
        $observer->customOfficeAudit($office, 'office_notes');

        return redirect()->to(url()->previous());
    }
    public function officeDetails($id)
    {
        $office = Office::findOrFail($id);
        return view('head-offices.details', compact('office'));
    }

    private function officeStatusBadges($office): string
    {
        $status = $office->status
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-secondary">Inactive</span>';

        return StatusBadge::withBlacklist($status, (bool) $office->is_blacklist);
    }

    public function toggleBlacklist(Request $request, $id)
    {
        $targetBlacklistState = $request->boolean('is_blacklist');
        $noteInput = $request->input('blacklist_note');
        if (is_string($noteInput)) {
            $request->merge(['blacklist_note' => trim($noteInput)]);
        }

        $request->validate([
            'is_blacklist' => ['required', 'boolean'],
            'blacklist_note' => ['required', 'string', 'max:2000'],
        ]);
        $blacklistNote = $request->input('blacklist_note');

        DB::transaction(function () use ($id, $targetBlacklistState, $blacklistNote) {
            $user = Auth::user();
            $note = $blacklistNote . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

            $office = Office::whereKey($id)->lockForUpdate()->firstOrFail();
            $office->update(['is_blacklist' => $targetBlacklistState]);
            $this->recordOfficeNote($office, $note, $user->id);

            // Blacklisting cascades to units and sales. Removing the office from the
            // blacklist does not: units and sales are removed manually (their own
            // toggles refuse while the parent is still blacklisted).
            if (!$targetBlacklistState) {
                return;
            }

            // Skip children that are already blacklisted so their own notes are kept.
            $units = Unit::where('office_id', $office->id)
                ->where('is_blacklist', false)
                ->lockForUpdate()
                ->get();
            $sales = Sale::where('office_id', $office->id)
                ->where('is_blacklist', false)
                ->lockForUpdate()
                ->get();

            $observer = new ActionObserver();

            foreach ($units as $unit) {
                $unit->update(['is_blacklist' => true]);
                $this->recordUnitNote($unit, $note, $user->id);
            }

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
                ? 'Office, its units, and related sales have been added to the blacklist.'
                : 'Office has been removed from the blacklist. Its units and sales must be removed manually.',
        ]);
    }

    public function edit($id, Request $request)
    {
        $office = Office::find($id);
        $contactsQuery = Contact::where('contactable_id', $office->id)
            ->where('contactable_type', 'Horsefly\Office');

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $contactsQuery->where(function ($sub) use ($hidePrivateData) {
                foreach ($hidePrivateData as $hideValue) {
                    $sub->where(function ($fieldGroup) use ($hideValue) {
                        $fieldGroup->where(function ($nameCheck) use ($hideValue) {
                            $nameCheck->whereNull('contact_name')
                                ->orWhere('contact_name', 'NOT LIKE', '%' . $hideValue . '%');
                        })
                            ->where(function ($emailCheck) use ($hideValue) {
                                $emailCheck->whereNull('contact_email')
                                    ->orWhere('contact_email', 'NOT LIKE', '%' . $hideValue . '%');
                            })
                            ->where(function ($noteCheck) use ($hideValue) {
                                $noteCheck->whereNull('contact_note')
                                    ->orWhere('contact_note', 'NOT LIKE', '%' . $hideValue . '%');
                            });
                    });
                }
            });
        }

        $contacts = $contactsQuery->get();

        // Check if the applicant is found
        if (!$office) {
            Log::info('Head Office not found with ID: ' . $id);
        }

        $redirect_url = $request->input('redirect_url', 'head-offices.list');

        $office->load('documents');
        $deletedDocuments = $office->documents()->onlyTrashed()->orderByDesc('deleted_at')->get();

        return view('head-offices.edit', compact('office', 'contacts', 'redirect_url', 'deletedDocuments'));
    }
    public function update(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_name' => 'required|string|max:255',
            'office_type' => 'required',
            'office_postcode' => ['required', 'string', 'min:3', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            'office_notes' => 'required|string|max:255',

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
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240',
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
            $officeData = $request->only([
                'office_name',
                'office_type',
                'office_postcode',
                'office_website',
                'office_notes',
            ]);

            // Get the office ID from the request
            $id = $request->input('office_id');

            // Retrieve the office record
            $office = Office::find($id);

            // If the applicant doesn't exist, throw an exception
            if (!$office) {
                throw new \Exception("Head Office not found with ID: " . $id);
            }

            $officeData['office_notes'] = $office_notes = $request->office_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            $postcode = preg_replace('/\s+/', '', $request->office_postcode);

            if ($postcode != preg_replace('/\s+/', '', $office->office_postcode)) {
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

                        $officeData['office_lat'] = $result['lat'];
                        $officeData['office_lng'] = $result['lng'];
                    } catch (\Exception $e) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to locate address: ' . $e->getMessage()
                        ], 400);
                    }
                } else {
                    $officeData['office_lat'] = $postcode_query->lat;
                    $officeData['office_lng'] = $postcode_query->lng;
                }
            }

            // Update the Office with the validated and formatted data
            $office->update($officeData);

            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Office'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $office_notes,
                'module_noteable_id' => $office->id,
                'module_noteable_type' => 'Horsefly\Office',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            Contact::where('contactable_id', $office->id)
                ->where('contactable_type', 'Horsefly\Office')->delete();

            // Iterate through each contact provided in the request
            foreach ($request->input('contact_name') as $index => $contactName) {
                // Create contact data for each contact in the array
                $contactData = [
                    'contact_name' => $contactName,
                    'contact_email' => $request->input('contact_email')[$index],
                    'contact_phone' => preg_replace('/[^0-9]/', '', $request->input('contact_phone')[$index]),
                    'contact_landline' => $request->input('contact_landline')[$index]
                        ? preg_replace('/[^0-9]/', '', $request->input('contact_landline')[$index])
                        : null,
                    'contact_note' => $request->input('contact_note')[$index] ?? null,
                ];

                // Create each contact and associate it with the office
                $office->contact()->create($contactData);
            }

            $this->storeOfficeDocuments($request, $office, $storedDocumentPaths);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Head Office updated successfully',
                'redirect' => route($request->redirect_url)
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating head office: ' . $e->getMessage());
            DB::rollBack();
            File::delete(array_map('public_path', $storedDocumentPaths));
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the head office. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $office = Office::findOrFail($id);
        $office->delete();
        return redirect()->route('head-offices.list')->with('success', 'Head Office deleted successfully');
    }
    public function show($id)
    {
        $office = Office::findOrFail($id);
        return view('head-offices.show', compact('office'));
    }

    public function documents($id)
    {
        abort_unless(Gate::any(['office-view', 'office-edit']), 403);

        $office = Office::findOrFail($id);

        return response()->json([
            'documents' => $office->documents()->orderBy('id')->get()->map(function ($document) use ($office) {
                return [
                    'name' => $document->document_name,
                    'size' => $document->document_size,
                    'mime_type' => File::exists(public_path($document->document_path))
                        ? mime_content_type(public_path($document->document_path))
                        : null,
                    'url' => route('head-offices.documents.show', [
                        'id' => $office->id,
                        'documentId' => $document->id,
                    ]),
                ];
            }),
        ]);
    }

    public function showDocument($id, $documentId)
    {
        abort_unless(Gate::any(['office-view', 'office-edit']), 403);

        $document = Office::findOrFail($id)->documents()->withTrashed()->findOrFail($documentId);
        $path = public_path($document->document_path);

        abort_unless(File::exists($path), 404);

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

        $document = Office::findOrFail($id)->documents()->findOrFail($documentId);
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
        $document = Office::findOrFail($id)->documents()->onlyTrashed()->findOrFail($documentId);
        abort_unless(File::exists(public_path($document->document_path)), 410, 'The attachment file has already been permanently deleted.');

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

    private function storeOfficeDocuments(Request $request, Office $office, array &$storedPaths): void
    {
        foreach ($request->file('attachments', []) as $attachment) {
            $originalName = $attachment->getClientOriginalName();
            $size = $attachment->getSize();
            $extension = $attachment->guessExtension();
            $filename = Str::uuid() . ($extension ? '.' . $extension : '');
            $dateFolder = Carbon::now()->format('Y/m/d');
            $directory = public_path('uploads/docs/' . $dateFolder);
            File::ensureDirectoryExists($directory, 0755, true);
            $attachment->move($directory, $filename);
            $path = 'uploads/docs/' . $dateFolder . '/' . $filename;

            $storedPaths[] = $path;
            $office->documents()->create([
                'user_id' => Auth::id(),
                'document_name' => $originalName,
                'document_path' => $path,
                'document_size' => $size,
                'document_extension' => $extension,
            ]);
        }
    }
    public function getModuleContacts(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'module' => 'required',
            ]);

            $module = 'Horsefly\\' . $request->input('module');
            // contactable_id is a VARCHAR. Comparing it to an integer makes
            // MySQL cast the column (index skipped) instead of the literal.
            $contactableId = (string) $request->input('id');

            $contactsQuery = DB::table('contacts')
                ->leftJoin('job_sources', 'job_sources.id', '=', 'contacts.job_source_id')
                ->where('contacts.contactable_id', $contactableId)
                ->where('contacts.contactable_type', $module)
                ->orderByDesc('contacts.id')
                ->select([
                    'contacts.contact_name',
                    'contacts.contact_email',
                    'contacts.contact_phone',
                    'contacts.contact_landline',
                    'contacts.contact_note',
                    'job_sources.name as job_source_name',
                ]);

            $this->excludePrivateContacts($contactsQuery);

            $context = $this->privateDataContext();
            $hideValues = $context === null ? [] : $context['values'];
            foreach ($hideValues as $hideValue) {
                $contactsQuery->where(function ($q) use ($hideValue) {
                    $q->whereNull('job_sources.name')
                        ->orWhere('job_sources.name', 'NOT LIKE', '%' . $hideValue . '%');
                });
            }

            $contacts = $contactsQuery->get();

            $data = $contacts->map(function ($contact) {
                $sourceName = $contact->job_source_name;

                return [
                    'contact_name' => $contact->contact_name,
                    'contact_email' => $contact->contact_email,
                    'contact_phone' => $contact->contact_phone,
                    'contact_landline' => $contact->contact_landline,
                    'contact_note' => $contact->contact_note,
                    'job_source_name' => $sourceName,
                    'is_hayaibu_source' => $sourceName !== null
                        && stripos($sourceName, 'hayaibu') !== false,
                ];
            })->values();

            return response()->json([
                'data' => $data,
                'success' => true,
                'message' => $data->isEmpty() ? 'No manager details found.' : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        $filters = [];
        foreach (['status_filter', 'search'] as $key) {
            if ($request->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        return Excel::download(new HeadOfficesExport($type, $filters), "headOffices_{$type}.csv");
    }
}
