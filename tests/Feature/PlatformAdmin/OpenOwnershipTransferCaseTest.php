<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OpenOwnershipTransferCaseTest extends PlatformAdminTestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seedRbacCatalog();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->allowlist([$this->admin->getKey()]);
    }

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_open_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a tenant Owner outside the platform-admin allowlist
        $owner = $this->aCompanyMember($this->company, 'owner');

        // When they call the open-case endpoint with a valid payload
        $response = $this->openCase($owner, $this->validPayload());

        // Then the admin surface is never acknowledged and nothing is written
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoCaseOpened();
    }

    #[Test]
    #[DataProvider('invalidCasePayloadProvider')]
    public function when_the_case_payload_is_invalid_then_the_case_is_refused(array $overrides, string $field): void
    {
        // Given an allowlisted platform admin
        // When they submit a payload carrying this row's defect
        $response = $this->openCase($this->admin, $this->validPayload($overrides));

        // Then the case is refused naming the offending field and nothing is written
        $this->assertValidationFailed($response, $field);
        $this->assertNoCaseOpened();
    }

    #[Test]
    public function when_the_proposed_incoming_owner_is_not_a_member_of_the_company_then_the_case_is_refused(): void
    {
        // Given a user with no membership in the named company
        $outsider = User::factory()->create();

        // When the admin proposes them as the incoming owner
        $response = $this->openCase($this->admin, $this->validPayload([
            'newOwnerUserId' => $outsider->getKey(),
        ]));

        // Then the case is refused on the proposed owner and nothing is written
        $this->assertValidationFailed($response, 'newOwnerUserId');
        $this->assertNoCaseOpened();
    }

    #[Test]
    public function when_the_proposed_incoming_owner_holds_only_a_removed_membership_then_the_case_is_refused(): void
    {
        // Given a user whose membership in the company was removed
        $removed = $this->aCompanyMember($this->company, 'employee');
        $this->company->users()->updateExistingPivot($removed->getKey(), ['deleted_at' => now()]);

        // When the admin proposes them as the incoming owner
        $response = $this->openCase($this->admin, $this->validPayload([
            'newOwnerUserId' => $removed->getKey(),
        ]));

        // Then the case is refused — an active member means a live pivot
        $this->assertValidationFailed($response, 'newOwnerUserId');
        $this->assertNoCaseOpened();
    }

    #[Test]
    public function when_the_audit_write_fails_then_no_case_row_survives(): void
    {
        // Given the audit write will fail
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin opens a case
        $response = $this->openCase($this->admin, $this->validPayload());

        // Then the whole transaction rolls back — a case exists only alongside its audit row
        $this->assertOpenFailedSafely($response);
        $this->assertNoCaseOpened();
    }

    #[Test]
    public function when_the_incoming_owner_is_not_yet_known_then_the_case_opens_without_one(): void
    {
        // Given the incoming owner is not yet known
        // When the admin opens a case with only the company and the context
        $response = $this->openCase($this->admin, $this->validPayload());

        // Then the case is open with no incoming owner and the audit row is present
        $this->assertCaseOpened($response, ['new_owner_user_id' => null]);
        $this->assertCaseOpenAudited($this->admin, (string) $response->json('data.id'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCasePayloadProvider(): array
    {
        return [
            'missing companyId' => [['companyId' => null], 'companyId'],
            'unknown companyId' => [['companyId' => '01980000-0000-7000-8000-000000000000'], 'companyId'],
            'empty requestedContext' => [['requestedContext' => ''], 'requestedContext'],
            'overlong requestedContext' => [['requestedContext' => str_repeat('a', 1001)], 'requestedContext'],
            'malformed newOwnerUserId' => [['newOwnerUserId' => 'not-a-uuid'], 'newOwnerUserId'],
        ];
    }

    /**
     * Call the REAL admin open-case endpoint (UC-1-140) as $caller, or as a
     * guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design, with no auth.company
     * in its chain (cd-foundation-http §3).
     */
    protected function openCase(?User $caller, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson('/api/v1/admin/ownership-transfer-cases', $body);
    }

    /**
     * A shape-valid open-case body for the shared company; rows override or
     * remove keys to arrange their defect.
     */
    protected function validPayload(array $overrides = []): array
    {
        return [
            'companyId' => $this->company->getKey(),
            'requestedContext' => 'Owner deceased; brother called support claiming next-of-kin, death certificate offered.',
            ...$overrides,
        ];
    }

    /**
     * The case is on the record — 201 with the created case in the §8.2 data
     * envelope, exactly ONE open case row carrying the shared company + the
     * verbatim context plus $expected's overrides.
     */
    protected function assertCaseOpened(TestResponse $response, array $expected): void
    {
        $response->assertCreated();
        $this->assertNotNull($response->json('data.id'));
        $this->assertDatabaseCount('ownership_transfer_cases', 1);
        $this->assertDatabaseHas('ownership_transfer_cases', [
            'company_id' => $this->company->getKey(),
            'status' => 'open',
            'closed_outcome' => null,
            'requested_context' => $this->validPayload()['requestedContext'],
            ...$expected,
        ]);
    }

    /**
     * Exactly ONE audit row records the open, in the cd-audit-trail
     * Direct-write shape (stored_event_id NULL — opening a case records no
     * domain event), actor + subject identity-pinned.
     */
    protected function assertCaseOpenAudited(User $actor, string $caseId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferCaseOpened',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $caseId,
            'action' => 'case_opened',
            'company_id' => $this->company->getKey(),
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'stored_event_id' => null,
            'severity' => 'info',
        ]);
    }

    /**
     * The open failed safely — generic 500 §S21 envelope, no internal details
     * (debug is off in setUp, so the production Throwable catch-all renders).
     */
    protected function assertOpenFailedSafely(TestResponse $response): void
    {
        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
    }

    /**
     * Nothing was opened — zero case rows and zero audit rows (the UC-1-140
     * refusal invariant: no case without its audit evidence, no evidence
     * without a case).
     */
    protected function assertNoCaseOpened(): void
    {
        $this->assertDatabaseCount('ownership_transfer_cases', 0);
        $this->assertNoAuditRecorded();
    }
}
