<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;

/**
 * UC-1-028 — Request Ownership Transfer (slice #11 Task 1, SM-1-05 T1): the
 * current Owner formally requests transfer of company ownership to another
 * member; the request is queued for platform-support verification before any
 * privilege change happens.
 */
class RequestOwnershipTransferTest extends OwnershipTransfersTestCase
{
    #[Test]
    public function when_the_request_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given no authenticated session

        // When an ownership-transfer request is posted
        $response = $this->postTransferRequest(actor: null, company: null);

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }
}
