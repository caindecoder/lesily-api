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
    #[Test]
    public function when_a_guest_withdraws_a_request_then_they_are_refused_as_unauthenticated(): void
    {
        // Given
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);

        // When
        $response = $this->withdrawDsarRequestAsGuest($dsarRequest->getKey());

        // Then
        $this->assertUnauthenticated($response);
        $this->assertStillInProgress($dsarRequest);
    }

    #[Test]
    #[DataProvider('provideUnknownRequestIds')]
    public function when_the_request_id_is_unknown_then_the_withdrawal_is_not_found(string $unknownId): void
    {
        // Given a member with a request of their own
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);

        // When
        $response = $this->withdrawDsarRequest($this->member, $this->company, $unknownId);

        // Then
        $this->assertHiddenAsNotFound($response, $unknownId);
        $this->assertStillInProgress($dsarRequest);
    }

    #[Test]
    public function when_the_request_belongs_to_another_company_then_the_withdrawal_is_not_found(): void
    {
        // Given a member of both companies whose own request lives in the other one
        $otherCompany = Company::factory()->create();
        $this->attachMember($otherCompany, $this->member, $this->seededRole('employee'));
        $dsarRequest = $this->aSubmittedRequest($otherCompany, $this->member);

        // When they withdraw it from this company's context
        $response = $this->withdrawDsarRequest($this->member, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $dsarRequest->getKey());
        $this->assertStillInProgress($dsarRequest);
    }

    #[Test]
    public function when_a_member_cannot_see_the_request_then_the_withdrawal_is_not_found(): void
    {
        // Given another member's own request
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);

        // When an employee who neither made it nor is its subject withdraws it
        $response = $this->withdrawDsarRequest($this->aCompanyMember($this->company), $this->company, $dsarRequest->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $dsarRequest->getKey());
        $this->assertStillInProgress($dsarRequest);
    }

    #[Test]
    #[DataProvider('provideVisibleNonRequesters')]
    public function when_a_member_who_did_not_make_the_request_withdraws_it_then_they_are_refused_as_not_the_requester(string $kind): void
    {
        // Given a request the actor can see but did not make
        $subject = $this->member;
        [$request, $actor] = $kind === 'subject'
            ? [$this->aSubmittedRequest($this->company, $subject, $this->admin), $subject]
            : [$this->aSubmittedRequest($this->company, $subject), $this->memberWithRole($kind)];

        // When
        $response = $this->withdrawDsarRequest($actor, $this->company, $request->getKey());

        // Then
        $this->assertRefusedAsNotTheRequester($response);
        $this->assertStillInProgress($request);
    }

    #[Test]
    #[DataProvider('provideClosedRequests')]
    public function when_the_request_is_closed_then_the_withdrawal_is_refused_as_no_longer_in_progress(string $closedBy, DsarRequestStatus $dsarRequestStatus): void
    {
        // Given a member's own request already closed — withdrawn, fulfilled or rejected
        $dsarRequest = $this->aClosedRequest($this->company, $this->member, $closedBy);

        // When they withdraw it
        $response = $this->withdrawDsarRequest($this->member, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertRefusedAsNoLongerInProgress($response);
        $this->assertStillClosedAs($dsarRequest, $dsarRequestStatus);
    }

    #[Test]
    public function when_a_non_requester_withdraws_a_closed_request_then_they_are_refused_as_not_the_requester(): void
    {
        // Given an admin withdrew the request they made for the subject
        $subject = $this->member;
        $dsarRequest = $this->aSubmittedRequest($this->company, $subject, $this->admin);
        $this->withdrawDsarRequest($this->admin, $this->company, $dsarRequest->getKey())->assertOk();

        // When the subject withdraws it
        $response = $this->withdrawDsarRequest($subject, $this->company, $dsarRequest->getKey());

        // Then the requester check answers before the state check
        $this->assertRefusedAsNotTheRequester($response);
        $this->assertWithdrawnOnce($dsarRequest);
    }

    #[Test]
    public function when_the_audit_write_fails_then_nothing_is_withdrawn(): void
    {
        // Given a member's own request and an audit write that fails after its row is inserted
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When they withdraw it
        $response = $this->withdrawDsarRequest($this->member, $this->company, $dsarRequest->getKey());

        // Then the one transaction takes back the event, the row change and the audit row together
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertStillInProgress($dsarRequest);
    }

    #[Test]
    public function when_an_owner_withdraws_a_request_they_made_for_a_member_then_it_is_withdrawn(): void
    {
        // Given an owner requested an export for a member
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member, $this->owner);

        // When the owner withdraws it
        $response = $this->withdrawDsarRequest($this->owner, $this->company, $dsarRequest->getKey());

        // Then the audit names the member as subject and the owner as actor
        $this->assertWithdrawn($response, $dsarRequest, $this->owner);
        $this->assertWithdrawalAudited($response, $this->member, $this->owner);
    }

    #[Test]
    public function when_a_request_is_withdrawn_then_a_new_request_for_the_subject_is_accepted(): void
    {
        // Given a member withdrew their request
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->withdrawDsarRequest($this->member, $this->company, $dsarRequest->getKey())->assertOk();

        // When they request a new export
        $response = $this->submitDsarRequest($this->member, $this->company);

        // Then the open-request slot was freed
        $this->assertAcceptedAsTheOnlyOpenRequest($response, $this->company, $this->member);
    }

    #[Test]
    #[DataProvider('provideRequesters')]
    public function when_a_request_is_withdrawn_then_its_subject_and_requester_are_notified_once_each(string $requestedBy, int $notices): void
    {
        // Given a member's request, made by them or by an owner on their behalf, with notices captured from the start
        Notification::fake();
        $requester = $this->aRequesterFor($this->member, $requestedBy);
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member, $requester);

        // When the requester withdraws it and the notices are delivered
        $this->withdrawDsarRequest($requester, $this->company, $dsarRequest->getKey())->assertOk();
        $this->deliverTheNoticesOf(DsarRequestCancelled::class);

        // Then the member and whoever asked for it each hear once that it was withdrawn
        $this->assertNoticeSentOnceEach($dsarRequest, NotificationType::DsarRequestWithdrawn, $notices);
    }

    #[Test]
    public function when_a_member_withdraws_their_own_request_then_it_is_withdrawn_and_audited(): void
    {
        // Given a member's own request in progress
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);

        // When they withdraw it
        $response = $this->withdrawDsarRequest($this->member, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertWithdrawn($response, $dsarRequest, $this->member);
        $this->assertWithdrawalAudited($response, $this->member, $this->member);
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
