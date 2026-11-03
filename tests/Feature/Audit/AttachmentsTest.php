<?php

namespace Tests\Feature\Audit;

use Horsefly\Office;
use Horsefly\OfficeDocument;
use Horsefly\Unit;
use Horsefly\UnitDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * N-10 (docs/audit/2026-11-03-audit-review.md): office and unit attachments are documents or
 * images only, stored privately, and reachable only through the permission-checked routes;
 * `documents:move-to-private` moves the older public ones.
 *
 * public/ and storage/ point at a temporary folder for each test.
 */
class AttachmentsTest extends XplogentTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/docs-test-' . uniqid();
        File::ensureDirectoryExists($this->root . '/public');
        File::ensureDirectoryExists($this->root . '/storage/app');
        $this->app->usePublicPath($this->root . '/public');
        $this->app->useStoragePath($this->root . '/storage');
        // A known postcode, so saving an office doesn't call the geocoder.
        DB::table('postcodes')->insert(['postcode' => 'LS6 2AB', 'lat' => 53.82, 'lng' => -1.58]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function officeForm(array $attachments): array
    {
        return ['office_name' => 'Oak Care Group', 'office_type' => 'head_office', 'office_postcode' => 'LS6 2AB',
            'office_notes' => 'new client', 'contact_name' => ['Pat Manager'], 'contact_email' => ['pat@oak.test'],
            'contact_phone' => ['07700900321'], 'contact_landline' => [''], 'contact_note' => [''],   // as the form sends
            'attachments' => $attachments];
    }

    private function legacyFile(string $name, string $body = '%PDF-1.4 old'): string
    {
        $path = 'uploads/docs/2026/10/28/' . $name;
        File::ensureDirectoryExists(dirname(public_path($path)));
        file_put_contents(public_path($path), $body);

        return $path;
    }

    public function test_an_attachment_must_be_a_document_or_an_image_and_is_stored_privately(): void
    {
        $staff = $this->staff('Olive Office', 'office-create', 'office-view');
        foreach (['shell.php', 'page.html', 'drawing.svg', 'run.phar'] as $bad) {
            $this->actingAs($staff)->postJson('/head-offices/store', $this->officeForm([
                UploadedFile::fake()->createWithContent($bad, '<?php echo 1;')]))->assertStatus(422);
        }
        $this->assertSame(0, Office::count());

        $this->actingAs($staff)->postJson('/head-offices/store', $this->officeForm([
            UploadedFile::fake()->createWithContent('Contract.PDF', '%PDF-1.4 contract')]))->assertOk();
        $doc = OfficeDocument::firstOrFail();
        $this->assertMatchesRegularExpression('#^private/documents/\d{4}/\d{2}/[a-z0-9]{40}\.pdf$#', $doc->document_path);
        $this->assertSame('pdf', $doc->document_extension);
        $this->assertSame('Contract.PDF', $doc->document_name);
        $this->assertFileExists(storage_path('app/' . $doc->document_path));
        $this->assertDirectoryDoesNotExist(public_path('uploads/docs'));

        // Served only through the route, to staff who may see offices.
        $url = '/head-offices/' . $doc->office_id . '/documents/' . $doc->id;
        $this->actingAs($staff)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->staff('Nora Nobody', 'applicant-index'))->get($url)->assertForbidden();
        $this->assertSame('application/pdf', $this->actingAs($staff)
            ->getJson('/head-offices/' . $doc->office_id . '/documents')->json('documents.0.mime_type'));
    }

    public function test_older_public_attachments_still_open_and_paths_outside_the_folders_do_not(): void
    {
        $office = Office::factory()->create();
        $unit = Unit::factory()->create(['office_id' => $office->id]);
        $old = OfficeDocument::create(['office_id' => $office->id, 'document_name' => 'old.pdf',
            'document_path' => $this->legacyFile('old.pdf'), 'document_extension' => 'pdf']);
        $unitDoc = UnitDocument::create(['unit_id' => $unit->id, 'document_name' => 'u.pdf',
            'document_path' => $this->legacyFile('u.pdf'), 'document_extension' => 'pdf']);
        $sneaky = OfficeDocument::create(['office_id' => $office->id, 'document_name' => 'x',
            'document_path' => 'uploads/docs/../../.env', 'document_extension' => 'txt']);
        $viewer = $this->staff('Vic Viewer', 'office-view', 'unit-view');

        $this->actingAs($viewer)->get("/head-offices/{$office->id}/documents/{$old->id}")->assertOk();
        $this->actingAs($viewer)->get("/units/{$unit->id}/documents/{$unitDoc->id}")->assertOk();
        $this->actingAs($viewer)->get("/head-offices/{$office->id}/documents/{$sneaky->id}")->assertNotFound();
    }

    public function test_the_move_command_moves_public_attachments_into_private_storage(): void
    {
        $office = Office::factory()->create();
        $unit = Unit::factory()->create(['office_id' => $office->id]);
        $a = OfficeDocument::create(['office_id' => $office->id, 'document_name' => 'a.pdf',
            'document_path' => $this->legacyFile('a.pdf', '%PDF a'), 'document_extension' => 'pdf']);
        $b = UnitDocument::create(['unit_id' => $unit->id, 'document_name' => 'b.docx',
            'document_path' => $this->legacyFile('b.docx', 'PK b'), 'document_extension' => 'docx']);
        $b->delete();                                                     // soft-deleted ones move too
        $gone = OfficeDocument::create(['office_id' => $office->id, 'document_name' => 'gone.pdf',
            'document_path' => 'uploads/docs/2026/10/28/gone.pdf', 'document_extension' => 'pdf']);
        DB::table('offices_documents')->update(['updated_at' => '2026-10-28 10:00:00']);

        $this->artisan('documents:move-to-private', ['--dry-run' => true])->expectsOutputToContain('Would move 2 attachment(s)')
            ->assertSuccessful();
        $this->artisan('documents:move-to-private')->expectsOutputToContain('Moved 2 attachment(s)')
            ->expectsOutputToContain("offices_documents #{$gone->id}: uploads/docs/2026/10/28/gone.pdf")->assertSuccessful();

        $newA = DB::table('offices_documents')->find($a->id);
        $this->assertMatchesRegularExpression('#^private/documents/2026/10/[a-z0-9]{40}\.pdf$#', $newA->document_path);
        $this->assertSame('2026-10-28 10:00:00', (string) $newA->updated_at);
        $this->assertSame('%PDF a', file_get_contents(storage_path('app/' . $newA->document_path)));
        $this->assertStringStartsWith('private/documents/', DB::table('units_documents')->find($b->id)->document_path);
        $this->assertFileDoesNotExist(public_path('uploads/docs/2026/10/28/a.pdf'));
        $this->assertSame('uploads/docs/2026/10/28/gone.pdf', $gone->fresh()->document_path);

        $this->actingAs($this->staff('Vic Viewer', 'office-view'))->get("/head-offices/{$office->id}/documents/{$a->id}")->assertOk();
        $this->artisan('documents:move-to-private')->expectsOutputToContain('Moved 0 attachment(s)')->assertSuccessful();
    }

    public function test_the_purge_command_deletes_private_and_public_files_of_expired_attachments(): void
    {
        $office = Office::factory()->create();
        $private = 'private/documents/2026/10/' . str_repeat('c', 40) . '.pdf';
        File::ensureDirectoryExists(dirname(storage_path('app/' . $private)));
        file_put_contents(storage_path('app/' . $private), '%PDF c');
        $c = OfficeDocument::create(['office_id' => $office->id, 'document_name' => 'c.pdf', 'document_path' => $private]);
        $d = OfficeDocument::create(['office_id' => $office->id, 'document_name' => 'd.pdf',
            'document_path' => $this->legacyFile('d.pdf')]);
        DB::table('offices_documents')->update(['deleted_at' => now()->subDays(4)]);

        $this->artisan('attachments:purge-deleted')->expectsOutputToContain('Permanently purged 2 attachment(s).')
            ->assertSuccessful();
        $this->assertFileDoesNotExist(storage_path('app/' . $private));
        $this->assertFileDoesNotExist(public_path('uploads/docs/2026/10/28/d.pdf'));
        $this->assertSame(0, DB::table('offices_documents')->whereIn('id', [$c->id, $d->id])->count());
    }
}
