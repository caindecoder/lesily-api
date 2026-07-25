<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Exceptions\OpenOwnershipTransferExistsException;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferRequestedNotification;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

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
        $response = $this->postTransferRequestAsGuest();

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

    #[Test]
    public function when_the_target_lacks_an_emergency_contact_then_the_request_is_rejected(): void
    {
        // Given a company owner and a member who has never set an emergency contact
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');

        // When the owner requests a transfer to that member
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then it is refused until the target sets their emergency contact
        // (§S10b mandate). Literal pins, not the enum-derived helper: this PR
        // introduces the arm, so the wire contract is fixed here once — a
        // status or code-string change must fail THIS test (reviewer I2).
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'EMERGENCY_CONTACT_REQUIRED');
    }

    #[Test]
    public function when_the_target_is_a_removed_member_then_the_request_is_rejected(): void
    {
        // Given a company owner and a member whose membership was removed
        // (soft-deleted pivot — the s113 pivot-read regression class)
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $target->getKey())
            ->sole()
            ->delete();

        // When the owner requests a transfer to the removed member
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then it is refused as an invalid target
        $this->assertValidationRejected($response, 'targetUserId');
    }

    #[Test]
    public function when_an_open_transfer_request_already_exists_then_a_second_request_is_rejected(): void
    {
        // Given a company with an open (requested) transfer already on file
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        OwnershipTransferRequest::factory()->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $owner->getKey(),
            'target_user_id' => $target->getKey(),
        ]);

        // When the owner posts another transfer request
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then it is refused — one transfer at a time per company
        $this->assertRefusedWith($response, ApiErrorCode::StateGuardViolation);
    }

    #[Test]
    public function when_a_verified_transfer_is_still_open_then_a_second_request_is_rejected(): void
    {
        // Given a company whose open transfer already passed identity
        // verification (the open set counts requested AND verified — ERD-1-05 §5)
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        OwnershipTransferRequest::factory()->verified()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $owner->getKey(),
            'target_user_id' => $target->getKey(),
        ]);

        // When the owner posts another transfer request
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then it is refused — a verified request is still open
        $this->assertRefusedWith($response, ApiErrorCode::StateGuardViolation);
    }

    #[Test]
    public function when_the_request_omits_the_idempotency_key_then_it_is_rejected(): void
    {
        // Given a company owner with an eligible target
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);

        // When the request is posted without an Idempotency-Key
        $response = $this->postTransferRequestWithoutKey($owner, $company, $this->transferBodyFor($target));

        // Then it is refused — the mutation mounts the required idempotency contract
        $this->assertRefusedWith($response, ApiErrorCode::IdempotencyKeyRequired);
    }

    #[Test]
    public function when_a_transfer_is_re_requested_on_the_same_stream_then_the_second_request_is_rejected(): void
    {
        // Given a transfer request already recorded on a stream (Tier-2 seam —
        // the aggregate's own genesis guard, below the HTTP Tier-1 repo read).
        // Real rows: the projector reacts to the persist, so the projection's
        // FK chain must hold even at the seam.
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $command = RequestOwnershipTransferCommand::validateAndCreate([
            'companyId' => $company->getKey(),
            'currentOwnerUserId' => $owner->getKey(),
            'targetUserId' => $target->getKey(),
            'reason' => 'Retiring from the business',
            'requestChannel' => 'app',
        ]);
        $requestId = (string) Str::uuid7();
        OwnershipTransferRequestAggregate::retrieve($requestId)->request($command)->persist();

        // When the genesis fires again on the same stream
        try {
            OwnershipTransferRequestAggregate::retrieve($requestId)->request($command);
            $this->fail('Expected the genesis re-fire to be rejected.');
        } catch (OpenOwnershipTransferExistsException) {
            // Then only the original event remains on the stream
            $this->assertSame(1, LesilyStoredEvent::query()
                ->where('event_class', OwnershipTransferRequested::class)
                ->count());
        }
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
            'missing channel' => [
                ['targetUserId' => '0193abcd-9999-7000-8000-000000000001', 'reason' => 'Retiring'],
                'requestChannel',
            ],
            'malformed target' => [
                ['targetUserId' => 'not-a-uuid', 'reason' => 'Retiring', 'requestChannel' => 'app'],
                'targetUserId',
            ],
            'overlong reason' => [
                ['targetUserId' => '0193abcd-9999-7000-8000-000000000001', 'reason' => str_repeat('r', 1001), 'requestChannel' => 'app'],
                'reason',
            ],
        ];
    }

    #[Test]
    public function when_the_same_request_is_retried_with_the_same_idempotency_key_then_the_original_outcome_is_replayed(): void
    {
        // Given a successfully submitted transfer request
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        $this->pinIdempotencyKey((string) Str::uuid7());
        $first = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));
        $first->assertCreated();

        // When the identical request is retried with the same Idempotency-Key
        $replay = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then the original outcome is replayed without a second side effect
        $replay->assertCreated();
        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, OwnershipTransferRequest::query()->count());
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRequested::class)
            ->count());
    }

    #[Test]
    public function when_the_owner_requests_via_a_support_ticket_then_the_channel_is_recorded(): void
    {
        // Given an eligible transfer arrangement (§10b email intake entered
        // via impersonation uses the second contracted channel literal — F2)
        $this->arrangeEligibleTransfer();
        $body = [...$this->transferBodyFor($this->target), 'requestChannel' => 'support_ticket'];

        // When the owner's request is submitted through the support channel
        $response = $this->postTransferRequest($this->owner, $this->company, $body);

        // Then the request is created carrying the support_ticket channel
        $response->assertCreated();
        $response->assertJsonPath('data.request_channel', 'support_ticket');
    }

    #[Test]
    public function when_the_owner_requests_a_transfer_to_an_eligible_member_then_the_request_is_created(): void
    {
        // Given a company owner, an eligible member (emergency contact set),
        // and a platform support admin on the allowlist
        $this->arrangeEligibleTransfer();

        // When the owner requests the transfer
        $response = $this->postTransferRequest($this->owner, $this->company, $this->transferBodyFor($this->target));

        // Then the request is created, the genesis event is recorded, the
        // projection row + 30-day timer are written, and the T1 side effects fire
        $requestId = $this->assertTransferCreated($response);
        $this->assertRequestedEventRecorded();
        $this->assertProjectionMirrorsEvent($requestId);
        $this->assertThirtyDayTimerAnchored($requestId);
        $this->assertSupportQueueNotified();
        $this->assertAuditRowRecorded($requestId);
    }

    private Company $company;

    private User $owner;

    private User $target;

    private User $supportAdmin;

    /**
     * Shared happy-path fixtures as properties (§2.2 Rule 4) — owner + eligible
     * target + allowlisted support admin.
     */
    private function arrangeEligibleTransfer(): void
    {
        [$this->company, $this->owner] = $this->aCompanyWithOwner();
        $this->target = $this->aMemberWithRole($this->company, 'admin');
        $this->withEmergencyContact($this->company, $this->target);
        $this->supportAdmin = $this->aPlatformSupportAdmin();
    }

    /**
     * 201 with the full owner-facing snake_case envelope; returns the new id.
     */
    private function assertTransferCreated(TestResponse $response): string
    {
        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'company_id', 'status', 'request_channel', 'reason', 'requested_at']]);
        $response->assertJsonPath('data.status', 'requested');
        $response->assertJsonPath('data.company_id', $this->company->getKey());
        $response->assertJsonPath('data.request_channel', 'app');

        return $response->json('data.id');
    }

    /**
     * The genesis OwnershipTransferRequested is on the company-scoped stream
     * with the actor + target in its payload (amendment R — the event, not a
     * direct row write, is the mechanism).
     */
    private function assertRequestedEventRecorded(): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRequested::class)
            ->whereForCompany($this->company->getKey())
            ->sole();

        $this->assertSame($this->owner->getKey(), $stored->event_properties['currentOwnerUserId']);
        $this->assertSame($this->target->getKey(), $stored->event_properties['targetUserId']);
    }

    /**
     * The projection row mirrors the event's identities and state.
     */
    private function assertProjectionMirrorsEvent(string $requestId): void
    {
        $row = OwnershipTransferRequest::query()->findOrFail($requestId);

        $this->assertSame($this->company->getKey(), $row->company_id);
        $this->assertSame($this->owner->getKey(), $row->current_owner_user_id);
        $this->assertSame($this->target->getKey(), $row->target_user_id);
        $this->assertSame($this->owner->getKey(), $row->created_by);
        $this->assertSame(OwnershipTransferStatus::Requested, $row->status);
    }

    /**
     * The 30-day next_state_transition_at anchor (SM-1-05 T1 / OQ-1-006).
     */
    private function assertThirtyDayTimerAnchored(string $requestId): void
    {
        $row = OwnershipTransferRequest::query()->findOrFail($requestId);

        $this->assertNotNull($row->next_state_transition_at);
        $this->assertTrue($row->next_state_transition_at->equalTo($row->requested_at->addDays(30)));
    }

    /**
     * The support-queue notification reaches ONLY the allowlisted admin —
     * neither party learns of the queue entry before support verification.
     *
     * Retrofitted at s151 (ruling H3) from the generic SystemNotification onto the
     * discriminated OwnershipTransferRequestedNotification. The BODY is asserted,
     * not just the class: T1 and T2 previously emitted byte-identical
     * SystemNotifications, so a recipient could not tell "entered the queue" from
     * "ready to execute", nor WHICH transfer either concerned (OQ-1-144). Asserting
     * the rendered content is what makes those two distinguishable by test rather
     * than by hope.
     */
    private function assertSupportQueueNotified(): void
    {
        Notification::assertSentTo(
            $this->supportAdmin,
            OwnershipTransferRequestedNotification::class,
            fn (OwnershipTransferRequestedNotification $notification): bool => $notification->transfer->company_id === $this->company->getKey()
                && str_contains($notification->toArray($this->supportAdmin)['body'], 'awaiting identity verification'),
        );
        Notification::assertNotSentTo([$this->owner, $this->target], OwnershipTransferRequestedNotification::class);
    }

    /**
     * The append-only audit_trail row attributes the owner and links the
     * stored event (the event-driven writer's distinguishing linkage).
     */
    private function assertAuditRowRecorded(string $requestId): void
    {
        $entry = AuditEntry::query()->where('subject_id', $requestId)->sole();

        $this->assertSame('transfer_requested', $entry->action);
        $this->assertSame($this->owner->getKey(), $entry->actor_user_id);
        $this->assertSame($this->company->getKey(), $entry->company_id);
        $this->assertNotNull($entry->stored_event_id);
    }
}
