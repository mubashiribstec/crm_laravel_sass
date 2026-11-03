<?php

namespace Tests\Feature\Audit;

use App\Support\ApplicantAgeWindows;
use Horsefly\Applicant;
use Horsefly\CrmNote;
use Horsefly\CVNote;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\Office;
use Horsefly\Sale;
use Horsefly\SaleNote;
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

    // ── B-06 / L-04 / B-07: sale and applicant status writes ─────────────────
    public function test_sale_notes_and_status_changes_are_validated_and_recorded_together(): void
    {
        $sale = $this->sale();
        $noter = $this->staff('Jane Recruiter', 'sale-add-note');
        $this->actingAs($noter)->postJson('/storeSaleNotes', ['sale_id' => 999999, 'details' => 'x'])->assertStatus(422);
        $this->actingAs($noter)->postJson('/storeSaleNotes', ['sale_id' => $sale->id, 'details' => ''])->assertStatus(422);
        SaleNote::create(['sale_id' => $sale->id, 'sale_note' => 'old', 'user_id' => $this->ai->id, 'status' => 1]);
        $this->actingAs($noter)->post('/storeSaleNotes', ['sale_id' => $sale->id, 'details' => 'Client wants nights'])
            ->assertRedirect();
        $this->assertSame(1, SaleNote::where('sale_id', $sale->id)->where('status', 1)->count());
        $this->assertStringStartsWith('Client wants nights', $sale->fresh()->sale_notes);

        // changeSaleStatus: needs a change-status permission now, and a known status
        $this->actingAs($noter)->postJson('/changeSaleStatus', ['sale_id' => $sale->id, 'status' => 0, 'details' => 'x'])
            ->assertForbidden();
        $manager = $this->staff('Sam Manager', 'sale-change-status', 'sale-mark-on-hold', 'sale-edit');
        $this->actingAs($manager)->postJson('/changeSaleStatus', ['sale_id' => $sale->id, 'status' => 'open', 'details' => 'x'])
            ->assertStatus(422);
        $this->actingAs($manager)->post('/changeSaleStatus', ['sale_id' => $sale->id, 'status' => 1, 'details' => 'Reopen'])
            ->assertRedirect();
        $this->assertSame(2, (int) $sale->fresh()->status);                    // opening makes it pending
        $audit = json_decode($sale->audits()->latest('id')->value('data'), true);
        $this->assertSame(['status' => 1, 'stored_status' => 2], $audit['changes_made']);

        // hold: needs a hold permission and a valid state
        $this->actingAs($noter)->postJson('/changeSaleHoldStatus', ['id' => $sale->id, 'status' => 1])->assertForbidden();
        $this->actingAs($manager)->postJson('/changeSaleHoldStatus', ['id' => $sale->id, 'status' => 7])->assertStatus(422);

        // approving a hold only for a sale that is waiting for approval
        $approver = $this->staff('Pat Approver', 'sale-pending-hold-mark-approved');
        $this->actingAs($approver)->postJson('/updatePendingOnHoldStatus', ['sale_id' => $sale->id, 'status' => 1])
            ->assertStatus(409);

        $this->actingAs($manager)->get('/sales/999999/edit')->assertNotFound();      // B-07
    }

    public function test_an_applicants_status_change_is_validated(): void
    {
        $a = $this->applicant();
        $admin = $this->staff('Ada Admin', 'applicant-delete');
        $this->actingAs($admin)->postJson('/changeStatus', ['applicant_id' => $a->id, 'status' => 5, 'details' => 'x'])
            ->assertStatus(422);
        $this->actingAs($admin)->post('/changeStatus', ['applicant_id' => $a->id, 'status' => 0, 'details' => 'Left'])
            ->assertRedirect();
        $this->assertSame(0, (int) $a->fresh()->status);
    }

    // ── L-01: the radius screen's title filter ───────────────────────────────
    public function test_the_radius_list_honours_the_ticked_titles(): void
    {
        $senior = JobTitle::factory()->create(['name' => 'senior carer', 'job_category_id' => $this->category->id]);
        $this->title->update(['related_titles' => json_encode(['senior carer'])]);
        $sale = $this->sale();
        $carer = $this->applicant();
        $seniorCarer = $this->applicant(['job_title_id' => $senior->id]);
        $user = $this->staff('Jane Recruiter', 'sale-view');
        $ids = fn (array $extra) => collect($this->actingAs($user)->getJson('/getApplicantsBySaleRadius?' . http_build_query(
            ['sale_id' => $sale->id, 'radius' => 15, 'draw' => 1] + $extra), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame([$carer->id, $seniorCarer->id], $ids([]));                       // nothing ticked: both
        $this->assertSame([$seniorCarer->id], $ids(['title_filter' => [$senior->id]]));   // ticked: only that title
    }

    // ── L-03: dashboard counts use the Resources tabs' buckets ───────────────
    public function test_the_dashboard_counts_use_the_same_buckets_as_the_resources_tabs(): void
    {
        foreach ([3 => 'last-7-days', 20 => 'last-21-days', 60 => 'last-3-months', 400 => 'older'] as $daysAgo => $_) {
            $a = $this->applicant();
            Applicant::whereKey($a->id)->update(['updated_at' => now()->subDays($daysAgo)]);
        }
        $counts = $this->actingAs($this->staff('Ada Admin', 'dashboard-top-stats'))->getJson('/dashboard/counts')
            ->assertOk()->json();
        // "3 months" used to count everything older than 36 days (the 60- and 400-day ones: 2).
        $this->assertSame([1, 1, 1], [$counts['last7DaysCount'], $counts['last21DaysCount'], $counts['last3MonthsCount']]);

        foreach (['last-7-days' => 1, 'last-21-days' => 1, 'last-3-months' => 1, 'other' => 0] as $key => $n) {
            $this->assertSame($n, ApplicantAgeWindows::apply(Applicant::query(), 'updated_at', $key)->count(), $key);
        }
        // The Resources tab (same helper) answers for every bucket and returns the same applicant.
        $resources = $this->staff('Rita Resourcer', 'resource-category-index');
        foreach (['last-7-days', 'last-21-days', 'last-3-months', 'last-6-months', 'last-9-months', 'other'] as $key) {
            $r = $this->actingAs($resources)->getJson('/getResourcesCategoryWised?' . http_build_query(
                ['draw' => 1, 'date_range_filter' => $key]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
            if ($key === 'last-21-days') {
                $this->assertSame(1, (int) $r->json('recordsFiltered'));
            }
        }
    }

    // ── L-06: haversine at distance ~0 ───────────────────────────────────────
    public function test_a_candidate_at_the_vacancys_exact_location_is_not_dropped(): void
    {
        // For these coordinates the unclamped ACOS argument rounds to just over 1 (NULL in MySQL).
        $sale = $this->sale(['lat' => 50.034, 'lng' => -4.762]);
        $here = $this->applicant(['lat' => 50.034, 'lng' => -4.762]);
        $ids = array_column($this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $sale->id])
            ->assertOk()->json('candidates'), 'applicant_id');
        $this->assertSame([$here->id], $ids);
    }

    // ── R-03 – R-06: routes ──────────────────────────────────────────────────
    public function test_logout_and_the_hold_change_are_post_only_and_route_names_are_unique(): void
    {
        $user = $this->staff('Jane Recruiter', 'sale-mark-on-hold');
        \Horsefly\LoginDetail::create(['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'login_at' => now()]);
        $this->actingAs($user)->get('/logout')->assertNotFound();                      // a link can't log you out
        $this->assertAuthenticatedAs($user);
        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull(\Horsefly\LoginDetail::where('user_id', $user->id)->value('logout_at'));

        $sale = $this->sale();
        $this->actingAs($user)->getJson('/changeSaleHoldStatus?id=' . $sale->id . '&status=1')->assertNotFound();
        $this->assertSame(0, (int) $sale->fresh()->is_on_hold);                         // a GET changes nothing

        $this->assertSame(url('/login'), route('login'));
        $this->assertSame('App\\Http\\Controllers\\Auth\\LoginController@showLoginForm',
            app('router')->getRoutes()->getByName('login')->getActionName());
        $this->assertNotSame(route('api.sales.open'), route('api.sales.open.kingsbury'));
        $names = collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter();
        $this->assertSame($names->count(), $names->unique()->count(), 'duplicate route names: '
            . $names->duplicates()->implode(', '));
        foreach (app('router')->getRoutes()->getRoutes() as $route) {                   // R-03/R-04: every action exists
            $action = $route->getActionName();
            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action);
                $this->assertTrue(method_exists($class, $method), "{$route->uri()} → {$action}");
            }
        }
    }

    // ── S-03 – S-05: seeders ─────────────────────────────────────────────────
    public function test_the_full_seeder_runs_twice_without_duplicates_or_overwriting_real_values(): void
    {
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
        DB::table('settings')->where('key', 'google_map_api_key')->update(['value' => 'real-key']);
        $counts = fn () => collect(['job_categories', 'job_sources', 'allowed_ips', 'smtp_settings', 'regions',
            'email_templates', 'settings', 'ip_addresses'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $first = $counts();
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);

        $this->assertSame($first, $counts());
        $this->assertSame(['job_sources' => 7, 'allowed_ips' => 2, 'smtp_settings' => 1, 'regions' => 12],
            array_intersect_key($first, array_flip(['job_sources', 'allowed_ips', 'smtp_settings', 'regions'])));
        $this->assertSame(5, DB::table('job_categories')->whereIn('name', ['Nurse', 'Non Nurse', 'Specialist', 'Chef', 'Nursery'])->count());
        $this->assertSame('real-key', DB::table('settings')->where('key', 'google_map_api_key')->value('value'));
        $this->assertTrue(DB::table('job_categories')->where('name', 'Specialist')->exists());
        $this->assertFalse(DB::table('job_categories')->where('name', 'Speicialist')->exists());
        $this->assertSame(0, (int) DB::table('email_templates')->where('slug', 'send_job_vacancy_details')->value('is_active'));
        $this->assertNotNull(DB::table('regions')->where('name', 'Greater London')->value('latitude'));
    }

    // ── P-01: bounding box before the haversine ──────────────────────────────
    public function test_the_radius_box_keeps_everyone_inside_the_radius_and_nobody_outside(): void
    {
        $sale = $this->sale(['lat' => 53.82, 'lng' => -1.58]);
        $kmPerDegLng = 111.32 * cos(deg2rad(53.82));
        $in = [$this->applicant(['lat' => 53.82 + 14.8 / 111.32, 'lng' => -1.58]),        // 14.8 km north
               $this->applicant(['lat' => 53.82, 'lng' => -1.58 + 14.8 / $kmPerDegLng])];  // 14.8 km east
        $this->applicant(['lat' => 53.82 + 15.3 / 111.32, 'lng' => -1.58]);              // 15.3 km north
        $this->applicant(['lat' => 53.82, 'lng' => -1.58 - 15.3 / $kmPerDegLng]);        // 15.3 km west
        $ids = array_column($this->signed('/api/xplogent/campaigns/queue', ['sale_id' => $sale->id, 'radius_km' => 15])
            ->assertOk()->json('candidates'), 'applicant_id');
        sort($ids);
        $this->assertSame(collect($in)->pluck('id')->sort()->values()->all(), $ids);
        $this->assertTrue(Schema::hasIndex('applicants', 'idx_applicants_radius'));
    }

    // ── P-02: the direct and rejected sale lists ─────────────────────────────
    public function test_the_direct_and_rejected_sale_lists_return_the_same_rows_dates_and_counts(): void
    {
        $open = $this->sale();
        $other = $this->sale();
        $this->sale(['is_on_hold' => 1]);                                   // not listed
        foreach ([[$open, '2026-10-01 09:00:00'], [$open, '2026-10-20 10:30:00'], [$other, '2026-09-15 08:00:00']] as [$sale, $at]) {
            $sale->audits()->create(['user_id' => $this->ai->id, 'data' => '{}', 'message' => 'sale-opened'])
                ->forceFill(['created_at' => $at])->save();
        }
        foreach ([1, 1, 0] as $status) {                                      // two active CVs, one inactive
            CVNote::create(['applicant_id' => $this->applicant()->id, 'sale_id' => $open->id, 'user_id' => $this->ai->id,
                'status' => $status, 'details' => 'x']);
        }
        $rejected = $this->sale(['status' => 3]);
        $rejected->audits()->create(['user_id' => $this->ai->id, 'data' => '{}', 'message' => 'sale-rejected'])
            ->forceFill(['created_at' => '2026-10-05 12:00:00'])->save();

        $get = fn (string $uri, string $perm, array $q = []) => $this->actingAs($this->staff('Sam ' . $perm, $perm))
            ->getJson($uri . '?' . http_build_query(['draw' => 1, 'length' => 50] + $q), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data');

        $rows = collect($get('/getDirectSales', 'sale-direct-index'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$open->id, $other->id], $rows->keys()->all());
        $this->assertSame('20 Oct 2026, 10:30 AM', $rows[$open->id]['open_date']);          // the latest "opened" audit
        $this->assertStringContainsString('6/8', $rows[$open->id]['cv_limit']);             // 8 - 2 active CVs
        $byDate = collect($get('/getDirectSales', 'sale-direct-index', ['columns' => [['data' => 'open_date']],
            'order' => [['column' => 0, 'dir' => 'asc']]]))->pluck('id')->all();
        $this->assertSame([$other->id, $open->id], $byDate);
        $byCvs = collect($get('/getDirectSales', 'sale-direct-index', ['columns' => [['data' => 'no_of_sent_cv']],
            'order' => [['column' => 0, 'dir' => 'desc']]]))->pluck('id')->all();
        $this->assertSame($open->id, $byCvs[0]);

        $rej = collect($get('/getRejectedSales', 'sale-rejected-index'))->keyBy('id');
        $this->assertSame([$rejected->id], $rej->keys()->all());
    }

    // ── P-03: the portal feed ────────────────────────────────────────────────
    public function test_the_portal_feed_returns_everything_by_default_and_pages_on_request(): void
    {
        config(['services.portal.token' => 'portal-test-token-0123456789']);
        foreach (range(1, 3) as $_) {
            $this->sale();
        }
        $auth = ['Authorization' => 'Bearer portal-test-token-0123456789'];
        $all = $this->getJson('/api/sales/open', $auth)->assertOk()->assertJsonMissingPath('meta')->json('data');
        $this->assertCount(3, $all);                                          // unchanged for the portal
        $page = $this->getJson('/api/sales/open?per_page=2&page=2', $auth)->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2, 'from' => 3, 'to' => 3],
            $page->json('meta'));
        $this->assertSame(100, $this->getJson('/api/sales/open?per_page=5000', $auth)->json('meta.per_page'));
        $this->assertContains('throttle:60,1', app('router')->getRoutes()->getByName('api.sales.open')->gatherMiddleware());
    }
}
