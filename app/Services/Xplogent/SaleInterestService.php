<?php

namespace App\Services\Xplogent;

use Horsefly\Applicant;
use Horsefly\ApplicantPivotSale;
use Horsefly\ModuleNote;
use Horsefly\NotesForRangeApplicant;
use Horsefly\User;

/**
 * Interest in ONE vacancy. In this CRM an applicants_pivot_sales row means
 * "not interested in this sale" (its reason is in notes_for_range_applicants);
 * marking the applicant interested again deletes that row. Extracted from
 * ResourceController::markApplicantNotInterestedOnSale and
 * ::revertNotInterestedApplicant, which now call this, so the recruiter screens
 * and the Xplogent API record interest the same way.
 *
 * The caller owns the DB transaction.
 */
class SaleInterestService
{
    public const INTERESTED_NOTE = 'Applicant marked as interested on sale';

    /** "Not interested in this sale": a pivot row, its range note and a module note. */
    public function markNotInterested(int $applicantId, int $saleId, string $details, User $by): ApplicantPivotSale
    {
        $notes = $details . ' --- By: ' . $by->name . ' Date: ' . now()->format('d-m-Y');

        $pivotSale = ApplicantPivotSale::create([
            'applicant_id' => $applicantId,
            'sale_id' => $saleId,
        ]);
        $pivotSale->update(['pivot_uid' => md5($pivotSale->id)]);

        $notesForRange = NotesForRangeApplicant::create([
            'applicants_pivot_sales_id' => $pivotSale->id,
            'reason' => $notes,
        ]);
        $notesForRange->update(['range_uid' => md5($notesForRange->id)]);

        $this->newModuleNote($applicantId, $notes, $by);

        return $pivotSale;
    }

    /**
     * Interested again in this sale: remove the "not interested" pivot row (and its
     * range notes); with no pivot rows left, clear the temporary not-interested flag.
     * Returns false when there was nothing to revert.
     */
    public function revertNotInterested(int $applicantId, int $saleId, User $by): bool
    {
        $notes = self::INTERESTED_NOTE . ' --- By: ' . $by->name . ' Date: ' . now()->format('d-m-Y');

        $pivotSale = ApplicantPivotSale::where(['applicant_id' => $applicantId, 'sale_id' => $saleId])->first();
        if ($pivotSale) {
            $pivotSale->update(['is_interested' => true]);
            NotesForRangeApplicant::where('applicants_pivot_sales_id', $pivotSale->id)->delete();
            $pivotSale->delete();
        }

        if (!ApplicantPivotSale::where('applicant_id', $applicantId)->exists()) {
            Applicant::where('id', $applicantId)->update(['is_temp_not_interested' => false]);
        }

        $this->newModuleNote($applicantId, $notes, $by);

        return (bool) $pivotSale;
    }

    public function isNotInterestedIn(int $applicantId, int $saleId): bool
    {
        return ApplicantPivotSale::where(['applicant_id' => $applicantId, 'sale_id' => $saleId])->exists();
    }

    private function newModuleNote(int $applicantId, string $notes, User $by): void
    {
        ModuleNote::where([
            'module_noteable_id' => $applicantId,
            'module_noteable_type' => 'Horsefly\Applicant',
            'status' => 1,
        ])->update(['status' => 0]);

        $moduleNote = ModuleNote::create([
            'details' => $notes,
            'user_id' => $by->id,
            'module_noteable_id' => $applicantId,
            'module_noteable_type' => 'Horsefly\Applicant',
            'status' => 1,
        ]);
        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);
    }
}
