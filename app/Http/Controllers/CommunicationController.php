<?php

namespace App\Http\Controllers;

use App\Services\Xplogent\VacancyEmailService;
use Illuminate\Http\Request;
use Horsefly\Sale;
use Horsefly\Unit;
use Horsefly\Message;
use Horsefly\EmailTemplate;
use Horsefly\Applicant;
use App\Http\Controllers\Controller;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Exception;
use Carbon\Carbon;
use Horsefly\JobCategory;
use Horsefly\User;
use Horsefly\SentEmail;
use App\Traits\SendEmails;
use App\Traits\SendSMS;
use Horsefly\Contact;
use Illuminate\Support\Str;

class CommunicationController extends Controller
{
    use SendEmails, SendSMS;

    public function __construct()
    {
        $this->middleware('permission:email-index')->only(['index', 'sendEmailsToApplicants', 'saveEmailsForApplicants', 'saveComposedEmail']);
        $this->middleware('permission:sent-email-index')->only(['sentEmailsIndex', 'getSentEmailsAjaxRequest']);
        $this->middleware('permission:message-index')->only([
            'Messagesindex',
            'writeMessageindex',
            'sendMessageToApplicant',
            'sendChatBoxMsg',
            'getChatBoxMessages',
            'getApplicantsForMessage',
            'getUnknownMessage',
            'getUserChats',
        ]);
    }

    public function index()
    {
        return view('emails.compose-email');
    }
    public function Messagesindex()
    {
        return view('messages.index');
    }
    public function sentEmailsIndex()
    {
        return view('emails.sent-emails');
    }
    public function writeMessageindex()
    {
        return view('messages.write-message');
    }
    public function sendEmailsToApplicants(Request $request)
    {
        $radius = 15; //kilometers
        $id = $request->sale_id;
        $sale = Sale::find($id);
        $unit = Unit::where('office_id', $sale->office_id)->first();
        $JobCategory = JobCategory::where('id', $sale->job_category_id)->first();
        $job_category = $sale->job_category_id;
        $job_postcode = $sale->sale_postcode;
        $job_title = $sale->job_title_id;

        $nearby_applicants = $this->distance($sale->lat, $sale->lng, $radius, $job_title, $job_category);
        $emails = is_null($nearby_applicants) ? '' : implode(',', $nearby_applicants->toArray());

        $user_name = Auth::user()->name;

        // Template filling shared with the Xplogent API (App\Services\Xplogent\VacancyEmailService).
        $formattedMessage = '';
        $subject = '';
        if ($filled = app(VacancyEmailService::class)->fill($sale, $user_name)) {
            $formattedMessage = $filled['body'];
            $subject = $filled['subject'];
        }

        return view('emails.send-email-to-applicant', compact('sale', 'unit', 'subject', 'formattedMessage', 'emails'));
    }
    function distance($lat, $lon, $radius, $job_title_id, $job_category_id)
    {
        $location_distance = Applicant::with('cv_notes')
            ->select(DB::raw("
                id,
                lat,
                lng,
                applicant_email,
                applicant_email_secondary,
                ((ACOS(SIN($lat * PI() / 180) * SIN(lat * PI() / 180) + 
                COS($lat * PI() / 180) * COS(lat * PI() / 180) * COS(($lon - lng) * PI() / 180)) * 180 / PI()) * 60 * 1.852) AS distance
            "))
            ->having("distance", "<", $radius)
            ->orderBy("distance")
            ->where('applicants.status', 1)
            ->where('applicants.is_in_nurse_home', false)
            ->where('applicants.is_blocked', false)
            ->where('applicants.is_callback_enable', false)
            ->where('applicants.is_no_job', false)
            // Grouped: an ungrouped orWhere here cancelled the status / blocked /
            // no-job / callback filters above, so blocked applicants were emailed.
            ->where(function ($q) use ($job_category_id, $job_title_id) {
                $q->where("job_category_id", $job_category_id)
                    ->orWhere("job_title_id", $job_title_id);
            })
            ->whereNotNull('applicant_email')
            ->get();

        if ($location_distance->isEmpty()) {
            return null;
        }

        $validDomains = ['.com', '.msn', '.net', '.uk', '.gr'];

        $validEmailAddresses = $location_distance->flatMap(function ($applicant) use ($validDomains) {
            return collect([
                $applicant->applicant_email,
                $applicant->applicant_email_secondary
            ])
                ->filter() // Remove nulls
                ->unique() // Remove duplicates
                ->filter(function ($email) use ($validDomains) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
                    if (preg_match('/^[A-Za-z0-9._%+-]+@example\.com$/', $email)) return false;
                    if (strpos($email, '@') === false) return false;

                    foreach ($validDomains as $domain) {
                        if (str_ends_with($email, $domain)) {
                            return true;
                        }
                    }

                    return false;
                });
        })->unique()->values();

        return $validEmailAddresses;
    }
    public function getSentEmailsAjaxRequest(Request $request)
    {
        $searchTerm = $request->input('search', ''); // This will get the search query

        $model = SentEmail::query();

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sent_emails.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sent_emails.updated_at', 'desc');
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    // Direct column searches
                    $query->where('sent_emails.sent_to', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.sent_from', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.title', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.cc_emails', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.subject', 'LIKE', "%{$searchTerm}%");
                });
            }
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('updated_at', function ($email) {
                    return Carbon::parse($email->updated_at)->format('d M Y, h:iA');
                })
                ->addColumn('action', function ($email) {
                    $sent_to = addslashes(htmlspecialchars($email->sent_to ?? ''));
                    $sent_from = addslashes(htmlspecialchars($email->sent_from ?? ''));
                    $title = addslashes(htmlspecialchars($email->title ?? ''));
                    $cc_email = addslashes(htmlspecialchars($email->cc_email ?? ''));
                    $subject = addslashes(htmlspecialchars($email->subject ?? ''));

                    // Escape template safely (replace newlines and quotes)
                    $template = str_replace(["\r", "\n", "'"], [' ', ' ', "\\'"], $email->template ?? '');
                    $template = htmlspecialchars($template, ENT_QUOTES); // prevents XSS

                    return '<div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="showDetailsModal(
                                ' . $email->id . ',
                                \'' . $sent_to . '\',
                                \'' . $sent_from . '\',
                                \'' . $title . '\',
                                \'' . $cc_email . '\',
                                \'' . $subject . '\',
                                \'' . $template . '\'
                            )">
                            <i class=\"fas fa-eye\"></i> View
                        </button>
                    </div>';
                })

                ->rawColumns(['action', 'updated_at'])
                ->make(true);
        }
    }
    public function sendMessageToApplicant(Request $request)
    {
        try {
            $request->validate([
                'phone_number' => 'required',
                'message' => 'required',
            ]);

            $phone_numbers = array_map('trim', explode(',', $request->input('phone_number')));
            $message = $request->input('message');
            $applicant_id = $request->input('applicant_id');

            foreach ($phone_numbers as $phone) {
                $applicant = Applicant::where('applicant_phone', 'like', '%' . $phone . '%')
                    ->orWhere('applicant_phone_secondary', 'like', '%' . $phone . '%')
                    ->orWhere('applicant_landline', 'like', '%' . $phone . '%')
                    ->first();

                if ($applicant) {
                    $is_saved = $this->saveSMSDB($phone, $message, 'Horsefly\Applicant', $applicant->id);
                } else {
                    $contact = Contact::where('contact_phone', $phone)->first();
                    if ($contact) {
                        $is_saved = $this->saveSMSDB($phone, $message, $contact->contactable_type, $contact->contactable_id);
                    } else {
                        $is_saved = $this->saveSMSDB($phone, $message, 'unknown', null);
                    }
                }

                if (!$is_saved) {
                    return response()->json([
                        'success' => false,
                        'message' => 'SMS saving failed.',
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'SMS sent and stored successfully.',
            ]);
        } catch (ValidationException $ve) {
            // Validation errors will be caught separately
            return response()->json([
                'error' => $ve->validator->errors()->first(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    // public function sendMessageToApplicant(Request $request)
    // {
    //     return $request->all();
    //     try {
    //         $phone_number = $request->input('phone_number');
    //         $message = $request->input('message');

    //         if (!$phone_number || !$message) {
    //             return response()->json([
    //                 'error' => 'Phone number and message are required.'
    //             ], 400);
    //         }

    //         // Encode message to be safely used in a URL
    //         $encoded_message = urlencode($message);

    //         $url = 'http://milkyway.tranzcript.com:1008/sendsms?username=admin&password=admin&phonenumber='
    //             . $phone_number . '&message=' . $encoded_message . '&port=1&report=JSON&timeout=0';

    //         $curl = curl_init();
    //         curl_setopt_array($curl, [
    //             CURLOPT_URL => $url,
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_HEADER => false,
    //             CURLOPT_TIMEOUT => 10,
    //         ]);

    //         $response = curl_exec($curl);
    //         $curlError = curl_error($curl);
    //         curl_close($curl);

    //         if ($response === false) {
    //             return response()->json([
    //                 'error' => 'Failed to connect to SMS API: ' . $curlError,
    //                 'query_string' => $url
    //             ], 500);
    //         }

    //         // Try to parse JSON response
    //         $parsed = json_decode($response, true);
    //         if (json_last_error() === JSON_ERROR_NONE) {
    //             $report = $parsed['result'] ?? null;
    //             $time = $parsed['time'] ?? null;
    //             $phone = $parsed['phonenumber'] ?? null;
    //         } else {
    //             // Fallback (non-JSON API response)
    //             $report = explode('"', strstr($response, "result"))[2] ?? null;
    //             $time = explode('"', strstr($response, "time"))[2] ?? null;
    //             $phone = explode('"', strstr($response, "phonenumber"))[2] ?? null;
    //         }

    //         if ($report === "success") {
    //             return response()->json([
    //                 'success' => 'SMS sent successfully!',
    //                 'data' => $response,
    //                 'phonenumber' => $phone,
    //                 'time' => $time,
    //                 'report' => $report
    //             ]);
    //         } elseif ($report === "sending") {
    //             return response()->json([
    //                 'success' => 'SMS is sending, please check later!',
    //                 'data' => $response,
    //                 'phonenumber' => $phone,
    //                 'time' => $time,
    //                 'report' => $report
    //             ]);
    //         } else {
    //             return response()->json([
    //                 'error' => 'SMS failed, please check your device or settings!',
    //                 'data' => $response,
    //                 'report' => $report,
    //                 'query_string' => $url
    //             ]);
    //         }

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => 'An unexpected error occurred: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function sendRejectionEmail(Request $request)
    {
        try {
            return response()->json(['message' => 'Rejection email sent successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }
    public function saveEmailsForApplicants(Request $request)
    {
        try {
            $sale_id = $request->input('sale_id', null);

            $emailData = $request->input('app_email');
            $template = EmailTemplate::where('slug', 'send_job_vacancy_details')->where('is_active', 1)->first();

            if ($emailData != null && $template) {
                $dataEmail = explode(',', $emailData);

                $email_from = $template->from_email;
                $email_subject = $request->input('email_subject');
                $email_body = $request->input('email_body');
                $email_title = $template->title;

                foreach ($dataEmail as $email) {
                    $applicant = Applicant::where('applicant_email', $email)->orWhere('applicant_email_secondary', $email)->first();
                    $is_save = $this->saveEmailDB($email, $email_from, $email_subject, $email_body, $email_title, $applicant->id, $sale_id);
                    if (!$is_save) {
                        // Optional: throw or log
                        Log::warning('Email saved to DB failed for applicant ID: ' . $applicant->id);
                        throw new \Exception('Email is not stored in DB');
                    }
                }
            }
            return response()->json(['success' => true, 'message' => 'Email saved successfully']);
        } catch (\Exception $e) {
            return  response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }
    public function saveComposedEmail(Request $request)
    {
        try {
            $emailData = $request->input('app_email');
            $from_email = $request->input('from_email') ?? 'info@kingsburypersonnel.com';

            if ($emailData != null) {
                $dataEmail = explode(',', $emailData);

                $email_from = $from_email;
                $email_subject = $request->input('email_subject');
                $email_body = $request->input('email_body');
                $email_title = $request->input('email_subject');

                foreach ($dataEmail as $email) {
                    $applicant = Applicant::where('applicant_email', $email)->orWhere('applicant_email_secondary', $email)->first();
                    if ($applicant) {
                        $is_save = $this->saveEmailDB($email, $email_from, $email_subject, $email_body, $email_title, $applicant->id);
                    } else {
                        $is_save = $this->saveEmailDB($email, $email_from, $email_subject, $email_body, $email_title, null);
                    }
                    if (!$is_save) {
                        // Optional: throw or log
                        Log::warning('Email saved to DB failed');
                        throw new \Exception('Email is not stored in DB');
                    } else {
                        return response()->json(['success' => true, 'message' => 'Email saved successfully']);
                    }
                }
            }
        } catch (\Exception $e) {
            return  response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /*************************************** */
    public function messageReceive(Request $request)
    {
        Log::info('Received message: ' . json_encode($request)); // Log the incoming request for debugging
        try {
            $phoneNumber_gsm = $request->input('phoneNumber');
            $phoneNumber = preg_replace('/^(\+44|44)/', '0', $phoneNumber_gsm);
            $message = $request->input('message');
            $msg_id = substr(md5(time()), 0, 14);
            $date_time = $request->input('time');
            $date_time_arr = explode(" ", $date_time);
            $date_res = $date_time_arr[0];
            $date = str_replace("/", "-", $date_res);
            $time = $date_time_arr[1];

            $data = [];

            $lastMessage = Message::where('phone_number', $phoneNumber)
                ->where('status', 'outgoing')
                ->latest()
                ->first();

            $applicant = Applicant::where('applicant_phone', $phoneNumber)
                ->orWhere('applicant_landline', $phoneNumber)
                ->orWhere('applicant_phone_secondary', $phoneNumber)
                ->first();

            $contact = Contact::where('contact_phone', $phoneNumber)->first();

            if ($applicant) {
                $data['module_id'] = $applicant->id;
                $data['module_type'] = 'Horsefly\Applicant';
            } elseif ($contact) {
                $data['module_id'] = $contact->contactable_id;
                $data['module_type'] = $contact->contactable_type;
            } else {
                $data['module_id'] = null;
                $data['module_type'] = 'unknown';
            }

            if ($data) {
                $applicant_msg = new Message();
                $applicant_msg->module_id = $data['module_id'];
                $applicant_msg->module_type = $data['module_type'];
                $applicant_msg->user_id = $lastMessage ? $lastMessage->user_id : null;
                $applicant_msg->msg_id = $msg_id;
                $applicant_msg->message = $message;
                $applicant_msg->phone_number = $phoneNumber;
                $applicant_msg->date = $date;
                $applicant_msg->time = $time;
                $applicant_msg->created_at = Carbon::parse($date . '' . $time)->toDateTimeString();
                $applicant_msg->updated_at = Carbon::parse($date . '' . $time)->toDateTimeString();
                $applicant_msg->status = 'incoming';
                $applicant_msg->save();

                return response()->json(['message' => 'Message received and saved successfully.']);
            } else {
                return response()->json(['message' => 'Phone number not found in Applicant'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to save message: ' . $e->getMessage()], 500);
        }
    }
    public function sendChatBoxMsg(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required',
            'recipient_type' => 'required',
            'recipient_phone' => 'required|string|max:50',
            'message' => 'required|string',
        ]);

        if ($request->recipient_type == 'user') {
            $recipient_type = 'Horsefly\User';
        } else {
            $recipient_type = 'Horsefly\Applicant';
        }

        $message = new Message();
        $message->module_id = (int) $request->recipient_id;
        $message->module_type = $recipient_type;
        $message->user_id = Auth::id();
        $message->msg_id = 'D' . mt_rand(1000000000000, 9999999999999);
        $message->message = $request->message;
        $message->phone_number = trim($request->recipient_phone);
        $message->date = now()->toDateString();
        $message->time = now()->toTimeString();
        $message->is_sent = 0;
        $message->is_read = 0;
        $message->status = 'outgoing';
        $message->save();

        return response()->json([
            'id' => $message->id,
            'msg_id' => $message->msg_id,
            'user_id' => $message->user_id,
            'module_type' => $message->user_id,
            'module_id' => $message->module_id,
            'user_name' => Auth::user()->name,
            'message' => $message->message,
            'phone_number' => $message->phone_number,
            'date' => $message->date,
            'time' => $message->time,
            'status' => $message->status,
            'is_sent' => $message->is_sent,
            'is_read' => $message->is_read,
            'is_sender' => true,
            'created_at' => date('d M Y, H:i A', strtotime($message->date . ' ' . $message->time)),
        ]);
    }
    public function getChatBoxMessages(Request $request)
    {
        try {
            // Validate request input
            $request->validate([
                'recipient_id'   => 'required',
                'recipient_type' => 'required',
            ]);

            // Retrieve necessary data from the request
            $recipientId   = $request->recipient_id;
            $recipientType = $request->recipient_type;
            $beforeId      = $request->before_id; // 👈 important

            // Determine the model type for recipient (either Applicant or User)
            $moduleType = ($recipientType === 'applicant' || $recipientType === 'unknown') ? Applicant::class : User::class;
            $list_ref = $request->input('list_ref', '');

            // Initialize recipient data (empty by default)
            $recipient = null;

            // Handle the 'unknown-chat' case differently
            if ($list_ref === 'unknown-chat') {
                // `recipientId` here is always an exact phone number (from data-recipient-id
                // in the sidebar list), so a `LIKE '%...%'` match was both unnecessarily slow
                // (forces a full table scan - can't use an index with leading/trailing
                // wildcards) and risked matching unrelated numbers containing the same digits.
                Message::where('phone_number', $recipientId)
                    ->where('module_type', $moduleType)
                    ->where('status', 'incoming')
                    ->update(['is_read' => 1]);  // Ensure it's an integer value (0 or 1)

                // Special handling for unknown-chat (i.e., by phone number)
                $unknownQuery = Message::where('phone_number', $recipientId)
                    ->where('module_type', $moduleType)
                    ->orderByDesc('id')
                    ->limit(10); // chunk size

                // Load older messages (before a specific message ID) - previously missing
                // for unknown-chat, so scrolling up never loaded older messages here.
                if ($beforeId) {
                    $unknownQuery->where('id', '<', $beforeId);
                }

                $messages = $unknownQuery->get(); // Fetch messages

                // Assign recipient data in the case of 'unknown-chat'
                $recipient = [
                    'id'              => $messages->isEmpty() ? null : $messages->first()->id,  // Get the ID of the first message or null if no messages
                    'applicant_name'  => 'Unknown Number',
                    'applicant_phone' => $recipientId,
                ];
            } else {
                // Fetch the recipient details when the list_ref is not 'unknown-chat'
                $recipient = $moduleType::findOrFail($recipientId);

                Message::where('module_id', $recipientId)
                    ->where('module_type', ($recipientType === 'applicant' || $recipientType === 'unknown') ? Applicant::class : User::class)
                    ->where('status', 'incoming')
                    ->update(['is_read' => 1]);  // Ensure it's an integer value (0 or 1)


                // Base query for fetching messages for both normal and 'unknown-chat' cases
                $query = Message::where('module_id', $recipientId)
                    ->where('module_type', $moduleType)
                    ->with('user')
                    ->orderByDesc('id')
                    ->limit(10); // chunk size

                // Filter by authenticated user if the list_ref is 'user-chat'
                if ($list_ref === 'user-chat') {
                    $query->where('user_id', Auth::id());
                }

                // Load older messages (before a specific message ID)
                if ($beforeId) {
                    $query->where('id', '<', $beforeId);
                }

                // Fetch the messages
                $messages = $query->get();
            }

            // Reverse the messages for UI (oldest → newest)
            $formattedMessages = $messages->reverse()->values()->map(function ($message) {
                return [
                    'id'           => $message->id,
                    'message'      => $message->message,
                    'created_at'   => $message->created_at->format('d M Y, h:i A'),
                    'is_sender'    => $message->user_id == Auth::id(),
                    'user_name'    => $message->user?->name ?? 'Unknown',
                    'is_read'      => $message->is_read ?? 0,
                    'is_sent'      => $message->is_sent ?? 0,
                    'phone_number' => $message->phone_number,
                    'status'       => $message->status === 'outgoing' ? 'Sent' : 'Received',
                ];
            });

            // Determine recipient's status (active or not)
            $recipientStatus = $messages->isEmpty() ? false : true;

            // Prepare the response
            return response()->json([
                'recipient' => [
                    'id'    => $recipient['id'] ?? $recipient->id,
                    'name'  => $recipient['applicant_name'] ?? $recipient->applicant_name ?? 'Unknown',
                    'phone_primary' => $recipient['applicant_phone'] ?? $recipient->applicant_phone ?? '0',
                    'phone_secondary' => $recipient['applicant_phone_secondary'] ?? $recipient->applicant_phone_secondary ?? '0',
                    'status' => $recipientStatus ? 'active' : 'inactive',
                ],
                'messages' => $formattedMessages,
                'has_more' => $messages->count() == 10,
            ]);
        } catch (\Exception $e) {
            // Log the error and return a detailed response
            Log::error("Error fetching chat messages: " . $e->getMessage());
            return response()->json(['error' => 'An error occurred while fetching chat messages. Please try again later. => ' . $e->getMessage()], 500);
        }
    }
    public function getApplicantsForMessage(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        $start = (int) $request->input('start', 0);
        $search = trim((string) $request->input('search', ''));

        // Aggregate message stats once (grouped by applicant) instead of the previous
        // withCount()/withMax() approach, which ran a correlated COUNT/MAX subquery for
        // *every* row of the (large, ~230k-row) applicants table before it could even
        // apply LIMIT/OFFSET. A single GROUP BY over `messages` (a much smaller, indexed
        // table) computed once and left-joined back is dramatically cheaper.
        $stats = DB::table('messages')
            ->where('module_type', 'Horsefly\\Applicant')
            ->groupBy('module_id')
            ->select([
                'module_id',
                DB::raw('MAX(id) as last_message_id'),
                DB::raw('COUNT(*) as messages_count'),
                DB::raw("SUM(CASE WHEN status = 'incoming' AND is_read = 0 THEN 1 ELSE 0 END) as unread_count"),
            ]);

        $query = Applicant::query()
            ->leftJoinSub($stats, 'msg_stats', function ($join) {
                $join->on('applicants.id', '=', 'msg_stats.module_id');
            })
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'msg_stats.last_message_id',
                'msg_stats.messages_count',
                DB::raw('COALESCE(msg_stats.unread_count, 0) as unread_count'),
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('applicants.applicant_name', 'like', '%' . $search . '%')
                    ->orWhere('applicants.applicant_phone', 'like', '%' . $search . '%');
            });
        }

        $applicants = $query
            ->orderByDesc(DB::raw('unread_count > 0'))  // unread first
            ->orderByDesc('unread_count')
            ->orderByDesc('msg_stats.last_message_id')   // latest message first
            ->orderByDesc('msg_stats.messages_count')
            ->orderBy('applicants.applicant_name')
            ->offset($start)
            ->limit($limit)
            ->get();

        // Fetch the actual last-message body/status only for the small page of
        // applicants being displayed here (not the buggy `->limit(1)` eager load,
        // which - being applied to the batched IN() query of a hasMany relation -
        // only ever returned one message total across the whole page, not one per
        // applicant).
        $lastMessageIds = $applicants->pluck('last_message_id')->filter()->values()->all();
        $lastMessagesById = collect();

        if (!empty($lastMessageIds)) {
            $lastMessagesById = DB::table('messages')
                ->whereIn('id', $lastMessageIds)
                ->get(['id', 'message', 'created_at', 'is_sent', 'is_read'])
                ->keyBy('id');
        }

        $data = $applicants->map(function ($applicant) use ($lastMessagesById) {
            $lastMessage = $applicant->last_message_id ? $lastMessagesById->get($applicant->last_message_id) : null;

            return [
                'id'   => $applicant->id,
                'name' => $applicant->applicant_name,
                'last_message' => $lastMessage ? [
                    'message'       => Str::limit($lastMessage->message, 50),
                    'time'          => $lastMessage->created_at
                        ? Carbon::parse($lastMessage->created_at)->format('h:i A')
                        : '',
                    'is_sent'       => (int) ($lastMessage->is_sent ?? 0),
                    'is_read'       => (int) ($lastMessage->is_read ?? 0),
                    'unread_count'  => (int) $applicant->unread_count,
                ] : null,
            ];
        })->values();

        return response()->json([
            'data'     => $data,
            'has_more' => $data->count() == $limit // key logic
        ]);
    }
    public function getUnknownMessage(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        $start = (int) $request->input('start', 0);

        $query = Message::query()
            ->leftJoin('applicants', 'messages.module_id', '=', 'applicants.id')
            ->where('messages.module_type', 'Horsefly\\Applicant')
            ->where(function ($q) {
                $q->whereNull('messages.module_id')
                    ->orWhereNull('applicants.id');
            })
            ->select([
                'messages.phone_number',
                DB::raw('MAX(messages.created_at) as last_message_at'),
                DB::raw('COUNT(*) as messages_count'),
                DB::raw('SUM(
                    CASE 
                        WHEN messages.status = "incoming" 
                        AND messages.is_read = 0 
                        AND (messages.module_id IS NULL OR applicants.id IS NULL)
                        THEN 1 
                        ELSE 0 
                    END
                ) as unread_count')
            ])
            ->groupBy('messages.phone_number');

        if (!empty($request->search)) {
            $query->where('messages.phone_number', 'like', '%' . $request->search . '%');
        }

        $messages = $query
            ->orderByDesc('unread_count')       // unread first
            ->orderByDesc('messages_count')     // then by volume
            ->orderByDesc('last_message_at')    // then latest conversation
            ->offset($start)
            ->limit($limit)
            ->get();

        // Batch-fetch the latest message per phone number for this page in a single
        // query instead of issuing one extra query per row (N+1).
        $phoneNumbers = $messages->pluck('phone_number')->all();
        $lastMessagesByPhone = collect();

        if (!empty($phoneNumbers)) {
            $latestIdsPerPhone = DB::table('messages')
                ->select(DB::raw('MAX(id) as id'))
                ->whereIn('phone_number', $phoneNumbers)
                ->groupBy('phone_number');

            $lastMessagesByPhone = DB::table('messages')
                ->joinSub($latestIdsPerPhone, 'latest', function ($join) {
                    $join->on('messages.id', '=', 'latest.id');
                })
                ->get(['messages.phone_number', 'messages.message', 'messages.created_at', 'messages.is_sent', 'messages.is_read'])
                ->keyBy('phone_number');
        }

        $data = $messages->map(function ($message) use ($lastMessagesByPhone) {
            $lastMessage = $lastMessagesByPhone->get($message->phone_number);

            return [
                'phone_number' => $message->phone_number,
                'messages_count' => $message->messages_count,
                'unread_count' => $message->unread_count, // take from aggregated query
                'name' => 'Unknown Number',
                'last_message' => $lastMessage ? [
                    'message' => Str::limit($lastMessage->message, 50),
                    'time' => $lastMessage->created_at
                        ? Carbon::parse($lastMessage->created_at)->format('h:i A')
                        : '',
                    'is_sent' => (int) ($lastMessage->is_sent ?? 0),
                    'is_read' => (int) ($lastMessage->is_read ?? 0),
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'has_more' => $data->count() == $limit,
        ]);
    }
    public function getUserChats(Request $request)
    {
        try {
            $currentUserId = Auth::id();

            $limit = (int) $request->input('limit', 10);
            $start = (int) $request->input('start', 0);

            // Step 1: Get latest message ID per applicant sent by current user
            $latestMessageIds = DB::table('messages')
                ->select(DB::raw('MAX(id) as id'))
                ->where('user_id', $currentUserId)
                ->where('module_type', 'Horsefly\\Applicant')
                ->groupBy('module_id');

            // Step 2: Join to get full message and applicant
            $unreadCounts = DB::table('messages')
                ->select('module_id', DB::raw('COUNT(*) as unread_count'))
                ->where('is_read', 0)
                ->where('status', 'incoming')
                ->where('module_type', 'Horsefly\\Applicant')
                ->where('user_id', $currentUserId)
                ->groupBy('module_id');

            $raw_query = DB::table('messages')
                ->joinSub($latestMessageIds, 'latest_messages', function ($join) {
                    $join->on('messages.id', '=', 'latest_messages.id');
                })
                ->join('applicants', 'messages.module_id', '=', 'applicants.id')
                ->leftJoinSub($unreadCounts, 'unread_msgs', function ($join) {
                    $join->on('messages.module_id', '=', 'unread_msgs.module_id');
                })
                ->select(
                    'applicants.id',
                    'applicants.applicant_name as name',
                    'messages.message',
                    'messages.created_at',
                    DB::raw('COALESCE(unread_msgs.unread_count, 0) as unread_count')
                );

            if ($request->search) {
                $raw_query->where('applicants.applicant_name', 'like', '%' . $request->search . '%')
                    ->orWhere('applicant_phone', 'like', '%' . $request->search . '%');
            }

            $applicants = $raw_query->orderByDesc('messages.created_at')
                ->offset($start)
                ->limit($limit)
                ->get();

            // Transform the collection to match frontend expectations
            $applicants = $applicants->map(function ($applicant) {
                return [
                    'id' => $applicant->id,
                    'name' => $applicant->name,
                    'last_message' => [
                        'message' => Str::limit($applicant->message, 50),
                        'time' => Carbon::parse($applicant->created_at)->format('h:i A'),
                        'unread_count' => $applicant->unread_count,
                        'applicant_name' => $applicant->name,
                    ],
                ];
            });

            return response()->json([
                'data' => $applicants,
                'has_more' => $applicants->count() == $limit
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching applicants for messages: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch applicants'], 500);
        }
    }
}
