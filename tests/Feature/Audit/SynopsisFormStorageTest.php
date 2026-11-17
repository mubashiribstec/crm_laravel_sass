<?php

namespace Tests\Feature\Audit;

use App\Support\SynopsisFormStorage;
use Horsefly\ApplicantsSynopsisForm;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * Send CV writes a Word synopsis form (name, visa status, DBS convictions, salary). It used to be
 * written into public/uploads/synopsisForms/, which the web server serves without a login. It is
 * now kept in storage/app/private/synopsis-forms/ and only the download routes serve it.
 */
class SynopsisFormStorageTest extends XplogentTestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function sendCv(): ApplicantsSynopsisForm
    {
        $a = $this->applicant();
        $sale = $this->sale();
        $res = $this->actingAs($this->staff('Jane Recruiter', 'applicant-view'))->postJson('/sendCVtoQuality', [
            'applicant_id' => $a->id, 'sale_id' => $sale->id, 'details' => 'Keen, drives',
            'visa_status' => 'British citizen', 'dbs_convictions' => 'None', 'expected_salary' => '£13.50',
            'interview_option_1' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'interview_option_2' => now()->addDays(8)->format('Y-m-d\TH:i'),
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($res->json('download_url'));

        $form = $a->synopsisForms()->sole();
        $this->cleanup[] = (string) SynopsisFormStorage::fullPath($form->file_url);

        return $form;
    }

    public function test_send_cv_writes_the_form_outside_the_web_root(): void
    {
        $form = $this->sendCv();
        $this->assertStringStartsWith('private/synopsis-forms/', $form->file_url);
        $this->assertStringNotContainsString('sarah', strtolower($form->file_url));     // random name, not the applicant's
        $path = SynopsisFormStorage::existingPath($form->file_url);
        $this->assertNotNull($path);
        $this->assertStringStartsWith(storage_path('app/private/synopsis-forms'), $path);
        $this->assertStringStartsNotWith(public_path(), $path);
        $this->assertSame("PK", substr((string) file_get_contents($path), 0, 2));     // a real .docx (zip)
    }

    public function test_only_the_download_routes_serve_it(): void
    {
        $form = $this->sendCv();

        // Signed in, with the permission: the file, under a readable name.
        $res = $this->actingAs($this->staff('Quality Lead', 'applicant-show-synopsis-forms'))
            ->get(route('applicants.draft-file.download', $form->id))->assertOk();
        $this->assertStringContainsString('sarah-jones_synopsis-form_', (string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('cache-control'));

        // Without the permission: refused.
        $this->actingAs($this->staff('Someone Else'))
            ->get(route('applicants.draft-file.download', $form->id))->assertForbidden();

        // The sender's short-lived signed link works; a tampered one doesn't.
        $signed = URL::temporarySignedRoute('applicants.draft-file.auto-download', now()->addMinutes(10),
            ['synopsisForm' => $form->id]);
        $this->actingAs($this->staff('Jane Sender', 'applicant-view'))->get($signed)->assertOk();
        $this->actingAs($this->staff('Jane Sender 2', 'applicant-view'))->get($signed . 'x')->assertForbidden();
    }

    public function test_old_public_forms_still_download_and_the_move_command_moves_them(): void
    {
        $a = $this->applicant();
        $sale = $this->sale();
        $old = 'uploads/synopsisForms/2026/11/10/sarah-jones_20261110_101010_000001.docx';
        File::ensureDirectoryExists(dirname(public_path($old)));
        File::put(public_path($old), 'PK old form');
        $this->cleanup[] = public_path($old);
        $form = $a->synopsisForms()->create(['sale_id' => $sale->id, 'file_url' => $old]);

        $lead = $this->staff('Quality Lead', 'applicant-show-synopsis-forms');
        $this->actingAs($lead)->get(route('applicants.draft-file.download', $form->id))->assertOk();

        $this->artisan('synopsis:move-to-private', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($old, $form->fresh()->file_url);                        // the dry run changes nothing

        $this->artisan('synopsis:move-to-private')->assertSuccessful();
        $moved = $form->fresh()->file_url;
        $this->cleanup[] = (string) SynopsisFormStorage::fullPath($moved);
        $this->assertStringStartsWith('private/synopsis-forms/2026/11/', $moved);
        $this->assertFileDoesNotExist(public_path($old));                          // the public copy is gone
        $this->assertSame('PK old form', file_get_contents(SynopsisFormStorage::existingPath($moved)));
        $this->actingAs($lead)->get(route('applicants.draft-file.download', $form->id))->assertOk();
    }

    public function test_a_stored_path_never_reaches_other_public_uploads(): void
    {
        $this->assertNull(SynopsisFormStorage::fullPath('uploads/resume/2026/11/cv.pdf'));     // CVs
        $this->assertNull(SynopsisFormStorage::fullPath('uploads/docs/a.pdf'));                // attachments
        $this->assertNull(SynopsisFormStorage::fullPath('uploads/synopsisForms/../resume/x.docx'));
        $this->assertNull(SynopsisFormStorage::fullPath('/etc/passwd'));
        $this->assertNotNull(SynopsisFormStorage::fullPath('uploads/drafts/x.docx'));
    }

    public function test_nginx_and_apache_refuse_the_old_public_folders(): void
    {
        $nginx = (string) file_get_contents(base_path('docker/nginx.conf'));
        $this->assertStringContainsString("location ^~ /uploads/synopsisForms/ {\n        return 404;", $nginx);
        $this->assertStringContainsString("location ^~ /uploads/drafts/ {\n        return 404;", $nginx);
        $htaccess = (string) file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('RewriteRule ^uploads/synopsisForms/ - [R=404,L]', $htaccess);
        $this->assertStringContainsString('RewriteRule ^uploads/drafts/ - [R=404,L]', $htaccess);
    }
}
