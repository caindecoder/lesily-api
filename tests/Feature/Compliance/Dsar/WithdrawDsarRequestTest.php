<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;

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

    #[Test]
    public function when_the_request_belongs_to_another_company_then_the_withdrawal_is_not_found(): void
    {
        // Given a member of both companies whose own request lives in the other one
        $member = $this->aCompanyMember($this->company);
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = $this->aSubmittedRequest($otherCompany, $member);

        // When they withdraw it from this company's context
        $response = $this->withdrawDsarRequest($member, $this->company, $request->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $request->getKey());
        $this->assertStillInProgress($request);
    }

    #[Test]
    public function when_a_member_cannot_see_the_request_then_the_withdrawal_is_not_found(): void
    {
        // Given another member's own request
        $request = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));

        // When an employee who neither made it nor is its subject withdraws it
        $response = $this->withdrawDsarRequest($this->aCompanyMember($this->company), $this->company, $request->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $request->getKey());
        $this->assertStillInProgress($request);
    }

    #[Test]
    #[DataProvider('provideVisibleNonRequesters')]
    public function when_a_member_who_did_not_make_the_request_withdraws_it_then_they_are_refused_as_not_the_requester(string $kind): void
    {
        // Given a request the actor can see but did not make
        $subject = $this->aCompanyMember($this->company);
        [$request, $actor] = $kind === 'subject'
            ? [$this->aSubmittedRequest($this->company, $subject, $this->aCompanyMember($this->company, 'admin')), $subject]
            : [$this->aSubmittedRequest($this->company, $subject), $this->aCompanyMember($this->company, $kind)];

        // When
        $response = $this->withdrawDsarRequest($actor, $this->company, $request->getKey());

        // Then
        $this->assertRefusedAsNotTheRequester($response);
        $this->assertStillInProgress($request);
    }

    #[Test]
    public function when_the_audit_write_fails_then_nothing_is_withdrawn(): void
    {
        // Given a member's own request and an audit write that fails after its row is inserted
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When they withdraw it
        $response = $this->withdrawDsarRequest($member, $this->company, $request->getKey());

        // Then the one transaction takes back the event, the row change and the audit row together
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertStillInProgress($request);
    }

    #[Test]
    public function when_a_member_withdraws_their_own_request_then_it_is_withdrawn_and_audited(): void
    {
        // Given a member's own request in progress
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When they withdraw it
        $response = $this->withdrawDsarRequest($member, $this->company, $request->getKey());

        // Then
        $this->assertWithdrawn($response, $request, $member);
        $this->assertWithdrawalAudited($request, $member, $member);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideVisibleNonRequesters(): array
    {
        return [
            'the subject of a request made on their behalf' => ['subject'],
            'an owner' => ['owner'],
            'an admin' => ['admin'],
        ];
    }
}
