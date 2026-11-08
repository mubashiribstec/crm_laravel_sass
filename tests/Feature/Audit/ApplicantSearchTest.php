<?php

namespace Tests\Feature\Audit;

use Tests\Feature\Xplogent\XplogentTestCase;

/**
 * Atif's applicantSearchOptimized (MA-01), merged 8 Nov: the applicants list's search box
 * detects phone / email / postcode / name and uses the new indexes (a generated, indexed
 * applicant_postcode_normalized column and a FULLTEXT index on applicant_name).
 */
class ApplicantSearchTest extends XplogentTestCase
{
    private function search(string $term): array
    {
        $staff = $this->staff('Ann Search', 'applicant-index');
        $res = $this->actingAs($staff)->postJson('/getApplicantsAjaxRequest',
            ['draw' => 1, 'start' => 0, 'length' => 50, 'search' => ['value' => $term, 'regex' => 'false']],
            ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertArrayNotHasKey('error', $res->json(), (string) $res->getContent());

        return collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function test_each_kind_of_search_finds_the_right_applicants(): void
    {
        $sarah = $this->applicant(['applicant_name' => 'Sarah Jones', 'applicant_email' => 'sarah.jones@example.org',
            'applicant_postcode' => 'LS7 3AA', 'applicant_phone' => '07700 900111']);
        $tom = $this->applicant(['applicant_name' => 'Tom Smithson', 'applicant_email' => 'tom_s@example.org',
            'applicant_postcode' => 'KT2 7EG', 'applicant_phone' => '07700 900222']);
        $pct = $this->applicant(['applicant_name' => 'Pat 100% Ready', 'applicant_email' => 'pat@example.org',
            'applicant_postcode' => 'M1 1AA', 'applicant_phone' => '07700 900333']);

        $this->assertSame([$sarah->id], $this->search('07700 900111'));          // phone, with the space
        $this->assertSame([$tom->id], $this->search('900222'));                  // part of a phone
        $this->assertSame([$sarah->id], $this->search('sarah.jones@example.org')); // email
        $this->assertSame([$tom->id], $this->search('tom_s'));                    // email prefix (has "_")
        $this->assertSame([$tom->id], $this->search('KT2 7EG'));                  // postcode with the space
        $this->assertSame([$tom->id], $this->search('kt27eg'));                   // without it, lower case
        $this->assertSame([$sarah->id], $this->search('LS7'));                    // outward code
        // Names use the FULLTEXT index, which only sees committed rows (each test runs in a
        // rolled-back transaction), so check the query: every word as a required prefix.
        $sql = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$sql) { $sql[] = [$q->sql, $q->bindings]; });
        $this->search('sarah jo');
        $match = collect($sql)->first(fn ($q) => str_contains($q[0], 'MATCH (applicants.applicant_name) AGAINST'));
        $this->assertNotNull($match, 'a name search uses the FULLTEXT index');
        $this->assertContains('+sarah*', $match[1]);                              // "jo" is too short for it
        $this->assertSame([], $this->search('a%'));                               // % is literal, not "anything"
        $this->assertCount(3, $this->search(''));                                 // no search: everyone
    }

    public function test_the_normalized_postcode_follows_the_postcode(): void
    {
        $a = $this->applicant(['applicant_postcode' => 'ls6 2ab']);
        $this->assertSame('LS62AB', $a->fresh()->applicant_postcode_normalized);
        $a->update(['applicant_postcode' => 'M1-1AA']);
        $this->assertSame('M11AA', $a->fresh()->applicant_postcode_normalized);
    }
}
