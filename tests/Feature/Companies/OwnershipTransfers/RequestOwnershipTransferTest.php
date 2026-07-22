<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
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

    #[Test]
    #[DataProvider('provideInvalidRequestInputs')]
    public function when_the_request_input_is_invalid_then_the_request_is_rejected(array $body, string $invalidField): void
    {
        // Given a company owner
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they post a transfer request with invalid input
        $response = $this->postTransferRequest($owner, $company, $body);

        // Then it is refused as shape-invalid on the offending field
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_the_target_user_is_not_a_member_of_the_company_then_the_request_is_rejected(): void
    {
        // Given a company owner and a user who is not a member of the company
        [$company, $owner] = $this->aCompanyWithOwner();
        $outsider = User::factory()->create();

        // When the owner requests a transfer to the outsider
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($outsider));

        // Then it is refused as an invalid target
        $this->assertValidationRejected($response, 'targetUserId');
    }

    #[Test]
    public function when_the_owner_targets_themselves_then_the_request_is_rejected(): void
    {
        // Given a company owner
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they request a transfer to themselves
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($owner));

        // Then it is refused as an invalid target
        $this->assertValidationRejected($response, 'targetUserId');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidRequestInputs(): array
    {
        return [
            'missing target' => [
                ['reason' => 'Retiring from the business', 'requestChannel' => 'app'],
                'targetUserId',
            ],
            'missing reason' => [
                ['targetUserId' => '0193abcd-9999-7000-8000-000000000001', 'requestChannel' => 'app'],
                'reason',
            ],
            'invalid channel' => [
                ['targetUserId' => '0193abcd-9999-7000-8000-000000000001', 'reason' => 'Retiring', 'requestChannel' => 'email'],
                'requestChannel',
            ],
        ];
    }
}
