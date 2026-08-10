<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VerifyOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_verify_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the verify endpoint is called as a guest
        $response = $this->verifyCase(null, (string) Str::uuid7());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    /**
     * Call the REAL admin verify endpoint (UC-1-113) as $caller, or as a
     * guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design (cd-foundation-http §3).
     */
    protected function verifyCase(?User $caller, string $caseId, array $payload = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson('/api/v1/admin/ownership-transfer-cases/'.$caseId.'/verify', $payload);
    }
}
