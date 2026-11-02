<?php

namespace App\Services\Xplogent;

use App\Observers\ActionObserver;
use Horsefly\Applicant;
use Horsefly\ApplicantNote;
use Horsefly\ModuleNote;
use Horsefly\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * How a call outcome ("reason") is recorded on an applicant: the flags, the
 * applicant_notes column, an ApplicantNote with moved_tab_to, the module note
 * and the audit. Extracted unchanged from ApplicantController::storeShortNotes,
 * which now calls this, so the recruiter screen and the Xplogent API can never
 * drift apart.
 *
 * The caller owns the DB transaction.
 */
class ApplicantStatusService
{
    /** reason => [flag updates, moved_tab_to]; an unknown reason writes the note only. */
    public const REASONS = [
        'blocked' => [[
            'is_no_response' => false, 'is_blocked' => true, 'is_callback_enable' => false,
            'is_temp_not_interested' => false,
        ], 'blocked'],
        'casual' => [[
            'is_no_response' => false, 'is_blocked' => false, 'is_callback_enable' => false,
            'is_temp_not_interested' => false,
        ], 'casual'],
        'no_response' => [[
            'is_circuit_busy' => false, 'is_no_response' => true, 'is_callback_enable' => false,
            'is_blocked' => false, 'is_temp_not_interested' => false,
        ], 'no response'],
        'no_job' => [[
            'is_no_response' => false, 'is_callback_enable' => false, 'is_blocked' => false,
            'is_no_job' => true, 'is_temp_not_interested' => false,
        ], 'no job'],
        'circuit_busy' => [[
            'is_temp_not_interested' => false, 'is_callback_enable' => false, 'is_no_response' => false,
            'is_circuit_busy' => true, 'is_blocked' => false, 'is_no_job' => false,
        ], 'circuit busy'],
        'not_interested' => [[
            'is_temp_not_interested' => true, 'is_no_response' => false, 'is_callback_enable' => false,
            'is_circuit_busy' => false, 'is_blocked' => false, 'is_no_job' => false,
        ], 'not interested'],
        'callback' => [[
            'is_temp_not_interested' => false, 'is_callback_enable' => true, 'is_no_response' => false,
            'is_circuit_busy' => false, 'is_blocked' => false, 'is_no_job' => false,
        ], 'callback'],
    ];

    /**
     * Record $details for the applicant under $reason, as $by. Returns the new ApplicantNote.
     */
    public function apply(int $applicantId, string $reason, string $details, User $by): ApplicantNote
    {
        $applicantNotes = $details . ' --- By: ' . $by->name . ' Date: ' . now()->format('d-m-Y');
        $movedTabTo = '';

        if (isset(self::REASONS[$reason])) {
            [$flags, $movedTabTo] = self::REASONS[$reason];
            Applicant::where('id', $applicantId)->update(array_merge(['applicant_notes' => $applicantNotes], $flags));
        }

        $applicantNote = ApplicantNote::create([
            'details' => $applicantNotes,
            'applicant_id' => $applicantId,
            'moved_tab_to' => $movedTabTo,
            'user_id' => $by->id,
        ]);
        $applicantNote->update(['note_uid' => md5($applicantNote->id)]);

        // Disable previous module notes, then add the new one.
        ModuleNote::where([
            'module_noteable_id' => $applicantId,
            'module_noteable_type' => 'Horsefly\Applicant',
        ])->where('status', 1)->update(['status' => 0]);

        $moduleNote = ModuleNote::create([
            'details' => $applicantNotes,
            'module_noteable_id' => $applicantId,
            'module_noteable_type' => 'Horsefly\Applicant',
            'user_id' => $by->id,
        ]);
        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        // Audit, attributed to $by (the observer reads the authenticated user).
        $applicant = Applicant::where('id', $applicantId)
            ->select('applicant_name', 'applicant_notes', 'id')
            ->first();
        Log::info('Updated request for applicant', $applicant->toArray());

        $previous = Auth::user();
        if (!$previous || $previous->id !== $by->id) {
            Auth::setUser($by);
        }
        try {
            (new ActionObserver())->customApplicantAudit($applicant, 'applicant_notes');
        } finally {
            if ($previous && $previous->id !== $by->id) {
                Auth::setUser($previous);
            }
        }

        return $applicantNote;
    }
}
