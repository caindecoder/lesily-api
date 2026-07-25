<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferRejectedNotification;

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
     * The tenant roles the admin family must turn away with the SAME 404 an
     * unknown id produces. Born green — the platform.admin gate landed with the
     * route in lap 1 — so its teeth were proven by revert-check (drop the
     * middleware, watch all six rows RED, restore) rather than by a watched RED.
     */
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_the_caller_is_not_a_platform_admin_then_the_transfer_is_not_found(string $roleCode): void
    {
        // Given a transfer awaiting review and a tenant member of its company
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $caller = $this->aCompanyMember($company, $roleCode);

        // When that tenant role attempts to reject it
        $response = $this->rejectTransfer($caller, $transfer->getKey(), $this->rejectionBody());

        // Then the surface denies it without acknowledging the transfer exists
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertTransferStillOpen($transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * An id no transfer carries is refused with the same envelope a denied
     * caller gets. A VALID body is sent deliberately: it isolates the existence
     * check from input validation, so this test cannot pass for the wrong reason
     * once the Command's rules land.
     */
    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an allowlisted admin and an id that belongs to no transfer
        $admin = $this->anAllowlistedAdmin();

        // When they attempt to reject it
        $response = $this->rejectTransfer($admin, (string) Str::uuid7(), $this->rejectionBody());

        // Then the surface refuses it indistinguishably from a denied caller
        $this->assertResourceNotFoundNoLeak($response);
    }

    // Happy — the T3 walk: a Requested transfer is ended before verification.
    #[Test]
    public function when_a_platform_admin_rejects_a_requested_transfer_then_it_is_rejected_before_verification(): void
    {
        // Given a transfer awaiting review and the admin reviewing it
        $this->arrangeRejectableTransfer();

        // When the admin submits the rejection record
        $response = $this->rejectTransfer($this->admin, $this->transfer->getKey(), self::rejectionBody());

        // Then the transfer is terminally rejected before verification
        $this->assertRejectionAccepted($response, 'pre-verification');
        $this->assertRejectedEventRecorded('pre-verification');
        $this->assertProjectionMirrorsRejection('pre-verification');
        $this->assertSlaTimerCleared();
        $this->assertRequesterNotifiedWithTheReason();
        $this->assertRejectionAudited();
    }

    /**
     * The append-only audit row for the transition, linked to its stored event.
     * The ACTOR is the point: this is the record of WHICH platform admin ended a
     * tenant's transfer, which is the evidence a "the rejection was arbitrary"
     * claim turns on (UC-1-128 stakeholders — Lesily Legal).
     *
     * metadata stays NULL, following the s149 ruling: audit_trail rows are
     * TENANT-readable via UC-1-066, and while the reason here is prose the owner
     * already receives verbatim, the durable forensic record is the event, which
     * this row reaches through stored_event_id.
     */
    private function assertRejectionAudited(): void
    {
        $entry = AuditEntry::query()->where('event_class', 'OwnershipTransferRejected')->sole();

        $this->assertSame($this->transfer->getKey(), $entry->subject_id);
        $this->assertSame($this->company->getKey(), $entry->company_id);
        $this->assertSame('ownership_transfer_request', $entry->subject_type);
        $this->assertSame('transfer_rejected', $entry->action);
        $this->assertSame($this->admin->getKey(), $entry->actor_user_id);
        $this->assertSame($this->admin->name, $entry->actor_label);
        $this->assertNotNull($entry->stored_event_id);
        $this->assertNull($entry->metadata);
    }

    /**
     * T3/T5's operational signal: the REQUESTER learns their transfer was ended
     * and why (SM-1-05 §7 — RequesterNotificationReactor is T3/T5's reactor).
     *
     * The reason is asserted ON the notification, not merely that one was sent.
     * That is the whole content question OQ-1-144 recorded: the generic
     * SystemNotification carries only (type, companyName), so a notification could
     * be "sent" while telling the owner nothing about which transfer ended or why.
     *
     * The target user is asserted NOT notified: rejection happens before any role
     * change, so they were never affected and have no standing to be told (UC
     * stakeholders — the designated owner "has not yet been affected").
     */
    private function assertRequesterNotifiedWithTheReason(): void
    {
        Notification::assertSentTo(
            $this->owner,
            OwnershipTransferRejectedNotification::class,
            fn (OwnershipTransferRejectedNotification $notification): bool => $notification->reason === self::rejectionBody()['reason']
                && $notification->transfer->getKey() === $this->transfer->getKey(),
        );

        Notification::assertNotSentTo(
            [$this->transfer->targetUser, $this->admin],
            OwnershipTransferRejectedNotification::class,
        );
    }

    /**
     * A terminal row carries no next deadline, so the daily expiry scan can never
     * pick it up again (SM-1-05 §5 — terminal states end the stream).
     *
     * The BEFORE value is asserted too, deliberately: without it a projector that
     * had never set a deadline in the first place would satisfy "it is null now"
     * and this would prove nothing about the clear.
     */
    private function assertSlaTimerCleared(): void
    {
        $this->assertNotNull($this->transfer->next_state_transition_at);
        $this->assertNull($this->currentTransfer($this->transfer)->next_state_transition_at);
    }

    private Company $company;

    private User $admin;

    private User $owner;

    private OwnershipTransferRequest $transfer;

    /**
     * Shared happy-path fixtures as properties (§2.2 Rule 4). The transfer is
     * arranged through the REAL T1 chain, never the factory: the factory writes
     * only the projection, so an aggregate retrieved against it would replay an
     * empty stream and the state guard would fire — the test would then pass for
     * entirely the wrong reason (s149 C1).
     */
    private function arrangeRejectableTransfer(): void
    {
        [$this->company, $this->owner] = $this->aCompanyWithMember('owner');
        $this->admin = $this->anAllowlistedAdmin();
        $this->transfer = $this->aRequestedTransferStream(
            $this->company,
            $this->owner,
            User::factory()->create(),
        );

        // Discard the arrangement's own T1 side effects. The genesis fires
        // SupportQueueNotificationReactor, so without this reset a notification
        // assertion could pass on the T1 notification alone.
        Notification::fake();
    }

    /**
     * 200 with the admin envelope reporting the terminal state and which window
     * ended it.
     */
    private function assertRejectionAccepted(TestResponse $response, string $expectedSource): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.id', $this->transfer->getKey());
        $response->assertJsonPath('data.status', 'rejected');
        $response->assertJsonPath('data.rejection_source', $expectedSource);
    }

    /**
     * Amendment R — the EVENT is the mechanism, not a direct row write. The two
     * are indistinguishable from the read model alone (both leave identical
     * columns), so without this the transition could be reduced to an update()
     * and would silently lose its history on a projection replay, which is the
     * exact bug event-sourcing exists to prevent. Teeth proven by revert-check:
     * swapping the interactor for a direct write keeps every other assertion in
     * this class green and REDs only this one.
     *
     * The payload carries the legal-defensibility set verbatim — actor, prose,
     * code and window — which is why they are asserted on the STORED event and
     * not only on the projection.
     */
    private function assertRejectedEventRecorded(string $expectedSource): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRejected::class)
            ->whereForCompany($this->company->getKey())
            ->sole();

        $this->assertSame($this->transfer->getKey(), $stored->event_properties['requestId']);
        $this->assertSame($this->admin->getKey(), $stored->event_properties['rejectedBy']);
        $this->assertSame(self::rejectionBody()['reason'], $stored->event_properties['rejectionReason']);
        $this->assertSame('identity_failed', $stored->event_properties['rejectionReasonCode']);
        $this->assertSame($expectedSource, $stored->event_properties['source']);
    }

    /**
     * The read model carries the whole rejection record: terminal state, actor,
     * stamp, verbatim prose, the machine-readable code and the window.
     *
     * rejection_reason_code is the s151-H1 column. Before that ruling the Command
     * validated the code and nothing stored it, so this assertion is the thing
     * that makes the UC's "rejection_reason_code persisted" postcondition true
     * rather than aspirational.
     */
    private function assertProjectionMirrorsRejection(string $expectedSource): void
    {
        $row = $this->currentTransfer($this->transfer);

        $this->assertSame(OwnershipTransferStatus::Rejected, $row->status);
        $this->assertSame($this->admin->getKey(), $row->rejected_by);
        $this->assertNotNull($row->rejected_at);
        $this->assertSame(self::rejectionBody()['reason'], $row->rejection_reason);
        $this->assertSame('identity_failed', $row->rejection_reason_code);
        $this->assertSame($expectedSource, $row->rejection_source);
    }

    /**
     * Every shape the rejection record must refuse. Both fields are mandatory AT
     * WRITE TIME (UC-1-128 minimal guarantee: no rejection reaches stored_events
     * without attributable actor, verbatim prose AND a code), and the code must
     * name one of the four locked reasons.
     *
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('provideInvalidRejectionInputs')]
    public function when_the_rejection_input_is_invalid_then_it_is_refused(array $body, string $invalidField): void
    {
        // Given a transfer awaiting review
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When an allowlisted admin submits a malformed rejection
        $response = $this->rejectTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), $body);

        // Then it is refused on that field and nothing is recorded
        $this->assertValidationRejected($response, $invalidField);
        $this->assertTransferStillOpen($transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * The four code literals are spelled out rather than derived from
     * RejectionReasonCode::values(): deriving them would make this provider move
     * WITH the enum, so dropping a case would silently narrow the accepted set on
     * both sides and stay green. Literals make this the regression pin for the
     * locked vocabulary (the s149 artefact-enum lesson).
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidRejectionInputs(): array
    {
        return [
            'reason absent' => [self::rejectionBodyWithout('reason'), 'reason'],
            'reason empty' => [self::rejectionBody(['reason' => '']), 'reason'],
            'reason whitespace only' => [self::rejectionBody(['reason' => '   ']), 'reason'],
            'reason beyond the bound' => [self::rejectionBody(['reason' => str_repeat('a', 1001)]), 'reason'],
            'reason code absent' => [self::rejectionBodyWithout('rejectionReasonCode'), 'rejectionReasonCode'],
            'reason code empty' => [self::rejectionBody(['rejectionReasonCode' => '']), 'rejectionReasonCode'],
            'reason code outside the locked four' => [
                self::rejectionBody(['rejectionReasonCode' => 'admin_discretion']),
                'rejectionReasonCode',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rejectionBodyWithout(string $key): array
    {
        $body = self::rejectionBody();
        unset($body[$key]);

        return $body;
    }

    /**
     * The canonical valid rejection payload — camelCase in, per api-conventions
     * §8.1. Static so a DataProvider can break exactly one field of it per row.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function rejectionBody(array $overrides = []): array
    {
        return array_merge([
            'reason' => 'Identity document expired and no follow-up received within 7 days.',
            'rejectionReasonCode' => 'identity_failed',
        ], $overrides);
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
