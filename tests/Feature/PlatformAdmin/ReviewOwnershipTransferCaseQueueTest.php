<?php

namespace Tests\Feature\PlatformAdmin;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReviewOwnershipTransferCaseQueueTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_queue_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the case queue is requested as a guest
        $response = $this->listCases(null);

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_requests_the_case_queue_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a tenant Owner outside the allowlist whose company has an open case
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $this->anOpenCaseFor($company);

        // When she requests the case queue
        $response = $this->listCases($owner);

        // Then the admin surface is never acknowledged — not even her own
        // company's case — and nothing is audited
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }
}
