<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * UC-1-127 — the platform admin's single-transfer detail read at
 * GET /api/v1/admin/ownership-transfers/{requestId}: cross-tenant, unscoped by
 * company, returning the forensic admin resource and recording ONE audit_trail
 * row on 200, none on 404.
 *
 * Split from ReviewOwnershipTransferQueueTest by seam (single-record lookup vs
 * paginated collection) — the same split Task 2 made for the tenant reads.
 */
class ReviewOwnershipTransferAsAdminTest extends OwnershipTransferAdminTestCase
{
    // --- Sad ---

    #[Test]
    public function when_no_token_is_presented_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given a transfer exists and nobody is authenticated
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the detail endpoint is called as a guest
        $response = $this->getAdminTransfer(null, $transfer->getKey());

        // Then the request is rejected as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — a tenant role reading its OWN company's transfer through the /admin/
    // path still gets the indistinguishable 404 (UC-1-127 error-flow 2a).
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_a_tenant_role_calls_the_admin_detail_endpoint_then_an_indistinguishable_not_found_is_returned(string $roleCode): void
    {
        // Given a tenant member whose own company holds the transfer, while the allowlist names a different user
        [$company, $member] = $this->aCompanyWithMember($roleCode);
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $this->allowlist([(string) Str::uuid()]);

        // When that member reads it via the admin path
        $response = $this->getAdminTransfer($member, $transfer->getKey());

        // Then the response is the indistinguishable generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — an allowlisted admin asks for an id that exists nowhere: the generic
    // 404, and NO audit row, because the find throws BEFORE the insert.
    #[Test]
    public function when_the_transfer_id_is_unknown_then_not_found_is_returned_and_no_read_is_recorded(): void
    {
        // Given an allowlisted platform admin and a transfer that DOES exist —
        // present deliberately, so this pins "the lookup honours the id" and not
        // merely "an empty table returns nothing" (the weaker claim an empty
        // fixture would have made; caught by revert-check)
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When they ask for a DIFFERENT, unknown id
        $response = $this->getAdminTransfer($admin, (string) Str::uuid());

        // Then the response is the generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — a malformed path segment is refused the same way as any unknown id.
    // The route carries no whereUuid constraint, so this guards the no-leak
    // posture against a future column-type or driver change turning a bad
    // segment into a 500 that leaks a driver message.
    #[Test]
    public function when_the_path_segment_is_malformed_then_not_found_is_returned_and_no_read_is_recorded(): void
    {
        // Given an allowlisted platform admin
        $admin = $this->anAllowlistedAdmin();

        // When they ask for an id that is not even a uuid
        $response = $this->getAdminTransfer($admin, 'not-a-uuid');

        // Then the response is the generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — the no-leak claim stated as BYTE-IDENTITY rather than as two separate
    // structural checks: a denied caller and an unknown id must be
    // indistinguishable. assertJsonStructure is a subset check and would tolerate
    // an extra key (a reason, a required_role, a debug hint) appearing on one
    // path but not the other; comparing the two bodies directly cannot.
    #[Test]
    public function when_a_caller_is_denied_then_the_body_is_identical_to_an_unknown_id(): void
    {
        // Given a real transfer, a tenant member who may not see it, and an admin
        [$company, $member] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $admin = User::factory()->create();

        // When the denied member asks for the real id, and an allowlisted admin asks for an unknown one
        $this->allowlist([(string) Str::uuid()]);
        $denied = $this->getAdminTransfer($member, $transfer->getKey());
        $this->allowlist([$admin->getKey()]);
        $unknown = $this->getAdminTransfer($admin, (string) Str::uuid());

        // Then the two bodies are identical once the per-request correlation id is set aside
        $this->assertSame(
            Arr::except($denied->json(), 'correlation_id'),
            Arr::except($unknown->json(), 'correlation_id'),
        );
    }

    // --- Edge ---

    // Edge — cross-tenant is the point: an admin who is also a tenant member
    // reads a DIFFERENT company's transfer, with no company scope on the lookup.
    #[Test]
    public function when_the_admin_also_belongs_to_a_tenant_then_another_companys_transfer_is_still_readable(): void
    {
        // Given an allowlisted admin who owns company X, and a transfer belonging to company Y
        [, $admin] = $this->aCompanyWithMember('owner');
        $this->allowlist([$admin->getKey()]);
        [$companyY] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($companyY, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the admin reads company Y's transfer
        $response = $this->getAdminTransfer($admin, $transfer->getKey());

        // Then it is returned
        $response->assertOk();
        $response->assertJsonPath('data.id', $transfer->getKey());
    }

    // Edge — the detail read is state-agnostic: terminal transfers stay fully
    // readable for forensic lookback long after the lifecycle ended.
    #[Test]
    #[DataProvider('provideTerminalStates')]
    public function when_the_transfer_is_in_a_terminal_state_then_it_is_still_readable(OwnershipTransferStatus $state): void
    {
        // Given an allowlisted admin and a transfer in a terminal state
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), $state);

        // When the admin reads it
        $response = $this->getAdminTransfer($admin, $transfer->getKey());

        // Then it is returned with its stored terminal state
        $response->assertOk();
        $response->assertJsonPath('data.status', $state->value);
    }

    // --- Happy ---

    // Happy — the full forensic contract, locked byte-for-byte: every designed
    // key present, the verifier material and raw ids the owner resource
    // withholds on the wire, the target email unmasked, and the not-yet-built
    // transition columns (T4/T5/T8) rendering null — the shape Tasks 4, 5 and 8
    // will return on their own 200s.
    #[Test]
    public function when_a_platform_admin_reads_a_transfer_then_the_full_admin_contract_is_returned(): void
    {
        // Given an allowlisted admin and a verified transfer with a known target
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $target = User::factory()->create(['name' => 'Alex Turner', 'email' => 'alex.turner@contoso.test']);
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Verified);
        $owner = User::query()->findOrFail($company->owner_id);

        // When the admin reads it
        $response = $this->getAdminTransfer($admin, $transfer->getKey());

        // Then the full admin envelope is returned exactly
        $response->assertOk();
        $response->assertExactJson(['data' => [
            'id' => $transfer->getKey(),
            'company_id' => $company->getKey(),
            'company' => [
                'id' => $company->getKey(),
                'name' => $company->name,
                'br_number' => $company->br_number,
                'epf_number' => $company->epf_number,
                // Literals, matching the ReviewInvoiceAsAdminTest precedent: the
                // in-memory factory model holds the raw strings, so reading them
                // back through the enum accessor here would assert null while the
                // resource renders the cast value.
                'plan' => 'pro',
                'status' => 'trial',
            ],
            'status' => 'verified',
            'request_channel' => 'app',
            'reason' => $transfer->reason,
            'requested_at' => $transfer->requested_at->toJSON(),
            'requested_by' => ['id' => $owner->getKey(), 'name' => $owner->name, 'email' => $owner->email],
            'target_user' => ['id' => $target->getKey(), 'name' => 'Alex Turner', 'email' => 'alex.turner@contoso.test'],
            'verified_at' => $transfer->verified_at?->toJSON(),
            'verified_by' => $transfer->verified_by,
            'verification_notes' => 'Email confirmation + ID document verified.',
            'verification_artefacts' => null,
            'executed_at' => null,
            'executed_by' => null,
            'old_owner_user_id' => null,
            'new_owner_user_id' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'rejection_source' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_source' => null,
            'cancellation_reason' => null,
            'expired_at' => null,
            'expiry_source' => null,
            'expired_after_days' => null,
            'next_state_transition_at' => $transfer->next_state_transition_at?->toJSON(),
        ]]);
    }

    // Happy — the read is recorded: ONE audit row naming a real entity subject
    // and a real tenant, in contrast with the queue's subject-less row (F3).
    #[Test]
    public function when_a_platform_admin_reads_a_transfer_then_the_read_is_recorded_in_the_audit_trail(): void
    {
        // Given an allowlisted admin and a tenant's transfer
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the admin reads it
        $response = $this->getAdminTransfer($admin, $transfer->getKey());

        // Then exactly one audit row records who read what, and when
        $response->assertOk();
        $this->assertTransferReadAudited($transfer, $admin, $response);
    }
}
