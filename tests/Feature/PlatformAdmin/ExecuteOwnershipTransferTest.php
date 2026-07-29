<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * SM-1-05 T4 — a platform admin executes a verified transfer, moving ownership
 * atomically (UC-1-093, slice #11 Task 8). One behavior, one seam, one actor, so
 * the illegal-transition refusals, the live re-validation guards and the happy
 * swap all live here, ordered Sad → Edge → Happy (§2.14 Rule A).
 *
 * The heaviest increment of the slice: five write surfaces commit together or
 * none do (SD-1-02 §2.2), across four $weight-ordered projectors.
 *
 * Two deliberate asymmetries with the siblings, both s159 rulings:
 * - Unlike reject (H4) and withdraw, a legal hold DOES refuse here (P5): §S10b
 *   freezes ownership CHANGES, and this is the one transition that changes one.
 * - Unlike every other transition, execute re-validates live state before the
 *   aggregate call (P3) — the owner and the target could both have moved since
 *   T1, and the aggregate never reads the database.
 */
class ExecuteOwnershipTransferTest extends OwnershipTransferAdminTestCase
{
    #[Test]
    public function when_the_execution_is_unauthenticated_then_it_is_refused(): void
    {
        // Given a transfer awaiting execution
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState(
            $company,
            User::factory()->create(),
            OwnershipTransferStatus::Requested,
        );

        // When a guest attempts to execute it
        $response = $this->executeTransfer(null, $transfer->getKey());

        // Then the request is refused and the transfer is untouched
        $this->assertUnauthenticated($response);
        $this->assertSame(
            OwnershipTransferStatus::Requested,
            OwnershipTransferRequest::query()->findOrFail($transfer->getKey())->status,
        );
    }

    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_the_caller_is_not_a_platform_admin_then_the_transfer_is_not_found(string $roleCode): void
    {
        // Given a verified transfer and a tenant member of its own company
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->anAllowlistedAdmin());
        $caller = $this->aCompanyMember($company, $roleCode);

        // When that tenant role attempts to execute it
        $response = $this->executeTransfer($caller, $transfer->getKey());

        // Then the admin surface never acknowledges the transfer exists
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(
            OwnershipTransferStatus::Verified,
            $this->currentTransfer($transfer)->status,
        );
    }
}
