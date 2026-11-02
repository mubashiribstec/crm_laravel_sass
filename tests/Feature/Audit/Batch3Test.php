<?php

namespace Tests\Feature\Audit;

use Horsefly\CrmNote;
use Horsefly\CVNote;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\Office;
use Horsefly\Sale;
use Horsefly\Unit;
use Illuminate\Support\Facades\DB;
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

    // ── B-05 / L-05: creating a sale ─────────────────────────────────────────
    private function salePayload(array $overrides = []): array
    {
        $office = Office::factory()->create(['office_name' => 'Birch Care']);
        $unit = Unit::factory()->create(['office_id' => $office->id, 'unit_name' => 'Birch House']);

        return array_merge(['office_id' => $office->id, 'unit_id' => $unit->id, 'sale_postcode' => 'LS6 2AB',
            'job_category_id' => $this->category->id, 'job_title_id' => $this->title->id,
            'job_source_id' => JobSource::factory()->create()->id, 'job_type' => 'regular',
            'position_type' => 'full time', 'cv_limit' => 8, 'timing' => 'Days', 'experience' => '1 year',
            'salary' => '£12.60', 'benefits' => 'Pension', 'qualification' => 'NVQ 2', 'sale_notes' => 'New vacancy'],
            $overrides);
    }

    public function test_a_known_uppercase_postcode_is_found_without_a_geocoding_call(): void
    {
        DB::table('postcodes')->insert(['postcode' => 'LS6 2AB', 'lat' => 53.8211, 'lng' => -1.5794]);
        // No Google key is configured here, so before the fix (no match) this failed with "Geolocation failed".
        $this->actingAs($this->staff('Jane Recruiter', 'sale-create'))->postJson('/sales/store', $this->salePayload())
            ->assertOk()->assertJson(['success' => true]);
        $sale = Sale::latest('id')->first();
        $this->assertEqualsWithDelta(53.8211, (float) $sale->lat, 0.001);
    }

    public function test_a_sale_needs_a_whole_positive_cv_limit_and_real_ids(): void
    {
        $staff = $this->staff('Jane Recruiter', 'sale-create');
        foreach ([['cv_limit' => 0], ['cv_limit' => 'abc'], ['cv_limit' => 500]] as $bad) {
            $this->actingAs($staff)->postJson('/sales/store', $this->salePayload($bad))
                ->assertStatus(422)->assertJsonPath('errors.cv_limit.0', fn ($m) => is_string($m));
        }
        $this->actingAs($staff)->postJson('/sales/store', $this->salePayload(['job_title_id' => 999999]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['job_title_id']]);
    }
}
