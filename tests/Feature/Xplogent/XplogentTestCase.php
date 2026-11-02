<?php

namespace Tests\Feature\Xplogent;

use App\Support\Xplogent;
use Horsefly\Applicant;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\Office;
use Horsefly\Sale;
use Horsefly\Unit;
use Horsefly\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;

/**
 * Base for the Xplogent feature tests. Extends the framework TestCase directly
 * (tests/TestCase.php carries a homepage test every subclass would inherit).
 */
abstract class XplogentTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token-0123456789abcdef0123456789';

    protected User $ai;
    protected JobCategory $category;
    protected JobTitle $title;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();                      // screens render without a built asset manifest
        // The CRM's observers audit unattended writes as user 1 (the admin), as in production.
        User::factory()->create(['id' => 1, 'name' => 'Admin']);
        $this->ai = User::factory()->create(['name' => Xplogent::AI_USER_NAME, 'is_active' => 0]);
        config([
            'xplogent.api_token' => self::TOKEN,
            'xplogent.ai_user_id' => $this->ai->id,
            'xplogent.email_dns_check' => false,
            'xplogent.share_client_name' => false,
            'xplogent.share_cv_excerpt' => false,
        ]);
        $this->category = JobCategory::factory()->create(['name' => 'Care']);
        $this->title = JobTitle::factory()->create(['name' => 'care assistant', 'job_category_id' => $this->category->id]);
    }

    /** A CRM user holding these permissions. */
    protected function staff(string $name, string ...$permissions): User
    {
        $user = User::factory()->create(['name' => $name]);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** POST signed exactly the way Xplogent signs (voip/crm.py). */
    protected function signed(string $uri, array $data, ?string $token = null, ?int $ts = null): TestResponse
    {
        $body = json_encode($data);
        $token ??= self::TOKEN;
        $ts = (string) ($ts ?? time());

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_X_XPLOGENT_TIMESTAMP' => $ts,
            'HTTP_X_XPLOGENT_SIGNATURE_V2' => Xplogent::sign($body, $token, $ts),
        ], $body);
    }

    /** An open vacancy in Leeds (LS6) for "care assistant". */
    protected function sale(array $overrides = []): Sale
    {
        $office = Office::factory()->create(['office_name' => 'Oak Care Group']);

        return Sale::factory()->create(array_merge([
            'office_id' => $office->id,
            'unit_id' => Unit::factory()->create(['office_id' => $office->id, 'unit_name' => 'Oak House'])->id,
            'job_category_id' => $this->category->id,
            'job_title_id' => $this->title->id,
            'sale_postcode' => 'LS6 2AB',
            'position_type' => 'Full time',
            'salary' => '£12.60 per hour',
            'timing' => 'Days',
            'lat' => 53.8200, 'lng' => -1.5800,
            'status' => 1, 'is_on_hold' => 0, 'cv_limit' => 8,
        ], $overrides));
    }

    /** A callable candidate ~1 km from the sale. */
    protected function applicant(array $overrides = []): Applicant
    {
        return Applicant::factory()->create(array_merge([
            'job_source_id' => JobSource::factory(),
            'job_category_id' => $this->category->id,
            'job_title_id' => $this->title->id,
            'applicant_name' => 'Sarah Jones',
            'applicant_email' => 'sarah.' . uniqid() . '@example.org',
            'applicant_email_secondary' => null,
            'applicant_postcode' => 'LS7 3AA',
            'applicant_phone' => '07700 900' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT),
            'applicant_phone_secondary' => null,
            'applicant_landline' => null,
            'applicant_cv' => null, 'updated_cv' => null,
            'lat' => 53.8290, 'lng' => -1.5800,
            'status' => 1, 'paid_status' => 'open',
            'is_blocked' => false, 'is_temp_not_interested' => false, 'is_no_job' => false,
            'is_callback_enable' => false, 'is_no_response' => false, 'is_in_nurse_home' => false,
            'is_circuit_busy' => false,
        ], $overrides));
    }
}
