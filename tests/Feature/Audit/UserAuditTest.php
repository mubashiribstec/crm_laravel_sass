<?php

namespace Tests\Feature\Audit;

use Horsefly\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * N-11 (docs/audit/2026-11-03-audit-review.md): a user's audit row says a password, remember
 * token or SIP password changed, never what to; older rows are cleaned by a migration; a change
 * made with nobody signed in (console, queue) is still recorded instead of failing.
 * N-12: the activity log shows saved values as text and web links, never as markup or script.
 */
class UserAuditTest extends XplogentTestCase
{
    private function lastUserAudit(User $user): object
    {
        return DB::table('audits')->where('auditable_type', User::class)->where('auditable_id', $user->id)
            ->orderByDesc('id')->firstOrFail();
    }

    /** audits.data is JSON, usually inside a JSON string (as UserController::decodeAuditData reads it). */
    private function data(object $audit): array
    {
        $data = json_decode($audit->data, true);

        return is_string($data) ? json_decode($data, true) : $data;
    }

    public function test_a_password_change_is_audited_without_the_hash_and_shown_without_it(): void
    {
        $admin = $this->staff('Ada Admin', 'administrator-user-activity-log');
        $user = User::factory()->create(['name' => 'Rita Recruiter']);
        $this->travel(2)->seconds();                // the observer skips an update in the creating second
        $this->actingAs($admin);

        $hash = Hash::make('a new password');
        $user->forceFill(['password' => $hash, 'name' => 'Rita R.', 'sip_password' => 'sip-secret-1'])->save();

        $audit = $this->lastUserAudit($user);
        $data = $this->data($audit);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('(changed)', $data['changes_made']['password']);
        $this->assertSame('(changed)', $data['changes_made']['sip_password']);
        $this->assertSame('Rita R.', $data['changes_made']['name']);
        $this->assertStringNotContainsString($hash, $audit->data);
        $this->assertStringNotContainsString('sip-secret-1', $audit->data);
        $this->assertStringNotContainsString('$2y$', $audit->data);

        // The admin's activity log (where these rows are shown) has no hash either.
        $log = $this->actingAs($admin)->postJson('/getUserActivityLogs', ['id' => $admin->id, 'draw' => 1],
            ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertStringNotContainsString('$2y$', $log->getContent());
    }

    public function test_remember_token_cycling_is_not_an_audited_change_and_a_console_change_is_recorded(): void
    {
        $user = User::factory()->create();
        $this->travel(2)->seconds();
        $before = DB::table('audits')->where('auditable_id', $user->id)->where('auditable_type', User::class)->count();

        $user->forceFill(['remember_token' => str_repeat('r', 60)])->save();
        $this->assertSame($before, DB::table('audits')->where('auditable_id', $user->id)->where('auditable_type', User::class)->count());

        Auth::logout();                              // as from php artisan or a queued job
        $user->update(['name' => 'Changed From The Console']);
        $audit = $this->lastUserAudit($user);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Changed From The Console', $this->data($audit)['changes_made']['name']);
    }

    public function test_the_migration_cleans_secrets_out_of_older_user_audits(): void
    {
        $user = User::factory()->create();
        $hash = Hash::make('old password');
        $old = DB::table('audits')->insertGetId(['user_id' => $user->id, 'auditable_id' => $user->id,
            'auditable_type' => User::class, 'message' => "User 'x' has been updated successfully",
            'data' => json_encode(json_encode(['id' => $user->id, 'name' => 'x', 'changes_made' => [   // as the observer stored it
                'password' => $hash, 'remember_token' => 'tok123', 'name' => 'x']])),
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00']);
        $other = DB::table('audits')->insertGetId(['user_id' => $user->id, 'auditable_id' => 5,
            'auditable_type' => 'Horsefly\Sale', 'message' => 'sale', 'data' => json_encode(['changes_made' => ['password' => 'not a user']]),
            'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_11_04_200500_scrub_secrets_from_user_audits.php');
        $migration->up();
        $migration->up();                            // safe to run again

        $row = DB::table('audits')->find($old);
        $this->assertIsString(json_decode($row->data, true));        // still stored the same way
        $data = $this->data($row);
        $this->assertSame(['password' => '(changed)', 'remember_token' => '(changed)', 'name' => 'x'], $data['changes_made']);
        $this->assertStringNotContainsString($hash, $row->data);
        $this->assertSame('2026-10-01 09:00:00', (string) $row->updated_at);
        $this->assertStringContainsString('not a user', DB::table('audits')->find($other)->data);  // other models untouched
    }

    public function test_the_activity_log_shows_saved_values_without_running_them(): void
    {
        $admin = $this->staff('Ada Admin', 'administrator-user-activity-log');
        DB::table('audits')->insert(['user_id' => $admin->id, 'auditable_id' => 1, 'auditable_type' => 'Horsefly\\Office',
            'message' => 'Office updated', 'created_at' => now(), 'updated_at' => now(),
            'data' => json_encode(json_encode(['id' => 1, 'changes_made' => [
                'office_website' => "http://x.test/'onmouseover='alert(1)",
                'office_notes' => '<b onmouseover="alert(2)">bold</b> and <img src=x onerror=alert(3)>',
                'office_link' => 'javascript://x.test/%0aalert(4)',
                'office_page' => 'https://example.org/a?b=1&c=2',
            ]]))]);

        $details = $this->actingAs($admin)->postJson('/getUserActivityLogs', ['id' => $admin->id, 'draw' => 1],
            ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('data.0.details');

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $details . '</div>');
        $hrefs = [];
        foreach ((new \DOMXPath($dom))->query('//*') as $el) {
            foreach ($el->attributes as $attr) {
                $this->assertStringStartsNotWith('on', strtolower($attr->name), "<{$el->nodeName} {$attr->name}> in: {$details}");
                if ($attr->name === 'href') {
                    $hrefs[] = $attr->value;
                }
            }
            $this->assertNotSame('img', $el->nodeName);
        }
        foreach ($hrefs as $href) {                                              // web links only
            $this->assertMatchesRegularExpression('#^(https?://|\#$)#', $href);
        }
        $this->assertContains('https://example.org/a?b=1&c=2', $hrefs);
        $this->assertContains("http://x.test/'onmouseover='alert(1)", $hrefs);  // one escaped value, no new attribute
        $this->assertStringNotContainsString('javascript:', implode(' ', $hrefs));
        $this->assertStringContainsString('<b>bold</b>', $details);              // formatting kept, attributes not
    }
}
