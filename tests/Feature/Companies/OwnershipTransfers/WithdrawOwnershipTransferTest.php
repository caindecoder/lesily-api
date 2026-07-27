<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * SM-1-05 T6 + T7 (UC-1-114 pre-verification, UC-1-115 post-verification) — the
 * original requester withdraws their own transfer, from `Requested` or from
 * `Verified`. One endpoint, one Command, one aggregate method; the aggregate
 * picks the window from its own state, exactly as `reject()` does for T3/T5.
 *
 * ONE class for both windows (§2.14): same seam, same actor, same endpoint — the
 * T6-vs-T7 distinction is scenario-level, not a split trigger. Same reasoning
 * that kept both reject windows together at s151; LeaveDates likewise splits its
 * cancel tests by ACTOR (Subordinate / Admin / Approver), never by transition.
 */
class WithdrawOwnershipTransferTest extends OwnershipTransfersTestCase
{
    #[Test]
    public function when_the_withdrawal_is_unauthenticated_then_it_is_refused(): void
    {
        // Given an open transfer belonging to a company
        [$company] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When it is withdrawn with no session at all
        $response = $this->withdrawTransferAsGuest((string) $transfer->getKey());

        // Then the request never reaches the use case
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    /**
     * The UC's whole "Denied Roles" line in one row. The designated TARGET user
     * is refused here too: they hold an ordinary member role, and only `owner`
     * carries `ownership-transfer.withdraw` (matrix Module 8 after the J1
     * collapse). Withdrawal is the requester's authority, never the target's.
     */
    #[Test]
    #[DataProvider('provideRolesWithoutWithdrawPermission')]
    public function when_the_caller_lacks_the_withdraw_permission_then_the_transfer_is_not_withdrawn(string $roleCode): void
    {
        // Given an open transfer and a member holding some other role
        [$company] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);
        $caller = $this->aMemberWithRole($company, $roleCode);

        // When that member tries to withdraw it
        $response = $this->withdrawTransfer([$company, $caller], (string) $transfer->getKey());

        // Then the permission gate refuses and the transfer is untouched
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an owner with no transfer under the requested id
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they withdraw an id that names nothing
        $response = $this->withdrawTransfer([$company, $owner], (string) Str::uuid7());

        // Then the absence is reported as a plain not-found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    /**
     * An owner may hold `ownership-transfer.withdraw` and still not reach
     * another tenant's transfer: the id is existence-hidden, so a foreign
     * transfer and an absent one are byte-identical on the wire.
     */
    #[Test]
    public function when_the_transfer_belongs_to_another_company_then_it_is_not_found(): void
    {
        // Given an owner, and an open transfer at a different company
        [$company, $owner] = $this->aCompanyWithOwner();
        $foreign = $this->aBystanderTransferAtAnotherCompany();

        // When the owner withdraws the foreign transfer through their own context
        $response = $this->withdrawTransfer([$company, $owner], (string) $foreign->getKey());

        // Then it is refused as absent, and the foreign row is untouched
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertTransferStillOpen((string) $foreign->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * Every seeded company role except `owner`.
     *
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutWithdrawPermission(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    /**
     * The transfer is still in $expected, and no terminal columns were written —
     * a refusal that leaves the row alone is the assertion, not merely the
     * status code.
     */
    private function assertTransferStillOpen(string $requestId, OwnershipTransferStatus $expected): void
    {
        $this->assertDatabaseHas('ownership_transfer_requests', [
            'id' => $requestId,
            'status' => $expected->value,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_source' => null,
        ]);
    }
}
