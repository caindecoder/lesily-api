<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Notifications\Channels\QueuedMailChannel;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Mails\OwnershipTransferNotificationMail;
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
     * Rejection is terminal, so a second one is refused and the first record
     * stands. Task 5 owns exactly this row of the transition table and no other
     * terminal row: `Rejected` is the ONE terminal state this task makes
     * reachable, and it is reached through a real first rejection. The
     * `Cancelled` / `Expired` / `Executed` rows belong to Tasks 6/7/8, whose
     * events do not exist yet (s149 C2 + s151 G1).
     */
    #[Test]
    public function when_the_transfer_is_already_rejected_then_it_is_not_rejected_again(): void
    {
        // Given a transfer this admin has already rejected
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aRejectedTransferStream($company, $owner, $admin);
        $original = $this->currentTransfer($transfer);

        // When a second rejection is attempted against it
        $response = $this->rejectTransfer($admin, $transfer->getKey(), self::rejectionBody([
            'reason' => 'A second reviewer reached the same conclusion.',
            'rejectionReasonCode' => 'policy_violation',
        ]));

        // Then it is refused and the original rejection is untouched
        $this->assertStateGuardRefused($response, 'Only an open transfer can be rejected.');
        $this->assertOriginalRejectionIntact($original);
    }

    /**
     * The refused second attempt changed nothing: the stream still carries exactly
     * ONE rejection event, and the projected record still names the first
     * rejector, reason, code and time. Without the event-count aspect a guard that
     * recorded a duplicate event but left the columns alone would pass.
     */
    private function assertOriginalRejectionIntact(OwnershipTransferRequest $original): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRejected::class)
            ->count());
        // T1 genesis + the ONE accepted rejection. A third row would mean the
        // refused attempt was logged as though it had happened.
        $this->assertDatabaseCount('audit_trail', 2);

        $row = $this->currentTransfer($original);
        $rejectedAt = $row->rejected_at;
        $originalRejectedAt = $original->rejected_at;

        $this->assertSame($original->rejected_by, $row->rejected_by);
        $this->assertSame($original->rejection_reason, $row->rejection_reason);
        $this->assertSame($original->rejection_reason_code, $row->rejection_reason_code);
        $this->assertNotNull($rejectedAt);
        $this->assertNotNull($originalRejectedAt);
        $this->assertTrue($rejectedAt->equalTo($originalRejectedAt));
    }

    /**
     * The rejector is whoever the token says, never whoever the body claims.
     * `rejectedBy` is the field this whole use case exists to produce — every
     * stakeholder clause about defending a rejection against a later "it was
     * arbitrary" claim rests on it being auth-derived — yet the only thing
     * enforcing that is an `only()` whitelist and the spread ordering in the
     * controller, which no other test touches. A refactor to `$request->all()`
     * would let one admin attribute a rejection to a colleague.
     */
    #[Test]
    public function when_the_body_names_a_different_rejector_then_the_authenticated_admin_is_recorded(): void
    {
        // Given a rejectable transfer and a colleague to impersonate
        $this->arrangeRejectableTransfer();
        $colleague = User::factory()->create();

        // When the admin submits a body claiming the colleague rejected it
        $this->rejectTransfer($this->admin, $this->transfer->getKey(), self::rejectionBody([
            'rejectedBy' => $colleague->getKey(),
        ]));

        // Then every record of the rejector names the authenticated admin
        $this->assertRejectorIsAlways($this->admin);
    }

    /**
     * Every surface recording WHO ended the transfer names $expected — the event
     * payload (the durable evidence), the projection (what the admin console
     * reads) and the audit row (the access log). A forged body value would have to
     * defeat all three.
     */
    private function assertRejectorIsAlways(User $expected): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRejected::class)
            ->sole();

        $this->assertSame($expected->getKey(), $stored->event_properties['rejectedBy']);
        $this->assertSame($expected->getKey(), $this->currentTransfer($this->transfer)->rejected_by);
        $this->assertSame($expected->getKey(), AuditEntry::query()
            ->where('event_class', 'OwnershipTransferRejected')
            ->sole()
            ->actor_user_id);
    }

    /**
     * The projector must address ONE row. It is a fluent `whereKey(...)` call, and
     * Infection generates no method-call removal on a builder chain — so with a
     * single transfer in the fixture, dropping the scope leaves the suite green
     * while in production one admin's rejection would terminate every transfer on
     * the platform.
     *
     * A second, unrelated tenant is therefore part of the arrangement, not an
     * afterthought: it is the only thing that makes the scope observable (s149 C6).
     */
    #[Test]
    public function when_a_transfer_is_rejected_then_no_other_tenants_transfer_is_touched(): void
    {
        // Given a rejectable transfer and an untouched transfer at another company
        $this->arrangeRejectableTransfer();
        $bystander = $this->aBystanderTransferAtAnotherCompany();

        // When the admin rejects the first
        $this->rejectTransfer($this->admin, $this->transfer->getKey(), self::rejectionBody());

        // Then the other tenant's transfer is exactly as it was
        $this->assertBystanderUntouched($bystander);
    }

    private function assertBystanderUntouched(OwnershipTransferRequest $bystander): void
    {
        $row = $this->currentTransfer($bystander);
        $deadline = $row->next_state_transition_at;
        $originalDeadline = $bystander->next_state_transition_at;

        $this->assertSame(OwnershipTransferStatus::Requested, $row->status);
        $this->assertNull($row->rejected_at);
        $this->assertNull($row->rejected_by);
        // Unchanged, not merely non-null (tightened at the s152 review to match
        // the verify sibling): the terminal clear writes NULL here, so a
        // non-null check catches an unscoped clear — but not an unscoped write
        // that set some other deadline on the bystander.
        $this->assertNotNull($deadline);
        $this->assertNotNull($originalDeadline);
        $this->assertTrue($deadline->equalTo($originalDeadline));
    }

    /**
     * Ruling H4 — a legal hold does NOT bar rejection, the deliberate asymmetry
     * with verify. §S10b's hold freezes ownership CHANGES, and a rejection changes
     * no ownership and touches no pivot row; blocking it would strand held tenants
     * with permanently un-closable requests, since the expiry scan skips them too.
     *
     * This test is what makes that ruling durable rather than an omission somebody
     * later "fixes" by copying verify's interactor guard across.
     */
    #[Test]
    public function when_the_company_is_under_legal_hold_then_the_transfer_is_still_rejected(): void
    {
        // Given a rejectable transfer whose company is under a regulatory hold
        $this->arrangeRejectableTransfer();
        $this->placeCompanyUnderLegalHold($this->company);

        // When an allowlisted admin rejects it
        $response = $this->rejectTransfer($this->admin, $this->transfer->getKey(), self::rejectionBody());

        // Then the rejection succeeds — a hold freezes ownership, not closure
        $this->assertRejectionAccepted($response, 'pre-verification');
    }

    /**
     * The current owner keeps the company, and the designated new owner keeps
     * their pre-transfer role. This is the UC's Minimal Guarantee headline —
     * "roles on company_user are NEVER mutated by this UC" — and it is a
     * regression guard aimed squarely at Task 8, which WILL make this endpoint's
     * sibling mutate exactly these rows.
     *
     * Arranged from the Verified window deliberately: that is the state where a
     * pivot swap is otherwise one admin click away.
     */
    #[Test]
    public function when_a_transfer_is_rejected_then_the_ownership_records_are_left_untouched(): void
    {
        // Given a verified transfer and the ownership records as they stand
        [$this->company, $this->owner] = $this->aCompanyWithMember('owner');
        $this->admin = $this->anAllowlistedAdmin();
        $this->transfer = $this->aVerifiedTransferStream($this->company, $this->owner, $this->admin);
        $rolesBefore = $this->currentPivotRoles();

        // When the admin rejects it at execution review
        $this->rejectTransfer($this->admin, $this->transfer->getKey(), self::rejectionBody());

        // Then ownership and both pivot roles are exactly as they were
        $this->assertSame($this->company->owner_id, $this->company->fresh()?->owner_id);
        $this->assertSame($rolesBefore, $this->currentPivotRoles());
    }

    /**
     * Every (user_id => role_id) pair on this company's roster, as it stands now.
     * Comparing the whole map rather than one row means a swap between two members
     * cannot cancel itself out into a passing assertion.
     *
     * @return array<string, string>
     */
    private function currentPivotRoles(): array
    {
        return DB::table('company_user')
            ->where('company_id', $this->company->getKey())
            ->pluck('role_id', 'user_id')
            ->all();
    }

    /**
     * rejected_at is the legally-significant moment of the decision, but every
     * other assertion on it is merely non-null. Shifting the aggregate's clock by
     * five years would leave the whole class green; freezing time is what pins it
     * to an absolute instant (the s149 verified_at precedent).
     */
    #[Test]
    public function when_a_transfer_is_rejected_then_the_record_is_stamped_with_the_server_clock(): void
    {
        // Given a rejectable transfer and a frozen clock
        $this->arrangeRejectableTransfer();
        $frozen = CarbonImmutable::parse('2026-07-25 14:05:00');
        $this->travelTo($frozen);

        // When the admin rejects it
        $this->rejectTransfer($this->admin, $this->transfer->getKey(), self::rejectionBody());

        // Then the stamp sits at that absolute instant
        $rejectedAt = $this->currentTransfer($this->transfer)->rejected_at;
        $this->assertNotNull($rejectedAt);
        $this->assertTrue($rejectedAt->equalTo($frozen), "Expected rejected_at at {$frozen}, got {$rejectedAt}.");
    }

    /**
     * The rejection's durability guarantee, pinned at the one place we own it.
     *
     * UC-1-128 sc.10 promises that a notification-delivery failure never rolls back
     * the rejection. Two facts make that true, and only the second is ours: the
     * event is persisted before any reactor runs (spatie's ordering, already implied
     * by every happy-path assertion here), and the email leg is DEFERRED to the
     * queue instead of sent inline, so a dead transport cannot reach the request at
     * all.
     *
     * That second fact is a channel choice, and swapping QueuedMailChannel for
     * Laravel's synchronous `mail` channel would silently undo it — in production,
     * where the queue is the database driver, a rejection would then 500 after the
     * event was already committed and the admin would be told their successful
     * rejection failed. Nothing else in the suite would notice.
     *
     * Deliberately NOT written as "make the transport throw and assert the rejection
     * stands": tests run on the `sync` queue (phpunit.xml), where `queue()` sends
     * inline, so such a test would assert a 500 that production cannot produce —
     * pinning the test environment's artefact rather than our behaviour.
     */
    #[Test]
    public function when_the_requester_is_notified_then_the_email_leg_is_deferred_to_the_queue(): void
    {
        // Given a rejection notification
        $notification = new OwnershipTransferRejectedNotification(
            $this->aTransferInState(
                $this->aCompanyWithMember('owner')[0],
                User::factory()->create(),
                OwnershipTransferStatus::Requested,
            ),
            'Identity unverified.',
        );

        // When its delivery channels are resolved
        $channels = $notification->via(User::factory()->create());

        // Then the in-app record is immediate and the email is queued behind it
        $this->assertSame(['database', QueuedMailChannel::class], $channels);
    }

    /**
     * The email leg's last resort. Once the three retries are spent the failure is
     * REPORTED for ops and swallowed for the user, who already has the in-app
     * record — so a dead mail transport never becomes a silent loss.
     *
     * Amendment T: this needs its own direct test because `Mail::fake()` and
     * `Queue::fake()` both short-circuit the failure path entirely, so `failed()`
     * is invisible to every local gate; codecov/patch is what flags it, after the
     * push. `Exceptions::fake()` + calling the hook directly is the
     * ReceiveEmailNotificationTest precedent.
     *
     * It lives in this class rather than its own: the Mailable exists solely to
     * carry this notification, so its failure behaviour is part of "rejecting
     * notifies the requester" and a separate class would hold one test (§2.14 —
     * a different seam justifies a split, a single method does not).
     */
    #[Test]
    public function when_the_rejection_email_exhausts_its_retries_then_the_failure_is_reported(): void
    {
        // Given a rejection email and a reporting spy
        Exceptions::fake();
        $mail = new OwnershipTransferNotificationMail('Ownership transfer rejected', 'Reason: identity unverified.');
        $transportFailure = new RuntimeException('SMTP connection refused.');

        // When its retries are exhausted
        $mail->failed($transportFailure);

        // Then the cause is reported for ops, and the retry policy is the agreed one
        $this->assertMailFailureReported($mail, $transportFailure);
    }

    private function assertMailFailureReported(OwnershipTransferNotificationMail $mail, RuntimeException $cause): void
    {
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $cause);

        // Pinned as literals: the retry policy is a contract with ops (OQ-1-063),
        // and Infection generates no mutant for an untested property default.
        $this->assertSame(3, $mail->tries);
        $this->assertSame([1, 5, 15], $mail->backoff);
    }

    /**
     * The email leg's CONTENT — which no test reached until the s152 review.
     *
     * Both ownership-transfer test bases call `Notification::fake()` in setUp(),
     * so QueuedMailChannel never runs and `toMail()` was never invoked anywhere in
     * the suite. That left the subject line, the markdown view binding and the
     * whole Mailable body unexecuted while still counting as new code — which the
     * 100%-patch-coverage gate would have rejected on push, the same way it
     * rejected the unreachable `is_array` guard at s149.
     *
     * Building the Mailable off a real notification is the
     * `ReceiveEmailNotificationTest:70` shape, and one Act exercises `title()`,
     * `body()`, `toMail()`, `envelope()`, `content()` and the Blade view together.
     * A second test routing through `Mail::fake()` + `notify()` would add nothing
     * but a fight with the base's notification fake.
     */
    #[Test]
    public function when_the_rejection_email_is_built_then_it_carries_the_notice_and_the_reason(): void
    {
        // Given a rejection notification carrying a reason
        $notification = new OwnershipTransferRejectedNotification(
            $this->aTransferInState(
                $this->aCompanyWithMember('owner')[0],
                User::factory()->create(),
                OwnershipTransferStatus::Requested,
            ),
            'Identity documents could not be matched to the applicant.',
        );

        // When the email leg is built for the requester
        $mail = $notification->toMail(User::factory()->create());

        // Then it carries the notice subject and renders the reason verbatim
        $this->assertRejectionEmailCarriesTheReason($mail);
    }

    private function assertRejectionEmailCarriesTheReason(OwnershipTransferNotificationMail $mail): void
    {
        $this->assertSame('Ownership transfer rejected', $mail->envelope()->subject);
        $this->assertStringContainsString('Identity documents could not be matched to the applicant.', $mail->bodyText);
        // render() is what proves the Blade view resolves and binds $bodyText —
        // asserting the property alone would leave the template unexecuted
        // (SystemNotificationMailRenderTest precedent).
        $this->assertStringContainsString('Identity documents could not be matched to the applicant.', $mail->render());
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
        $this->assertRejectedEventRecorded('pre-verification', self::rejectionBody());
        $this->assertProjectionMirrorsRejection('pre-verification');
        $this->assertSlaTimerCleared();
        $this->assertRequesterNotifiedWithTheReason();
        $this->assertRejectionAudited();
    }

    /**
     * The T5 walk: the same rejection from the OTHER window, after identity was
     * verified but before execution. Structurally identical to T3 except for the
     * discriminator (UC-1-128 sc.2), which is exactly why both windows share one
     * endpoint, one Command and one aggregate method.
     */
    #[Test]
    public function when_a_platform_admin_rejects_a_verified_transfer_then_it_is_rejected_at_execution_time(): void
    {
        // Given a transfer whose identity this admin already verified
        [$this->company, $this->owner] = $this->aCompanyWithMember('owner');
        $this->admin = $this->anAllowlistedAdmin();
        $this->transfer = $this->aVerifiedTransferStream($this->company, $this->owner, $this->admin);
        Notification::fake();

        // When the admin rejects it at execution review
        $submitted = self::rejectionBody([
            'reason' => 'Target user left the company after verification - transfer no longer valid.',
            'rejectionReasonCode' => 'target_ineligible',
        ]);
        $response = $this->rejectTransfer($this->admin, $this->transfer->getKey(), $submitted);

        // Then it is terminally rejected at execution time, verification intact
        $this->assertRejectionAccepted($response, 'execution-time');
        $this->assertRejectedEventRecorded('execution-time', $submitted);
        $this->assertRejectionAudited();
        $this->assertVerificationRecordPreserved();
    }

    /**
     * The prior T2 evidence survives the rejection untouched. This is the half of
     * UC-1-113's own sc.6 that IS reachable here: the verifier's identity and
     * notes are the chain-of-custody a disputed transfer turns on, and a terminal
     * transition must not erase them (SM-1-05 §6 audit-immutability; the T7
     * asymmetry note makes the same promise for withdrawal).
     */
    private function assertVerificationRecordPreserved(): void
    {
        $row = $this->currentTransfer($this->transfer);

        $this->assertSame($this->admin->getKey(), $row->verified_by);
        $this->assertNotNull($row->verified_at);
        $this->assertNotNull($row->verification_notes);
        $this->assertNotNull($row->verification_artefacts);
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
     *
     * actor_role_code / subject_label / description were added at the s152 review.
     * All three are bare string literals in the reactor, so Infection generates no
     * mutant for them and nothing else in the suite bound them — actor_role_code
     * least excusably, since it is part of what a tenant reads back through
     * UC-1-066.
     *
     * Those three arrived first as three more assertSame calls, taking this helper
     * from 8 aspects to 11 and widening a §2.2 Rule 5 exception that had been
     * granted at 8. Pass 2 caught that and it is now written the way the base
     * class already writes an audit-row pin (assertTransferReadAudited): ONE
     * assertDatabaseHas carrying the whole row, plus the two null-shape checks a
     * column map cannot express. Same coverage, 3 aspects, no exception needed.
     * The identical gap in the merged assertVerificationAudited is logged for
     * Task 9 rather than swept here (E68 §3).
     */
    private function assertRejectionAudited(): void
    {
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferRejected',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $this->transfer->getKey(),
            'subject_label' => 'Ownership transfer request',
            'company_id' => $this->company->getKey(),
            'action' => 'transfer_rejected',
            'description' => 'Platform admin rejected the ownership transfer; the current owner retains ownership.',
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
        ]);

        $entry = AuditEntry::query()->where('event_class', 'OwnershipTransferRejected')->sole();

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
     * The RENDERED BODY is asserted too, not just the constructor field (added at
     * the s152 review). The owner reads the body, not the property: with only the
     * field pinned, a body() that stopped interpolating the reason would ship
     * green while silently breaking the UC's headline promise that the rejection
     * reason reaches the requester VERBATIM. The T1 and T2 retrofits already
     * assert their bodies; this one did not, which made the set asymmetric.
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
                && $notification->transfer->getKey() === $this->transfer->getKey()
                && str_contains($notification->toArray($this->owner)['body'], self::rejectionBody()['reason']),
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
     *
     * $submitted is the body that was actually posted, so both windows can assert
     * their own reason and code. It was a single-call-site helper reading the
     * default body until s152, when the T5 walk gained its own event assertion
     * (it had been asserting only the response envelope) and needed a second
     * vocabulary.
     *
     * @param  array<string, mixed>  $submitted
     */
    private function assertRejectedEventRecorded(string $expectedSource, array $submitted): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRejected::class)
            ->whereForCompany($this->company->getKey())
            ->sole();

        $this->assertSame($this->transfer->getKey(), $stored->event_properties['requestId']);
        $this->assertSame($this->admin->getKey(), $stored->event_properties['rejectedBy']);
        $this->assertSame($submitted['reason'], $stored->event_properties['rejectionReason']);
        $this->assertSame($submitted['rejectionReasonCode'], $stored->event_properties['rejectionReasonCode']);
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
