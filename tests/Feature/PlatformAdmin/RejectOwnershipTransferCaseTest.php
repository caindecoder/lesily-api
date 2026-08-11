<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use App\Companies\Entities\OwnershipTransferCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_reject_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given tenant Owner Olive and HER OWN company's open case
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $company->update(['owner_id' => $owner->getKey()]);
        $case = $this->anOpenCaseFor($company);

        // When she calls the reject endpoint for that case
        $response = $this->rejectCase($owner, $case->getKey());

        // Then the admin surface is never acknowledged and nothing was rejected
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRejected($case, $owner->getKey());
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

    /**
     * Nothing was rejected — the case row is exactly as arranged with its
     * rejection columns still empty, no domain event was recorded, no audit
     * row exists, the company's owner column still names $ownerUserId, and
     * nobody else holds an owner-role pivot (a rejection must never move
     * ownership on ANY path — the UC's Minimal Guarantee).
     */
    protected function assertNothingRejected(OwnershipTransferCase $case, string $ownerUserId): void
    {
        $this->assertCaseUnchanged($case);

        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNull($fresh->rejection_reason_code);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->count());

        $this->assertNoAuditRecorded();

        $this->assertSame($ownerUserId, Company::query()->whereKey($case->company_id)->sole()->owner_id);

        $this->assertSame(0, CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('role_id', $this->seededRole('owner')->getKey())
            ->where('user_id', '!=', $ownerUserId)
            ->withTrashed()
            ->count());
    }
}
