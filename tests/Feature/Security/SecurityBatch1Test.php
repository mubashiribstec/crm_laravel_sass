<?php

namespace Tests\Feature\Security;

use Horsefly\Message;
use Horsefly\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * Batch 1 of the 3 Nov audit (docs/audit/2026-11-03-audit-review.md): P0 and security fixes.
 * Reuses the Xplogent test base for its fixtures (sale, applicant, staff with permissions).
 */
class SecurityBatch1Test extends XplogentTestCase
{
    // ── N-03: CV uploads ─────────────────────────────────────────────────────
    public function test_cv_upload_refuses_scripts_and_keeps_the_old_cv_until_the_new_one_is_saved(): void
    {
        $staff = $this->staff('Jane Recruiter', 'applicant-upload-resume');
        $a = $this->applicant();

        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php echo "pwned";');
        $this->actingAs($staff)->postJson('/applicants/uploadCv', ['applicant_id' => $a->id, 'resume' => $php])
            ->assertStatus(422);
        $this->assertNull($a->fresh()->applicant_cv);

        $first = $this->actingAs($staff)->postJson('/applicants/uploadCv', ['applicant_id' => $a->id,
            'resume' => UploadedFile::fake()->createWithContent('Sarah CV.pdf', '%PDF-1.4 one')])->assertOk()->json('file_path');
        // Private storage, random name (N-04; CvPrivacyTest covers the download side).
        $this->assertMatchesRegularExpression('#^private/resumes/\d{4}/\d{2}/[a-z0-9]{40}\.pdf$#', $first);
        $this->assertFileExists(storage_path('app/' . $first));

        $second = $this->actingAs($staff)->postJson('/applicants/uploadCv', ['applicant_id' => $a->id,
            'resume' => UploadedFile::fake()->createWithContent('new.docx', 'PK docx')])->assertOk()->json('file_path');
        $this->assertSame($second, $a->fresh()->applicant_cv);
        $this->assertFileDoesNotExist(storage_path('app/' . $first));       // replaced only after the new one is saved
        File::delete(storage_path('app/' . $second));

        $this->actingAs($this->staff('Nobody'))->postJson('/applicants/uploadCv', ['applicant_id' => $a->id])
            ->assertForbidden();
    }

    // ── R-01: sign-up waits for approval ─────────────────────────────────────
    public function test_sign_up_creates_an_inactive_account_and_does_not_log_in(): void
    {
        $this->post('/register', ['name' => 'New Person', 'email' => 'new.person@test.co.uk',
            'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('approval');
        $this->assertGuest();
        $user = User::where('email', 'new.person@test.co.uk')->firstOrFail();
        $this->assertSame(0, (int) $user->is_active);
        $this->assertSame(0, (int) $user->is_admin);
        $this->assertCount(0, $user->roles);
    }

    // ── R-02, R-08 – R-11: permissions on controllers that had none ──────────
    public function test_crm_actions_need_crm_access_and_the_money_path_needs_its_own_permission(): void
    {
        $this->actingAs($this->staff('No Role'))->postJson('/crmChangePaidStatus', [])->assertForbidden();
        $this->actingAs($this->staff('Viewer', 'crm-index'))->postJson('/crmChangePaidStatus', [])->assertForbidden();
        $this->assertNotSame(403, $this->actingAs($this->staff('Viewer', 'crm-index'))
            ->postJson('/crmRequestNoResponse', [])->status());             // a button with no permission of its own
        // (each other action needs its button's permission: Batch3Test covers them)
        $code = $this->actingAs($this->staff('Payroll', 'crm-index', 'crm-paid-toggle-status'))
            ->postJson('/crmChangePaidStatus', [])->status();
        $this->assertNotSame(403, $code);                                    // allowed through (then fails validation)
    }

    public function test_admin_tools_imports_and_notes_are_no_longer_open_to_every_account(): void
    {
        $plain = $this->staff('Plain User');
        $this->actingAs($plain)->postJson('/dialing/clear-all-locks')->assertForbidden();
        $this->actingAs($plain)->postJson('/audits/import')->assertForbidden();
        $this->actingAs($plain)->postJson('/module-notes/store', ['module' => 'Applicant', 'module_key' => 1,
            'details' => 'x'])->assertForbidden();
        $this->assertNotSame(403, $this->actingAs($this->staff('Admin2', 'administrator-setting-index'))
            ->postJson('/dialing/clear-all-locks')->status());
        $this->assertNotSame(403, $this->actingAs($this->staff('Noter', 'sale-open-add-note'))
            ->postJson('/module-notes/store', ['module' => 'Nope', 'module_key' => 1, 'details' => 'x'])->status());
    }

    // ── B-09 / L-02: radius table sorting ────────────────────────────────────
    public function test_radius_table_sort_direction_and_column_are_allowlisted(): void
    {
        $sale = $this->sale();
        $this->applicant();
        $user = $this->staff('Jane Recruiter', 'sale-view');
        $query = http_build_query(['sale_id' => $sale->id, 'radius' => 15, 'draw' => 1,
            'columns' => [['data' => 'notes_created_at'], ['data' => 'paid_status'], ['data' => 'sleep(5)']],
            'order' => [['column' => 0, 'dir' => 'desc, (select 1 from users)']]]);
        $this->actingAs($user)->getJson('/getApplicantsBySaleRadius?' . $query, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
        foreach ([1, 2] as $col) {                                          // computed and unknown columns
            $q = str_replace('order%5B0%5D%5Bcolumn%5D=0', 'order%5B0%5D%5Bcolumn%5D=' . $col, $query);
            $this->actingAs($user)->getJson('/getApplicantsBySaleRadius?' . $q, ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk();
        }
    }

    // ── R-07: inbound SMS webhook ────────────────────────────────────────────
    public function test_sms_webhook_needs_its_token_and_parses_the_time(): void
    {
        $a = $this->applicant(['applicant_phone' => '07700900123']);
        $params = ['phoneNumber' => '+447700900123', 'message' => 'Yes I am interested', 'time' => '03/11/2026 10:15:00'];
        $this->get('/message_receive?' . http_build_query($params))->assertForbidden();          // no token configured
        config(['services.sms_webhook.token' => 'sms-test-token-0123456789']);
        $this->get('/message_receive?' . http_build_query($params + ['token' => 'wrong']))->assertForbidden();
        $this->get('/message_receive?' . http_build_query($params + ['token' => 'sms-test-token-0123456789']))->assertOk();
        $m = Message::latest('id')->first();
        $this->assertSame($a->id, (int) $m->module_id);
        $this->assertSame('2026-11-03 10:15:00', (string) $m->created_at);
    }

    // ── X-01: replay protection ──────────────────────────────────────────────
    public function test_a_signed_xplogent_request_is_accepted_once(): void
    {
        $body = json_encode(['limit' => 1]);
        $ts = (string) time();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN, 'HTTP_X_XPLOGENT_TIMESTAMP' => $ts,
            'HTTP_X_XPLOGENT_SIGNATURE_V2' => \App\Support\Xplogent::sign($body, self::TOKEN, $ts)];
        $this->call('POST', '/api/xplogent/sales/open', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/xplogent/sales/open', [], [], [], $headers, $body)
            ->assertStatus(401)->assertJson(['error' => 'replay']);
    }

    // ── S-01 / S-02 / S-07: seeders ──────────────────────────────────────────
    public function test_seeders_can_run_twice_and_do_not_use_a_known_password(): void
    {
        foreach (range(1, 2) as $_) {
            Artisan::call('db:seed', ['--class' => 'PermissionsTableSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'UserSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        }
        $this->assertSame(1, User::where('email', 'admin@gmail.com')->count());
        $this->assertSame(1, Role::where('name', 'super_admin')->count());
        $this->assertTrue(User::where('email', 'admin@gmail.com')->first()->hasRole('super_admin'));
    }
}
