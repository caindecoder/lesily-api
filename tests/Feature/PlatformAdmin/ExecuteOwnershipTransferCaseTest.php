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
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ExecuteOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_execute_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the execute endpoint is called as a guest
        $response = $this->executeCase(null, (string) Str::uuid7());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_execute_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given tenant Owner Olive and HER OWN company's verified case
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $case = $this->aVerifiedCaseFor($company);

        // When she calls the execute endpoint for that case
        $response = $this->executeCase($owner, $case->getKey());

        // Then the admin surface is never acknowledged and nothing moved
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    public function when_the_case_id_is_unknown_then_nothing_is_found_and_no_execution_is_recorded(): void
    {
        // Given an allowlisted admin and no case at all
        // When they execute an unknown case id
        $response = $this->executeCase($this->admin, (string) Str::uuid7());

        // Then nothing is found and nothing is audited (find-before-audit)
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_idempotency_key_is_missing_then_the_execution_is_refused(): void
    {
        // Given a verified case an allowlisted admin could otherwise execute
        $company = Company::factory()->create();
        $case = $this->aVerifiedCaseFor($company);

        // When the execute is submitted without an Idempotency-Key
        $response = $this->executeCaseWithoutIdempotencyKey($this->admin, $case->getKey());

        // Then the execution is refused as missing its key and nothing moved
        $this->assertRefusedAsMissingIdempotencyKey($response);
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    /**
     * Call the REAL admin execute endpoint (UC-1-093) as $actor, or as a guest
     * when null. Every call carries an Idempotency-Key, minted fresh unless
     * the row passes its own (§13.2 REQUIRED mount); deliberately NO
     * X-Company-Context — the /api/v1/admin/* family is cross-tenant by
     * design (cd-foundation-http §3).
     */
    protected function executeCase(?User $actor, string $caseId, ?string $idempotencyKey = null): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->patchJson(
            "/api/v1/admin/ownership-transfer-cases/{$caseId}/execute",
            [],
            ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid7()],
        );
    }

    /**
     * The §13.2 REQUIRED mount refused the keyless mutation — 422 naming the
     * missing key in the §S21 envelope (the EnsureIdempotencyKeyTest contract;
     * this row pins only that THIS route mounts it).
     */
    protected function assertRefusedAsMissingIdempotencyKey(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    /**
     * The execute endpoint called WITHOUT any Idempotency-Key header — the
     * row-4 omission form of executeCase.
     */
    protected function executeCaseWithoutIdempotencyKey(?User $actor, string $caseId): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfer-cases/{$caseId}/execute");
    }

    /**
     * Nothing was executed — the case row is exactly as arranged, the
     * company's stream carries no CompanyOwnershipTransferred event, no audit
     * row exists, and the company's owner column still names $ownerUserId
     * (the refusal invariant: every refusal leaves the world untouched).
     */
    protected function assertNothingExecuted(OwnershipTransferCase $case, string $ownerUserId): void
    {
        $this->assertCaseUnchanged($case);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->count());

        $this->assertNoAuditRecorded();

        $this->assertSame($ownerUserId, Company::query()->whereKey($case->company_id)->sole()->owner_id);
    }
}
