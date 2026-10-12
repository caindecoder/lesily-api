<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Foundation\Notifications\Enums\NotificationType;

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
    #[DataProvider('provideClosedRequests')]
    public function when_the_request_is_closed_then_the_withdrawal_is_refused_as_no_longer_in_progress(string $closedBy, DsarRequestStatus $status): void
    {
        // Given a member's own request already closed — withdrawn, fulfilled or rejected
        $member = $this->aCompanyMember($this->company);
        $request = $this->aClosedRequest($this->company, $member, $closedBy);

        // When they withdraw it
        $response = $this->withdrawDsarRequest($member, $this->company, $request->getKey());

        // Then
        $this->assertRefusedAsNoLongerInProgress($response);
        $this->assertStillClosedAs($request, $status);
    }

    #[Test]
    public function when_a_non_requester_withdraws_a_closed_request_then_they_are_refused_as_not_the_requester(): void
    {
        // Given an admin withdrew the request they made for the subject
        $subject = $this->aCompanyMember($this->company);
        $admin = $this->aCompanyMember($this->company, 'admin');
        $request = $this->aSubmittedRequest($this->company, $subject, $admin);
        $this->withdrawDsarRequest($admin, $this->company, $request->getKey())->assertOk();

        // When the subject withdraws it
        $response = $this->withdrawDsarRequest($subject, $this->company, $request->getKey());

        // Then the requester check answers before the state check
        $this->assertRefusedAsNotTheRequester($response);
        $this->assertWithdrawnOnce($request);
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
    public function when_an_owner_withdraws_a_request_they_made_for_a_member_then_it_is_withdrawn(): void
    {
        // Given an owner requested an export for a member
        $owner = $this->aCompanyMember($this->company, 'owner');
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member, $owner);

        // When the owner withdraws it
        $response = $this->withdrawDsarRequest($owner, $this->company, $request->getKey());

        // Then the audit names the member as subject and the owner as actor
        $this->assertWithdrawn($response, $request, $owner);
        $this->assertWithdrawalAudited($request, $member, $owner);
    }

    #[Test]
    public function when_a_request_is_withdrawn_then_a_new_request_for_the_subject_is_accepted(): void
    {
        // Given a member withdrew their request
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->withdrawDsarRequest($member, $this->company, $request->getKey())->assertOk();

        // When they request a new export
        $response = $this->submitDsarRequest($member, $this->company);

        // Then the open-request slot was freed
        $this->assertAcceptedAsTheOnlyOpenRequest($response, $this->company, $member);
    }

    #[Test]
    #[DataProvider('provideRequesters')]
    public function when_a_request_is_withdrawn_then_its_subject_and_requester_are_notified_once_each(string $requestedBy, int $notices): void
    {
        // Given a member's request, made by them or by an owner on their behalf, with notices captured from the start
        Notification::fake();
        $member = $this->aCompanyMember($this->company);
        $requester = $this->aRequesterFor($this->company, $member, $requestedBy);
        $request = $this->aSubmittedRequest($this->company, $member, $requester);

        // When the requester withdraws it and the notices are delivered
        $this->withdrawDsarRequest($requester, $this->company, $request->getKey())->assertOk();
        $this->deliverTheNoticesOf(DsarRequestCancelled::class);

        // Then the member and whoever asked for it each hear once that it was withdrawn
        $this->assertNoticeSentOnceEach($request, NotificationType::DsarRequestWithdrawn, $notices);
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
