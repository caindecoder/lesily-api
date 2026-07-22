<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    #[DataProvider('provideNonOwnerRoles')]
    public function when_the_actor_is_not_the_owner_then_the_request_is_denied(string $roleCode): void
    {
        // Given a company member holding a non-owner seeded role
        $company = Company::factory()->create();
        $actor = $this->aMemberWithRole($company, $roleCode);

        // When they post an ownership-transfer request
        $response = $this->postTransferRequest($actor, $company);

        // Then it is denied for lacking the owner-only request permission
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonOwnerRoles(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
        ];
    }
}
