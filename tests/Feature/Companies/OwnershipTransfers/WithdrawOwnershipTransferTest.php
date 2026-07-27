<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use PHPUnit\Framework\Attributes\Test;
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
}
