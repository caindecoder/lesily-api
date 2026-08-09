<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReviewOwnershipTransferCaseAsAdminTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_detail_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When a case detail is requested as a guest
        $response = $this->showCase(null, (string) Str::uuid());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_requests_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a tenant Owner outside the allowlist and her company's own case
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $case = $this->anOpenCaseFor($company);

        // When she requests that case by id
        $response = $this->showCase($owner, $case->getKey());

        // Then even her own company's case is not acknowledged and nothing is audited
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }
}
