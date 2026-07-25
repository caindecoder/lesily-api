<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * SM-1-05 T3 + T5 — a platform admin ends an in-flight transfer with a reasoned
 * rejection, from either Requested (pre-verification) or Verified
 * (execution-time) (UC-1-128, slice #11 Task 5). One behavior, one seam, one
 * actor, so both windows, the illegal-transition refusals and the input
 * rejections all live here, ordered Sad → Edge → Happy (§2.14 Rule A).
 *
 * Unlike its verify sibling there is NO legal-hold guard: a hold freezes
 * ownership CHANGES (§S10b), and a rejection changes no ownership and touches no
 * company_user pivot row (s151 ruling H4).
 */
class RejectOwnershipTransferTest extends OwnershipTransferAdminTestCase
{
    #[Test]
    public function when_the_rejection_is_unauthenticated_then_it_is_refused(): void
    {
        // Given a transfer awaiting review
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When a guest attempts to reject it
        $response = $this->rejectTransfer(null, $transfer->getKey());

        // Then the request is refused and the transfer is untouched
        $this->assertUnauthenticated($response);
        $this->assertTransferStillOpen($transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * Nothing was recorded and nothing moved: the stream carries no event at all
     * (the fixture is projected directly, so an empty stream IS the baseline),
     * no access was logged, and the row still sits in its original state. The
     * trio is what makes each refusal test prove a non-event rather than just a
     * status code.
     */
    private function assertTransferStillOpen(string $requestId, OwnershipTransferStatus $expected): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertNoAuditRecorded();
        $this->assertSame(
            $expected,
            OwnershipTransferRequest::query()->findOrFail($requestId)->status,
        );
    }
}
