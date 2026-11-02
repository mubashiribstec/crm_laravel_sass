<?php

namespace Tests\Feature\Xplogent;

use App\Services\Xplogent\CandidateQueueService;
use Horsefly\ApplicantPivotSale;
use Horsefly\CallLog;
use Horsefly\CVNote;
use Horsefly\DialLock;
use Horsefly\JobTitle;

class XplogentQueueTest extends XplogentTestCase
{
    public function test_open_sales_list_and_search(): void
    {
        $sale = $this->sale();
        $this->sale(['status' => 0]);                       // closed: not listed
        $this->sale(['is_on_hold' => 1]);                   // on hold: not listed
        $r = $this->signed('/api/xplogent/sales/open', ['search' => 'care LS6'])->assertOk();
        $this->assertSame([$sale->id], array_column($r->json('sales'), 'sale_id'));
        $this->assertSame('LS6', $r->json('sales.0.postcode_district'));
        $this->assertSame('Oak Care Group', $r->json('sales.0.office_name'));
    }

    public function test_queue_returns_nearby_matching_candidates_without_personal_data(): void
    {
        $sale = $this->sale();
        $near = $this->applicant(['applicant_name' => 'sarah jones']);
        $this->applicant(['lat' => 51.5, 'lng' => -0.12]);                         // London: outside 15 km
        $other = JobTitle::factory()->create(['name' => 'chef', 'job_category_id' => $this->category->id]);
        $this->applicant(['job_title_id' => $other->id]);                            // different title
        $r = $this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $sale->id, 'radius_km' => 15])->assertOk();

        $this->assertSame([$near->id], array_column($r->json('candidates'), 'applicant_id'));
        $c = $r->json('candidates.0');
        $this->assertSame('Sarah', $c['first_name']);
        $this->assertStringStartsWith('+447700900', $c['phone_e164']);
        $this->assertSame('mobile', $c['phone_kind']);
        $this->assertEqualsWithDelta(1.0, $c['distance_km'], 0.2);
        $this->assertSame('LS7', $c['postcode_district']);
        $this->assertNull($r->json('sale.client_name'));                            // sharing off
        $json = $r->getContent();
        foreach (['applicant_email', '@example.org', 'dob', 'gender', 'LS7 3AA', 'applicant_cv'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_related_titles_are_included(): void
    {
        $senior = JobTitle::factory()->create(['name' => 'senior carer', 'job_category_id' => $this->category->id]);
        $this->title->update(['related_titles' => json_encode(['senior carer'])]);
        $sale = $this->sale();
        $a = $this->applicant(['job_title_id' => $senior->id]);
        $r = $this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $sale->id])->assertOk();
        $this->assertContains($a->id, array_column($r->json('candidates'), 'applicant_id'));
    }

    public function test_every_exclusion_is_counted(): void
    {
        $sale = $this->sale();
        $this->applicant(['is_blocked' => true]);
        $this->applicant(['is_temp_not_interested' => true]);
        $notHere = $this->applicant();
        ApplicantPivotSale::create(['applicant_id' => $notHere->id, 'sale_id' => $sale->id]);
        $this->applicant(['is_no_job' => true]);
        $this->applicant(['paid_status' => 'close']);
        $sent = $this->applicant();
        CVNote::create(['applicant_id' => $sent->id, 'sale_id' => $sale->id, 'user_id' => $this->ai->id, 'status' => 0, 'details' => 'x']);
        $locked = $this->applicant(['applicant_phone' => '07700900777']);
        DialLock::create(['phone_key' => DialLock::keyFor('07700900777'), 'full_number' => '07700900777',
            'user_id' => $this->ai->id, 'user_name' => 'Jane', 'locked_at' => now(), 'expires_at' => now()->addMinutes(5)]);
        $recent = $this->applicant();
        CallLog::create(['user_id' => $this->ai->id, 'applicant_id' => $recent->id, 'sale_id' => $sale->id,
            'direction' => 'outbound', 'called_at' => now()->subDays(2), 'source' => 'xplogent']);
        $this->applicant(['applicant_phone' => '12345']);                           // can't be dialled
        $ok = $this->applicant();

        $r = $this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $sale->id, 'exclude_called_within_days' => 14])->assertOk();
        $this->assertSame([$ok->id], array_column($r->json('candidates'), 'applicant_id'));
        $this->assertSame(['blocked' => 1, 'not_interested' => 2, 'no_job' => 1, 'closed' => 1, 'already_sent' => 1,
            'locked' => 1, 'recently_called' => 1, 'no_phone' => 1], $r->json('skipped'));
    }

    public function test_a_sale_that_cannot_be_called_gets_409(): void
    {
        foreach ([['status' => 0], ['is_on_hold' => 1], ['lat' => null], ['cv_limit' => 1]] as $state) {
            $sale = $this->sale($state);
            if (($state['cv_limit'] ?? 0) === 1) {
                CVNote::create(['applicant_id' => $this->applicant()->id, 'sale_id' => $sale->id,
                    'user_id' => $this->ai->id, 'status' => 1, 'details' => 'x']);
            }
            $this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $sale->id])
                ->assertStatus(409)->assertJson(['error' => 'sale_not_callable']);
        }
    }

    public function test_the_radius_screen_and_the_queue_share_one_query(): void
    {
        $sale = $this->sale();
        $a = $this->applicant();
        $this->applicant(['lat' => 51.5, 'lng' => -0.12]);
        $ids = app(CandidateQueueService::class)->radiusQuery($sale, 15)->pluck('applicants.id')->all();
        $this->assertSame([$a->id], $ids);

        // The recruiters' radius screen (SaleController::getApplicantsBySaleRadius) returns the same people.
        $user = $this->staff('Jane Recruiter', 'sale-view');
        $r = $this->actingAs($user)->getJson('/getApplicantsBySaleRadius?sale_id=' . $sale->id . '&radius=15',
            ['X-Requested-With' => 'XMLHttpRequest']);
        $r->assertOk();
        $this->assertSame([$a->id], array_map('intval', array_column($r->json('data'), 'id')));
    }
}
