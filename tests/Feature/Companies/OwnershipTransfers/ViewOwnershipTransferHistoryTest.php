<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;

/**
 * UC-1-126 — View Ownership Transfer History (slice #11 Task 2): the company's
 * Owner / Admin / Auditor read the paginated, newest-first list of every
 * ownership-transfer record for their company (all states incl. terminals),
 * tenant-scoped, with a `?state=` filter.
 */
class ViewOwnershipTransferHistoryTest extends OwnershipTransfersTestCase
{
    // --- Sad ---

    #[Test]
    public function when_the_request_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given no authenticated session

        // When the transfer history is requested
        $response = $this->getTransferHistoryAsGuest();

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }
}
