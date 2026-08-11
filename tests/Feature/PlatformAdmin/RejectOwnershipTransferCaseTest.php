<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RejectOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_reject_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the reject endpoint is called as a guest
        $response = $this->rejectCase(null, (string) Str::uuid7());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    /**
     * Call the REAL admin reject endpoint (UC-1-128) as $actor, or as a guest
     * when null. The default payload is a valid rejection; rows override or
     * strip keys. Deliberately NO X-Company-Context — the /api/v1/admin/*
     * family is cross-tenant by design (cd-foundation-http §3).
     */
    protected function rejectCase(?User $actor, string $caseId, array $payload = [
        'reason' => 'Requester could not evidence the claimed relationship',
        'reasonCode' => 'identity_failed',
    ]): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfer-cases/{$caseId}/reject", $payload);
    }
}
