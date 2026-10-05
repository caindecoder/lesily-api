<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class WithdrawDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_a_guest_withdraws_a_request_then_they_are_refused_as_unauthenticated(): void
    {
        // Given
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When
        $response = $this->withdrawDsarRequestAsGuest($request->getKey());

        // Then
        $this->assertUnauthenticated($response);
        $this->assertStillInProgress($request);
    }

    #[Test]
    #[DataProvider('provideUnknownRequestIds')]
    public function when_the_request_id_is_unknown_then_the_withdrawal_is_not_found(string $unknownId): void
    {
        // Given a member with a request of their own
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When
        $response = $this->withdrawDsarRequest($member, $this->company, $unknownId);

        // Then
        $this->assertHiddenAsNotFound($response, $unknownId);
        $this->assertStillInProgress($request);
    }
}
