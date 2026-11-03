<?php

namespace Tests\Feature\Audit;

use App\Services\Xplogent\CvExcerpt;
use Horsefly\Applicant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * N-04 (docs/audit/2026-11-03-audit-review.md): CVs are no longer public files. New uploads
 * are private; every CV goes through applicants/{id}/cv/{kind} (login, permission, blocked
 * rule); `cv:move-to-private` moves the old public ones.
 *
 * public/ and storage/ point at a temporary folder for each test, so no real CV is touched.
 */
class CvPrivacyTest extends XplogentTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cv-test-' . uniqid();
        File::ensureDirectoryExists($this->root . '/public');
        File::ensureDirectoryExists($this->root . '/storage/app');
        $this->app->usePublicPath($this->root . '/public');
        $this->app->useStoragePath($this->root . '/storage');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** A CV file as an older upload left it: public/uploads/resume/YYYY/MM/DD/<name>. */
    private function legacyCv(string $name = 'Sarah_Jones_1700000000.pdf', string $body = '%PDF-1.4 sarah'): string
    {
        $path = 'uploads/resume/2025/03/14/' . $name;
        File::ensureDirectoryExists(dirname(public_path($path)));
        file_put_contents(public_path($path), $body);

        return $path;
    }

    private function url(int $id, string $kind = 'original'): string
    {
        return '/applicants/' . $id . '/cv/' . $kind;
    }

    public function test_a_cv_download_needs_a_login_and_a_cv_permission(): void
    {
        $a = $this->applicant(['applicant_cv' => $this->legacyCv()]);

        $this->get($this->url($a->id))->assertRedirect('/login');                            // guests: no
        $this->actingAs($this->staff('Nora Nobody', 'applicant-index'))->get($this->url($a->id))->assertForbidden();

        $r = $this->actingAs($this->staff('Dan Downloader', 'applicant-download-resume'))->get($this->url($a->id))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline; filename="sarah-jones-cv.pdf"', $r->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 sarah', file_get_contents($r->baseResponse->getFile()->getPathname()));

        $this->get($this->url($a->id, 'updated'))->assertNotFound();                            // no CRM CV yet
        $this->get('/applicants/' . $a->id . '/cv/passport')->assertNotFound();
        $this->get($this->url(999999))->assertNotFound();

        // Screens that showed the icon without a download permission keep it (e.g. sales radius).
        $this->actingAs($this->staff('Sid Sales', 'sale-view'))->get($this->url($a->id))->assertOk();
    }

    public function test_a_blocked_applicants_cv_needs_the_show_blocked_data_permission(): void
    {
        $a = $this->applicant(['applicant_cv' => $this->legacyCv(), 'is_blocked' => true]);
        $this->actingAs($this->staff('Dan Downloader', 'applicant-download-resume'))->get($this->url($a->id))
            ->assertForbidden();
        $this->actingAs($this->staff('Bea Boss', 'applicant-download-resume', 'applicant-show-blocked-data'))
            ->get($this->url($a->id))->assertOk();
    }

    public function test_only_cv_locations_are_served(): void
    {
        File::put(base_path('.cv-test-secret'), 'secret');
        $user = $this->staff('Dan Downloader', 'applicant-download-resume');
        try {
            foreach (['uploads/../../.cv-test-secret', 'private/resumes/../../../.cv-test-secret', '.env', '/etc/passwd'] as $bad) {
                $a = $this->applicant(['applicant_cv' => $bad]);
                $this->actingAs($user)->get($this->url($a->id))->assertNotFound();
            }
            // A symlink inside the CV folder that leads out of it is not followed.
            File::ensureDirectoryExists(public_path('uploads/resume'));
            symlink(base_path('.cv-test-secret'), public_path('uploads/resume/link.pdf'));
            $a = $this->applicant(['applicant_cv' => 'uploads/resume/link.pdf']);
            $this->actingAs($user)->get($this->url($a->id))->assertNotFound();
        } finally {
            File::delete(base_path('.cv-test-secret'));
        }
    }

    public function test_an_upload_is_stored_privately_under_a_random_name_and_replaces_the_old_file(): void
    {
        $old = $this->legacyCv();
        $a = $this->applicant(['applicant_cv' => $old]);
        $user = $this->staff('Una Uploader', 'applicant-upload-resume', 'applicant-upload-crm-resume', 'applicant-download-resume');

        $r = $this->actingAs($user)->post('/applicants/uploadCv', ['applicant_id' => $a->id,
            'resume' => UploadedFile::fake()->createWithContent('Sarah Jones CV.pdf', '%PDF-1.4 new')])->assertOk();
        $path = $a->fresh()->applicant_cv;
        $this->assertMatchesRegularExpression('#^private/resumes/\d{4}/\d{2}/[a-z0-9]{40}\.pdf$#', $path);
        $this->assertSame($path, $r->json('file_path'));
        $this->assertSame(url($this->url($a->id)), $r->json('file_url'));
        $this->assertFileExists(storage_path('app/' . $path));
        $this->assertFileDoesNotExist(public_path($old));                 // the replaced public copy is gone
        $this->assertFalse(is_dir(public_path('uploads/resume/' . now()->format('Y'))));   // nothing new in public/

        // The CRM's copy (a Word file) downloads rather than opening in the browser.
        $this->actingAs($user)->post('/applicants/crmuploadCv', ['applicant_id' => $a->id,
            'resume' => UploadedFile::fake()->createWithContent('cv.docx', 'PK word')])->assertOk();
        $this->assertStringStartsWith('attachment; filename=sarah-jones-crm-cv.docx',
            $this->actingAs($user)->get($this->url($a->id, 'updated'))->assertOk()->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 new', file_get_contents(storage_path('app/' . $path)));
    }

    public function test_the_tables_link_to_the_download_route_not_to_the_file(): void
    {
        $sale = $this->sale();
        $a = $this->applicant(['applicant_cv' => $this->legacyCv()]);
        $row = collect($this->actingAs($this->staff('Sid Sales', 'sale-view'))->getJson('/getApplicantsBySaleRadius?'
            . http_build_query(['sale_id' => $sale->id, 'radius' => 15, 'draw' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data'))->firstWhere('id', $a->id);

        $this->assertStringContainsString('href="' . url($this->url($a->id)) . '"', $row['applicant_resume']);
        $this->assertStringNotContainsString('uploads/', $row['applicant_resume']);
        $this->assertStringContainsString('CV Not Available', $row['crm_resume']);         // no CRM CV

        $row = collect($this->actingAs($this->staff('Rita Resourcer', 'resource-category-index'))
            ->getJson('/getResourcesCategoryWised?draw=1', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data'))->firstWhere('id', $a->id);
        $this->assertStringContainsString('href="' . url($this->url($a->id)) . '"', $row['applicant_resume']);
    }

    public function test_the_move_command_moves_public_cvs_into_private_storage(): void
    {
        $shared = $this->legacyCv('shared.pdf', '%PDF shared');
        $both = $this->applicant(['applicant_cv' => $shared, 'updated_cv' => $shared]);
        $other = $this->applicant(['applicant_cv' => $shared]);                    // an import reused the file
        $gone = $this->applicant(['applicant_cv' => 'uploads/resume/2024/01/01/missing.pdf']);
        $fresh = $this->applicant(['applicant_cv' => 'private/resumes/2026/11/' . str_repeat('a', 40) . '.pdf']);
        Applicant::query()->update(['updated_at' => now()->subDays(30)->startOfSecond()]);
        $stamp = Applicant::find($both->id)->updated_at;

        $this->artisan('cv:move-to-private', ['--dry-run' => true])->expectsOutputToContain('Would move 1 file(s) for 2 applicant(s)')
            ->assertSuccessful();
        $this->assertSame($shared, $both->fresh()->applicant_cv);                  // dry run: nothing changed

        $this->artisan('cv:move-to-private')->expectsOutputToContain('Moved 1 file(s) for 2 applicant(s)')
            ->expectsOutputToContain('applicant ' . $gone->id . ' applicant_cv: uploads/resume/2024/01/01/missing.pdf')
            ->assertSuccessful();

        $new = $both->fresh()->applicant_cv;
        $this->assertMatchesRegularExpression('#^private/resumes/2025/03/[a-z0-9]{40}\.pdf$#', $new);
        $this->assertSame($new, $both->fresh()->updated_cv);
        $this->assertSame($new, $other->fresh()->applicant_cv);
        $this->assertSame('%PDF shared', file_get_contents(storage_path('app/' . $new)));
        $this->assertFileDoesNotExist(public_path($shared));
        $this->assertSame('uploads/resume/2024/01/01/missing.pdf', $gone->fresh()->applicant_cv);
        $this->assertSame('private/resumes/2026/11/' . str_repeat('a', 40) . '.pdf', $fresh->fresh()->applicant_cv);
        $this->assertTrue($stamp->equalTo($both->fresh()->updated_at));        // the Resources tabs don't move

        // Still downloadable, and a second run has nothing left to do.
        $this->actingAs($this->staff('Dan Downloader', 'applicant-download-resume'))->get($this->url($both->id))->assertOk();
        $this->artisan('cv:move-to-private')->expectsOutputToContain('Moved 0 file(s) for 0 applicant(s)')->assertSuccessful();
    }

    public function test_the_cv_excerpt_reads_private_and_old_public_cvs(): void
    {
        $private = 'private/resumes/2026/11/' . str_repeat('b', 40) . '.txt';
        File::ensureDirectoryExists(dirname(storage_path('app/' . $private)));
        file_put_contents(storage_path('app/' . $private), 'Senior carer with ten years of dementia care.');
        $this->assertStringContainsString('dementia care',
            app(CvExcerpt::class)->excerpt($this->applicant(['updated_cv' => $private])));
        $this->assertStringContainsString('night shifts', app(CvExcerpt::class)->excerpt(
            $this->applicant(['applicant_cv' => $this->legacyCv('old.txt', 'Happy to work night shifts.')])));
    }
}
