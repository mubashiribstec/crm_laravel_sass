<?php

namespace Tests\Feature\Audit;

use Horsefly\CrmNote;
use Horsefly\CVNote;
use Horsefly\JobTitle;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * Batch 3 of the 3 Nov audit (docs/audit/2026-11-03-audit-review.md): the other CRM P1s.
 * Reuses the Xplogent test base for its fixtures (sale, applicant, staff with permissions).
 */
class Batch3Test extends XplogentTestCase
{
    // ── B-02: CRM reverts with nothing to revert to ──────────────────────────
    public function test_a_crm_revert_with_nothing_to_revert_to_answers_409_and_changes_nothing(): void
    {
        $sale = $this->sale();
        $a = $this->applicant();
        $cv = CVNote::create(['applicant_id' => $a->id, 'sale_id' => $sale->id, 'user_id' => $this->ai->id,
            'status' => 0, 'details' => 'x']);
        $this->actingAs($this->staff('Jane Recruiter', 'crm-index'))->postJson('/crmRevertRequestRejectToRequest',
            ['applicant_id' => $a->id, 'sale_id' => $sale->id, 'details' => 'revert please'])
            ->assertStatus(409)->assertJson(['success' => false])
            ->assertJsonPath('message', "There is no 'CV sent' note to revert to for this applicant and sale.");
        $this->assertSame(0, (int) $cv->fresh()->status);          // the earlier write was rolled back
        $this->assertSame(0, CrmNote::count());
    }

    // ── B-03 / B-04: send CV to quality ──────────────────────────────────────
    public function test_send_cv_to_quality_says_why_it_refuses_even_without_debug(): void
    {
        config(['app.debug' => false]);
        $staff = $this->staff('Jane Recruiter', 'applicant-view');
        $closed = $this->sale(['status' => 0]);
        $this->actingAs($staff)->postJson('/sendCVtoQuality', ['applicant_id' => $this->applicant()->id,
            'sale_id' => $closed->id, 'details' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', "CV can't be sent - the sale is not active.");

        // B-03: a sale whose job title is gone (the FK prevents it here; a DB without it doesn't)
        $orphan = $this->sale(['job_title_id' => JobTitle::factory()->create(['job_category_id' => $this->category->id])->id]);
        Schema::disableForeignKeyConstraints();
        JobTitle::whereKey($orphan->job_title_id)->delete();
        Schema::enableForeignKeyConstraints();
        $this->actingAs($staff)->postJson('/sendCVtoQuality', ['applicant_id' => $this->applicant()->id,
            'sale_id' => $orphan->id, 'details' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', "CV can't be sent - the vacancy's job title no longer exists.");
    }

    public function test_send_cv_to_quality_at_the_cv_limit_rolls_back_what_it_wrote(): void
    {
        $sale = $this->sale(['cv_limit' => 1]);
        CVNote::create(['applicant_id' => $this->applicant()->id, 'sale_id' => $sale->id, 'user_id' => $this->ai->id,
            'status' => 1, 'details' => 'x']);
        $a = $this->applicant();
        $this->actingAs($this->staff('Jane Recruiter', 'applicant-view'))->postJson('/sendCVtoQuality',
            ['applicant_id' => $a->id, 'sale_id' => $sale->id, 'details' => 'no job now', 'no_job' => true])
            ->assertOk()->assertJson(['success' => false]);
        $this->assertFalse((bool) $a->fresh()->is_no_job);          // the "no job" write was undone (B-04)
        $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel() - 1);   // nothing left open
    }
}
