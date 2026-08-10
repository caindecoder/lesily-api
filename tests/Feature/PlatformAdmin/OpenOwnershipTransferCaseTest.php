<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class OpenOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
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
    public function when_an_unauthenticated_caller_attempts_to_open_a_case_then_the_request_is_rejected(): void
    {
        // Given no authenticated caller at all
        // When the open-case endpoint is called as a guest
        $response = $this->openCase(null, $this->validPayload());

        // Then the request is rejected as unauthenticated and nothing is written
        $this->assertUnauthenticated($response);
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
        // Given a live member of a DIFFERENT company — membership elsewhere
        // must not satisfy the named company's rule (the discriminating
        // arrange: only the company_id scoping can reject this user)
        $foreignMember = $this->aCompanyMember(Company::factory()->create(), 'employee');

        // When the admin proposes them as the incoming owner
        $response = $this->openCase($this->admin, $this->validPayload([
            'newOwnerUserId' => $foreignMember->getKey(),
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
        $this->assertCaseOpenAudited($this->admin, (string) $response->json('data.id'), $response);
    }

    #[Test]
    public function when_a_platform_admin_opens_a_case_for_a_company_then_the_case_is_on_the_record(): void
    {
        // Given company Contoso and Alex, a live member proposed as incoming owner
        $alex = $this->aCompanyMember($this->company, 'employee');

        // When the platform admin opens the case naming Alex
        $response = $this->openCase($this->admin, $this->validPayload([
            'newOwnerUserId' => $alex->getKey(),
        ]));

        // Then the case is on the record, exactly one audit row names the
        // admin, and nothing about the company changed
        $this->assertCaseOpened($response, ['new_owner_user_id' => $alex->getKey()]);
        $this->assertCaseOpenAudited($this->admin, (string) $response->json('data.id'), $response);
        $this->assertCompanyUntouched($this->company, $alex);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCasePayloadProvider(): array
    {
        return [
            'null companyId' => [['companyId' => null], 'companyId'],
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
     * The case is on the record — 201 with the created case as the FULL admin
     * contract, locked byte-for-byte (§8.2 data envelope, snake_case response
     * keys per the built Resource convention — exact-envelope leak-guard, the
     * ReviewInvoiceAsAdminTest idiom), and exactly ONE open case row carrying
     * the shared company + the verbatim context plus $expected's overrides.
     */
    protected function assertCaseOpened(TestResponse $response, array $expected): void
    {
        $response->assertCreated();
        $this->assertDatabaseCount('ownership_transfer_cases', 1);
        $this->assertDatabaseHas('ownership_transfer_cases', [
            'company_id' => $this->company->getKey(),
            'status' => 'open',
            'closed_outcome' => null,
            'requested_context' => $this->validPayload()['requestedContext'],
            ...$expected,
        ]);

        $case = OwnershipTransferCase::query()->sole();
        $response->assertExactJson(['data' => [
            'id' => $case->getKey(),
            'company_id' => $case->company_id,
            'status' => 'open',
            'closed_outcome' => null,
            'requested_context' => $case->requested_context,
            'new_owner_user_id' => $case->new_owner_user_id,
            'verified_at' => null,
            'verified_by' => null,
            'created_at' => $case->created_at->toISOString(),
        ]]);
    }

    /**
     * Exactly ONE audit row records the open, in the cd-audit-trail
     * Direct-write shape (stored_event_id NULL — opening a case records no
     * domain event; metadata NULL — an open has no query context to record,
     * unlike the queue read's row, which carries validated
     * {filters, result_count, page}), actor + subject + description pinned to
     * the sibling assertAdminReviewAuditRecorded standard, and the
     * request-context columns IDENTITY-pinned (ip = the test client's
     * loopback, request_id = the very Correlation-Id the response carries) —
     * presence-only asserts could not catch the interactor threading nulls.
     */
    protected function assertCaseOpenAudited(User $actor, string $caseId, TestResponse $response): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferCaseOpened',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $caseId,
            'subject_label' => null,
            'action' => 'case_opened',
            'description' => 'Platform admin opened an ownership-transfer case.',
            'company_id' => $this->company->getKey(),
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $actor->name,
            'stored_event_id' => null,
            'metadata' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $entry = AuditEntry::query()->sole();
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        $this->assertNotNull($entry->user_agent);
    }

    /**
     * Opening a case changed nothing about the company (UC-1-140
     * postcondition): the owner column still names the pre-act owner, the
     * company's stream recorded no domain event, and the proposed owner's
     * pivot role is untouched — a case is a record, never a transfer.
     */
    protected function assertCompanyUntouched(Company $company, User $proposed): void
    {
        $ownerBefore = $company->owner_id;
        $this->assertSame($ownerBefore, $company->refresh()->owner_id);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->count());

        $this->assertSame(
            $this->seededRole('employee')->getKey(),
            CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $proposed->getKey())
                ->sole()
                ->role_id,
        );
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
