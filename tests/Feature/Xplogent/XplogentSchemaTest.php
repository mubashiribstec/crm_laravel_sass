<?php

namespace Tests\Feature\Xplogent;

use Illuminate\Support\Facades\Schema;

/** Indexes the Xplogent API's idempotency depends on. */
class XplogentSchemaTest extends XplogentTestCase
{
    /** X-19: call-summary dedupes on call_id; X-08: send-job-details dedupes on call + applicant + sale. */
    public function test_the_idempotency_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('xplogent_call_events', ['call_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('sent_emails', ['xplogent_call_id', 'applicant_id', 'sale_id'], 'unique'));
    }
}
