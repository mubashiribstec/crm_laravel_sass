<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Horsefly\Applicant;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\ApplicantNote;
use Horsefly\ModuleNote;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\Sale;
use App\Observers\ActionObserver;
use Exception;

class ModuleNotesController extends Controller
{
    public function store(Request $request)
    {
        // Notes (and the flags they can change) need note permissions; the controller used to
        // accept any logged-in account. Each screen has its own "*-add-note" permission.
        abort_unless($this->hasPermissionLike('-add-note'), 403, 'You do not have permission to add notes.');
        try {
            $input = $request->all();
            $input['module'] = strip_tags((string) ($input['module'] ?? ''));
            $input['details'] = strip_tags((string) ($input['details'] ?? ''));
            $request->replace($input);

            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'module' => "required|in:Office,Sale,Unit,Applicant",
                'module_key' => "required",
                'details' => "required|string",
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                    'message' => 'Please fix the errors in the form'
                ], 422);
            }

            $noteDetail = '';
            if (isset($request->request_from_applicants) && $request->input('module') == 'Applicant') {
                $applicant_id = $request->input('module_key');

                if ($request->has('hangup_call') && $request->input('hangup_call') == 'on') {
                    $noteDetail .= '<strong>Date:</strong> ' . now()->format('d-m-Y') . '<br>';
                    $noteDetail .= '<strong>Call Hung up/Not Interested:</strong> Yes<br>';
                    $noteDetail .= '<strong>Details:</strong> ' . nl2br(htmlspecialchars($request->input('details'))) . '<br>';
                    $noteDetail .= '<strong>By:</strong> ' . $user->name . '<br>';

                    Applicant::where('id', $applicant_id)->update([
                        'is_temp_not_interested' => true,
                        'is_no_response' => false,
                        'is_no_job' => false,
                        'is_blocked' => false,
                        'is_circuit_busy' => false,
                        'applicant_notes' => $noteDetail
                    ]);

                    // Save applicant note
                    $applicantNote = ApplicantNote::create([
                        'details' => $request->input('details') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y'),
                        'applicant_id' => $applicant_id,
                        'moved_tab_to' => 'not interested',
                        'user_id' => $user->id,
                    ]);

                    $applicantNote->update([
                        'note_uid' => md5($applicantNote->id),
                    ]);

                } elseif ($request->has('no_job') && $request->input('no_job') == 'on') {
                    $noteDetail .= '<strong>Date:</strong> ' . now()->format('d-m-Y') . '<br>';
                    $noteDetail .= '<strong>No Job:</strong> Yes<br>';
                    $noteDetail .= '<strong>Details:</strong> ' . nl2br(htmlspecialchars($request->input('details'))) . '<br>';
                    $noteDetail .= '<strong>By:</strong> ' . $user->name . '<br>';

                    Applicant::where('id', $applicant_id)->update([
                        'is_no_response' => false,
                        'is_temp_not_interested' => false,
                        'is_blocked' => false,
                        'is_circuit_busy' => false,
                        'is_no_job' => true,
                        'applicant_notes' => $noteDetail
                    ]);

                    // Save applicant note
                    $applicantNote = ApplicantNote::create([
                        'details' => $request->input('details') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y'),
                        'applicant_id' => $applicant_id,
                        'moved_tab_to' => 'no job',
                        'user_id' => $user->id,
                    ]);

                    $applicantNote->update([
                        'note_uid' => md5($applicantNote->id),
                    ]);

                } else {
                    $transportType = $request->has('transport_type') ? implode(', ', $request->input('transport_type')) : '';
                    $shiftPattern = $request->has('shift_pattern') ? implode(', ', $request->input('shift_pattern')) : '';

                    $noteDetail .= '<strong>Date:</strong> ' . now()->format('d-m-Y') . '<br>';
                    $noteDetail .= '<strong>Current Employer Name:</strong> ' . htmlspecialchars($request->input('current_employer_name')) . '<br>';
                    $noteDetail .= '<strong>PostCode:</strong> ' . htmlspecialchars($request->input('postcode')) . '<br>';
                    $noteDetail .= '<strong>Current/Expected Salary:</strong> ' . htmlspecialchars($request->input('expected_salary')) . '<br>';
                    $noteDetail .= '<strong>Qualification:</strong> ' . htmlspecialchars($request->input('qualification')) . '<br>';
                    $noteDetail .= '<strong>Transport Type:</strong> ' . htmlspecialchars($transportType) . '<br>';
                    $noteDetail .= '<strong>Shift Pattern:</strong> ' . htmlspecialchars($shiftPattern) . '<br>';
                    $noteDetail .= '<strong>Nursing Home:</strong> ' . ($request->has('nursing_home') ? 'Yes' : 'No') . '<br>';
                    $noteDetail .= '<strong>Alternate Weekend:</strong> ' . ($request->has('alternate_weekend') ? 'Yes' : 'No') . '<br>';
                    $noteDetail .= '<strong>Interview Availability:</strong> ' . ($request->has('interview_availability') ? 'Available' : 'Not Available') . '<br>';
                    $noteDetail .= '<strong>No Job:</strong> ' . ($request->has('no_job') ? 'Yes' : 'No') . '<br>';
                    $noteDetail .= '<strong>Details:</strong> ' . nl2br(htmlspecialchars($request->input('details'))) . '<br>';
                    $noteDetail .= '<strong>By:</strong> ' . $user->name . '<br>';

                    Applicant::where('id', $applicant_id)->update([
                        'applicant_notes' => $request->input('details') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y')
                    ]);
                }

            } else {
                $noteDetail .= $request->input('details') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');
            }

            $model_class = 'Horsefly\\' . $request->input('module');
            $model = $model_class::find($request->input('module_key'));

            if ($model) {
                ModuleNote::where('module_noteable_id', $request->input('module_key'))
                    ->where('status', 1)
                    ->update(['status' => 0]);

                $module_note = ModuleNote::create([
                    'user_id' => $user->id,
                    'details' => $noteDetail,
                    'module_noteable_type' => $model_class,
                    'module_noteable_id' => $request->input('module_key'),
                ]);

                $module_note->update(['module_note_uid' => md5($module_note->id)]);

                // Log audit
                $applicant = Applicant::find($applicant_id)->select('applicant_name', 'id')->first();
                $observer = new ActionObserver();
                $observer->customApplicantAudit($applicant, 'applicant_notes');
            }

            return response()->json([
                'success' => true,
                'message' => 'Note added successfully!'  // <-- this message will go to frontend
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Note storing error: ' . $e->getMessage());

            echo '<div class="alert alert-danger border-0 alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    <span class="font-weight-semibold">Error:</span> An unexpected error occurred while saving the note.
                </div>';
        }
    }
    public function getModuleNotesHistory(Request $request)
    {
        abort_unless($this->hasPermissionLike('-view-notes-history') || $this->hasPermissionLike('-view-note'), 403);
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'id' => 'required|integer',
                'module' => 'required|in:Office,Sale,Unit,Applicant',   // becomes a class name below
            ]);

            $model_class = 'Horsefly\\' . $request->module;

            // Fetch the module notes by the given ID
            $moduleNote = ModuleNote::where('module_noteable_id', $request->id)
                ->where('module_noteable_type', $model_class)
                ->latest()->get();

            // Check if the module note was found
            if (!$moduleNote) {
                return response()->json(['error' => 'Module note not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $moduleNote,
                'success' => true
            ]);

        } catch (Exception $e) {
            // If an error occurs, catch it and return a meaningful error message
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again later.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500); // Internal server error
        }
    }
    public function getModuleUpdateHistory(Request $request)
    {
        abort_unless($this->hasPermissionLike('-view-notes-history') || $this->hasPermissionLike('-view-note'), 403);
        try {
            $validator = Validator::make($request->all(), [
                'module' => 'required|in:Office,Sale,Unit,Applicant',   // becomes a class name below
                'module_key' => 'required|integer'
            ]);

            $validator->validate();
            $module = $request->input('module');
            $model_class = 'Horsefly\\' . $module;
            $audit_data = [];

            if ($module == 'Sale') {
                $model = $model_class::with('unit', 'office', 'audits.user')
                    ->find($request->input('module_key'));

                if (!$model) {
                    return response()->json([
                        'error' => 'Record not found.',
                        'success' => false
                    ], 404);
                }

                $index = 0;
                $audit_history_collection = [];

                foreach ($model->audits as $audit) {

                    // ✅ Handle both string and array for data column
                    $auditData = is_string($audit->data)
                        ? json_decode($audit->data, true)
                        : (is_array($audit->data) ? $audit->data : []);

                    if (empty($auditData)) {
                        continue;
                    }

                    $is_created = is_string($audit->message)
                        && str_contains($audit->message, 'has been created');

                    // "created" entry — parse auditData directly
                    if (!isset($auditData['changes_made'])) {

                        $created_data = Arr::except($auditData, [
                            'user_id',
                            'created_at',
                            'updated_at',
                            'sale_uid',
                            'lat',
                            'lng',
                            'job_source_id',
                            'job_category_id',
                            'job_title_id'
                        ]);

                        // FK label resolution
                        if (isset($created_data['office_id'])) {
                            $created_data['office_name'] = optional(Office::find($created_data['office_id']))->office_name;
                            unset($created_data['office_id']);
                        }
                        if (isset($created_data['job_category_id'])) {
                            $created_data['job_category'] = optional(JobCategory::find($created_data['job_category_id']))->name;
                            unset($created_data['job_category_id']);
                        }
                        if (isset($created_data['job_title_id'])) {
                            $created_data['job_title'] = optional(JobTitle::find($created_data['job_title_id']))->name;
                            unset($created_data['job_title_id']);
                        }
                        if (isset($created_data['unit_id'])) {
                            $created_data['unit_name'] = optional(Unit::find($created_data['unit_id']))->unit_name;
                            unset($created_data['unit_id']);
                        }

                        // Format dates
                        if (isset($created_data['updated_at'])) {
                            $created_data['updated_at'] = Carbon::parse($created_data['updated_at'])->format('d M Y, h:i A');
                        }
                        if (isset($created_data['created_at'])) {
                            $created_data['created_at'] = Carbon::parse($created_data['created_at'])->format('d M Y, h:i A');
                        }

                        // Status mapping
                        if (isset($created_data['status'])) {
                            $created_data['status'] = match ((string) $created_data['status']) {
                                '0', 'false' => 'Inactive',
                                '1', 'true' => 'Active',
                                '2' => 'Pending',
                                '3' => 'Rejected',
                                '4' => 'Scraped',
                                default => $created_data['status']
                            };
                        }
                        if (isset($created_data['is_on_hold'])) {
                            $created_data['is_on_hold'] = match ((string) $created_data['is_on_hold']) {
                                '0', 'false' => 'No',
                                '1', 'true' => 'Yes',
                                '2' => 'Pending',
                                default => $created_data['is_on_hold']
                            };
                        }

                        // ✅ Pass parsed data + is_created flag
                        $audit_history_collection[$index]['is_created'] = true;
                        $audit_history_collection[$index]['changes_made'] = !empty($created_data) ? $created_data : null;
                        $audit_history_collection[$index]['changes_made_by'] = ucwords(optional($audit->user)->name ?? 'Unknown');
                        $audit_history_collection[$index]['date'] = $audit->created_at->format('d M Y, h:i A');
                        $index++;
                        continue;
                    }

                    // "updated" entry
                    $changes_made_raw = is_string($auditData['changes_made'])
                        ? json_decode($auditData['changes_made'], true)
                        : $auditData['changes_made'];

                    if (!is_array($changes_made_raw)) {
                        $changes_made_raw = [];
                    }

                    $changes_made = Arr::except($changes_made_raw, [
                        'user_id',
                        'created_at',
                        'updated_at',
                        'sale_uid',
                        'lat',
                        'lng',
                        'job_source_id',
                        'job_category_id',
                        'job_title_id'
                    ]);

                    // FK label resolution
                    if (isset($changes_made['office_id'])) {
                        $changes_made['office_name'] = optional(Office::find($changes_made['office_id']))->office_name;
                        unset($changes_made['office_id']);
                    }
                    if (isset($changes_made['job_category_id'])) {
                        $changes_made['job_category'] = optional(JobCategory::find($changes_made['job_category_id']))->name;
                        unset($changes_made['job_category_id']);
                    }
                    if (isset($changes_made['job_title_id'])) {
                        $changes_made['job_title'] = optional(JobTitle::find($changes_made['job_title_id']))->name;
                        unset($changes_made['job_title_id']);
                    }
                    if (isset($changes_made['unit_id'])) {
                        $changes_made['unit_name'] = optional(Unit::find($changes_made['unit_id']))->unit_name;
                        unset($changes_made['unit_id']);
                    }

                    // Format dates
                    if (isset($changes_made['updated_at'])) {
                        $changes_made['updated_at'] = Carbon::parse($changes_made['updated_at'])->format('d M Y, h:i A');
                    }
                    if (isset($changes_made['created_at'])) {
                        $changes_made['created_at'] = Carbon::parse($changes_made['created_at'])->format('d M Y, h:i A');
                    }

                    // Status mapping
                    if (isset($changes_made['status'])) {
                        $changes_made['status'] = match ((string) $changes_made['status']) {
                            '0', 'false' => 'Inactive',
                            '1', 'true' => 'Active',
                            '2' => 'Pending',
                            '3' => 'Rejected',
                            '4' => 'Scraped',
                            default => $changes_made['status']
                        };
                    }
                    if (isset($changes_made['is_on_hold'])) {
                        $changes_made['is_on_hold'] = match ((string) $changes_made['is_on_hold']) {
                            '0', 'false' => 'No',
                            '1', 'true' => 'Yes',
                            '2' => 'Pending',
                            default => $changes_made['is_on_hold']
                        };
                    }

                    // ✅ is_created from message check for update entries
                    $audit_history_collection[$index]['is_created'] = $is_created;
                    $audit_history_collection[$index]['changes_made'] = !empty($changes_made) ? $changes_made : null;
                    $audit_history_collection[$index]['changes_made_by'] = ucwords(optional($audit->user)->name ?? 'Unknown');
                    $audit_history_collection[$index]['date'] = $audit->created_at->format('d M Y, h:i A');
                    $index++;
                }

                // ✅ latest first
                $audit_data = array_reverse($audit_history_collection);

            } elseif ($module == 'Applicant') {
                $model = $model_class::with([
                    'jobCategory',
                    'jobTitle',
                    'jobSource',
                    'audits.user'
                ])->find($request->input('module_key'));

                if (!$model) {
                    return response()->json([
                        'error' => 'Record not found.',
                        'success' => false
                    ], 404);
                }

                $index = 0;
                $audit_history_collection = [];

                foreach ($model->audits as $audit) {

                    $auditData = is_string($audit->data)
                        ? json_decode($audit->data, true)
                        : (is_array($audit->data) ? $audit->data : []);

                    $is_created = is_string($audit->message)
                        && str_contains($audit->message, 'has been created');

                    // "created" entry — parse auditData directly
                    if (!isset($auditData['changes_made'])) {

                        $created_data = Arr::except($auditData, [
                            'user_id',
                            'created_at',
                            'updated_at',
                            'sale_uid',
                            'lat',
                            'lng',
                            'job_source_id',
                            'job_category_id',
                            'job_title_id'
                        ]);

                        // FK label resolution
                        if (isset($created_data['office_id'])) {
                            $created_data['office_name'] = optional(Office::find($created_data['office_id']))->office_name;
                            unset($created_data['office_id']);
                        }
                        if (isset($created_data['unit_id'])) {
                            $created_data['unit_name'] = optional(Unit::find($created_data['unit_id']))->unit_name;
                            unset($created_data['unit_id']);
                        }

                        // Status mapping
                        if (isset($created_data['status'])) {
                            $created_data['status'] = match ((string) $created_data['status']) {
                                '0', 'false' => 'Inactive',
                                '1', 'true' => 'Active',
                                '2' => 'Pending',
                                '3' => 'Rejected',
                                '4' => 'Scrapped',
                                default => $created_data['status']
                            };
                        }
                        if (isset($created_data['is_on_hold'])) {
                            $created_data['is_on_hold'] = match ((string) $created_data['is_on_hold']) {
                                '0', 'false' => 'No',
                                '1', 'true' => 'Yes',
                                default => $created_data['is_on_hold']
                            };
                        }

                        // ✅ Pass parsed data + is_created flag
                        $audit_history_collection[$index]['is_created'] = true;
                        $audit_history_collection[$index]['changes_made'] = !empty($created_data) ? $created_data : null;
                        $audit_history_collection[$index]['changes_made_by'] = ucwords(optional($audit->user)->name ?? 'Unknown');
                        $audit_history_collection[$index]['date'] = $audit->created_at->format('d M Y, h:i A');
                        $index++;
                        continue;
                    }

                    $changes_made_raw = is_string($auditData['changes_made'])
                        ? json_decode($auditData['changes_made'], true)
                        : $auditData['changes_made'];

                    if (!is_array($changes_made_raw)) {
                        $changes_made_raw = [];
                    }

                    $changes_made = Arr::except($changes_made_raw, [
                        'user_id',
                        'created_at',
                        'updated_at',
                        'sale_uid',
                        'lat',
                        'lng',
                        'job_source_id',
                        'job_category_id',
                        'job_title_id'
                    ]);

                    // FK label resolution
                    if (isset($changes_made['office_id'])) {
                        $changes_made['office_name'] = optional(Office::find($changes_made['office_id']))->office_name;
                        unset($changes_made['office_id']);
                    }
                    if (isset($changes_made['job_category_id'])) {
                        $changes_made['job_category'] = optional(JobCategory::find($changes_made['job_category_id']))->name;
                        unset($changes_made['job_category_id']);
                    }
                    if (isset($changes_made['job_title_id'])) {
                        $changes_made['job_title'] = optional(JobTitle::find($changes_made['job_title_id']))->name;
                        unset($changes_made['job_title_id']);
                    }
                    if (isset($changes_made['unit_id'])) {
                        $changes_made['unit_name'] = optional(Unit::find($changes_made['unit_id']))->unit_name;
                        unset($changes_made['unit_id']);
                    }

                    // Format dates
                    if (isset($changes_made['updated_at'])) {
                        $changes_made['updated_at'] = Carbon::parse($changes_made['updated_at'])->format('d M Y, h:i A');
                    }
                    if (isset($changes_made['created_at'])) {
                        $changes_made['created_at'] = Carbon::parse($changes_made['created_at'])->format('d M Y, h:i A');
                    }

                    // Status mapping
                    if (isset($changes_made['status'])) {
                        $changes_made['status'] = match ((string) $changes_made['status']) {
                            '0', 'false' => 'Inactive',
                            '1', 'true' => 'Active',
                            '2' => 'Pending',
                            '3' => 'Rejected',
                            '4' => 'Scrapped',
                            default => $changes_made['status']
                        };
                    }
                    if (isset($changes_made['is_on_hold'])) {
                        $changes_made['is_on_hold'] = match ((string) $changes_made['is_on_hold']) {
                            '0', 'false' => 'No',
                            '1', 'true' => 'Yes',
                            '2' => 'Pending',
                            default => $changes_made['is_on_hold']
                        };
                    }

                    // ✅ is_created = false for update entries
                    $audit_history_collection[$index]['is_created'] = $is_created;
                    $audit_history_collection[$index]['changes_made'] = !empty($changes_made) ? $changes_made : null;
                    $audit_history_collection[$index]['changes_made_by'] = ucwords(optional($audit->user)->name ?? 'Unknown');
                    $audit_history_collection[$index]['date'] = $audit->created_at->format('d M Y, h:i A');
                    $index++;
                }

                $audit_data = array_reverse($audit_history_collection);


            } else {
                $model = $model_class::with([
                    'jobCategory',
                    'jobTitle',
                    'jobSource',
                    'updated_by_audits.user',
                    'created_by_audit.user',
                ])->find($request->input('module_key'));

                if (!$model) {
                    return response()->json([
                        'error' => 'Record not found.',
                        'success' => false
                    ], 404);
                }

                $index = 0;

                foreach ($model->updated_by_audits as $audit) {
                    if (!empty($audit->data['changes_made'])) {
                        $changes_made = Arr::except($audit->data['changes_made'], ['user_id', 'created_at', 'updated_at', 'lat', 'lng', 'job_source_id', 'job_category_id', 'job_title_id']);
                        if (empty($changes_made))
                            continue;

                        if (isset($changes_made['job_source_id'])) {
                            $changes_made['job_source'] = @JobSource::find($changes_made['job_source_id'])->name;
                        }
                        if (isset($changes_made['job_category_id'])) {
                            $changes_made['job_category'] = @JobCategory::find($changes_made['job_category_id'])->name;
                        }
                        if (isset($changes_made['job_title_id'])) {
                            $changes_made['job_title'] = @JobTitle::find($changes_made['job_title_id'])->name;
                        }
                        if (isset($changes_made['updated_at'])) {
                            $changes_made['updated_at'] = @Carbon::parse($changes_made['updated_at'])->format('d M Y, h:i A');
                        }
                        if (isset($changes_made['created_at'])) {
                            $changes_made['created_at'] = @Carbon::parse($changes_made['created_at'])->format('d M Y, h:i A');
                        }
                        if (isset($changes_made['is_crm_interview_attended'])) {
                            switch ($changes_made['is_crm_interview_attended']) {
                                case '0':
                                    $interview_attended_string = "No";
                                    break;
                                case '1':
                                    $interview_attended_string = "Yes";
                                    break;
                                case '2':
                                    $interview_attended_string = "Pending";
                                    break;
                            }
                            $changes_made['is_crm_interview_attended'] = $interview_attended_string;
                        }
                        if (isset($changes_made['status'])) {
                            switch ($changes_made['status']) {
                                case '0':
                                    $status_string = "Inactive";
                                    break;
                                case '1':
                                    $status_string = "Active";
                                    break;
                            }
                            $changes_made['status'] = $status_string;
                        }

                        $audit_data[$index]['changes_made'] = $changes_made;
                        $audit_data[$index]['changes_made_by'] = $audit->user->name;
                        $audit_data[$index]['date'] = $audit->created_at->format('d M Y, h:i A'); // ✅ ADDED
                        $index++;
                    }
                }

                $audit_data = array_reverse($audit_data);
            }

            return response()->json([
                'data' => $model,
                'audit_history' => $audit_data,
                'success' => true
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    /** Whether the user holds any permission whose name ends with $suffix (e.g. "-add-note"). */
    private function hasPermissionLike(string $suffix): bool
    {
        $user = Auth::user();

        return $user !== null && $user->getAllPermissions()->contains(fn ($p) => str_ends_with($p->name, $suffix));
    }

}
