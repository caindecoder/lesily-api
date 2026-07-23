<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * UC-1-127 — Review Ownership Transfer Queue (slice #11 Task 3): the platform
 * admin's cross-tenant triage queue at GET /api/v1/admin/ownership-transfers
 * (auth:sanctum + platform.admin, deliberately NO auth.company), ordered
 * soonest-deadline-first (F1), defaulting to the open states (F5), and
 * recording ONE platform-level audit_trail row per read (F3).
 */
class ReviewOwnershipTransferQueueTest extends OwnershipTransferAdminTestCase
{
    // --- Sad ---

    #[Test]
    public function when_no_token_is_presented_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given nobody is authenticated

        // When the admin queue is requested as a guest
        $response = $this->getAdminQueue(null);

        // Then the request is rejected as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — the admin surface never acknowledges its existence to tenant-side
    // callers: every seeded tenant role, even one whose OWN company holds a
    // transfer, gets the byte-identical generic 404 and the read is never
    // recorded (UC-1-127 error-flow 2a + Gherkin sc.2/sc.4).
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_a_tenant_role_calls_the_admin_queue_then_an_indistinguishable_not_found_is_returned(string $roleCode): void
    {
        // Given a tenant member whose own company holds a transfer, while the allowlist names a different user
        [$company, $member] = $this->aCompanyWithMember($roleCode);
        $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $this->allowlist([(string) Str::uuid()]);

        // When that member calls the admin queue
        $response = $this->getAdminQueue($member);

        // Then the response is the indistinguishable generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — the query whitelist refuses anything it does not model, and the read
    // never happens, so nothing is recorded (UC-1-127 error-flows 4h/4i).
    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueryInputs')]
    public function when_the_query_input_is_invalid_then_it_is_rejected(array $query, string $invalidField): void
    {
        // Given an allowlisted platform admin
        $admin = $this->anAllowlistedAdmin();

        // When they call the queue with an unsupported query parameter
        $response = $this->getAdminQueue($admin, $query);

        // Then it is refused as shape-invalid on the offending field and no read is recorded
        $this->assertValidationRejected($response, $invalidField);
        $this->assertNoAuditRecorded();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidQueryInputs(): array
    {
        return [
            'unknown filter key' => [['foo' => 'bar'], 'foo'],
            'old status param name' => [['status' => 'requested'], 'status'],
            // ?sort= is deliberately unsupported on the admin queue: ordering is
            // hard-coded soonest-deadline-first (F1), the LD-idiomatic shape.
            'sort is not a queue parameter' => [['sort' => 'requested_at'], 'sort'],
            'non-canonical state' => [['state' => 'completed'], 'state'],
            'company filter is not a uuid' => [['company_id' => 'not-a-uuid'], 'company_id'],
            'per_page over the ceiling' => [['per_page' => 101], 'per_page'],
            'per_page below one' => [['per_page' => 0], 'per_page'],
            'page below one' => [['page' => 0], 'page'],
        ];
    }
}
