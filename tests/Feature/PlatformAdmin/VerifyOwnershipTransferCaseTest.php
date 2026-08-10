<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\OwnershipTransferCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_verify_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given Owner Olive of Contoso and an open case for HER OWN company
        $company = Company::factory()->create();
        $olive = $this->aCompanyMember($company, 'owner');
        $case = $this->anOpenCaseFor($company);

        // When Olive calls the verify endpoint for her company's case
        $response = $this->verifyCase($olive, $case->getKey());

        // Then the admin surface is never acknowledged and nothing is written
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingVerified($case);
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

    /**
     * The refusal left the case exactly as arranged (whatever state that
     * was), recorded no domain event on the case's company stream, and wrote
     * no audit row — the UC-1-113 fail-closed invariant.
     */
    protected function assertNothingVerified(OwnershipTransferCase $case): void
    {
        $this->assertCaseUnchanged($case);

        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertEquals($case->verified_at, $fresh->verified_at);
        $this->assertSame($case->verification_event_id, $fresh->verification_event_id);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->count());

        $this->assertNoAuditRecorded();
    }
}
