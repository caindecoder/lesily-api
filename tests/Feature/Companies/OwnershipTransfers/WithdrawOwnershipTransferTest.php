<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Carbon\CarbonImmutable;
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
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferWithdrawnNotification;

/**
 * SM-1-05 T6 + T7 (UC-1-114 pre-verification, UC-1-115 post-verification) — the
 * original requester withdraws their own transfer, from `Requested` or from
 * `Verified`. One endpoint, one Command, one aggregate method; the aggregate
 * picks the window from its own state, exactly as `reject()` does for T3/T5.
 *
 * ONE class for both windows (§2.14): same seam, same actor, same endpoint — the
 * T6-vs-T7 distinction is scenario-level, not a split trigger. Same reasoning
 * that kept both reject windows together at s151; LeaveDates likewise splits its
 * cancel tests by ACTOR (Subordinate / Admin / Approver), never by transition.
 */
class WithdrawOwnershipTransferTest extends OwnershipTransfersTestCase
{
    private const string WITHDRAWAL_REASON = 'Decided to keep ownership after all.';

    #[Test]
    public function when_the_withdrawal_is_unauthenticated_then_it_is_refused(): void
    {
        // Given an open transfer belonging to a company
        [$company] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When it is withdrawn with no session at all
        $response = $this->withdrawTransferAsGuest((string) $transfer->getKey());

        // Then the request never reaches the use case
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    /**
     * The UC's whole "Denied Roles" line in one row. The designated TARGET user
     * is refused here too: they hold an ordinary member role, and only `owner`
     * carries `ownership-transfer.withdraw` (matrix Module 8 after the J1
     * collapse). Withdrawal is the requester's authority, never the target's.
     */
    #[Test]
    #[DataProvider('provideRolesWithoutWithdrawPermission')]
    public function when_the_caller_lacks_the_withdraw_permission_then_the_transfer_is_not_withdrawn(string $roleCode): void
    {
        // Given an open transfer and a member holding some other role
        [$company] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);
        $caller = $this->aMemberWithRole($company, $roleCode);

        // When that member tries to withdraw it
        $response = $this->withdrawTransfer([$company, $caller], (string) $transfer->getKey());

        // Then the permission gate refuses and the transfer is untouched
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an owner with no transfer under the requested id
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they withdraw an id that names nothing
        $response = $this->withdrawTransfer([$company, $owner], (string) Str::uuid7());

        // Then the absence is reported as a plain not-found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    /**
     * An owner may hold `ownership-transfer.withdraw` and still not reach
     * another tenant's transfer: the id is existence-hidden, so a foreign
     * transfer and an absent one are byte-identical on the wire.
     */
    #[Test]
    public function when_the_transfer_belongs_to_another_company_then_it_is_not_found(): void
    {
        // Given an owner, and an open transfer at a different company
        [$company, $owner] = $this->aCompanyWithOwner();
        $foreign = $this->aBystanderTransferAtAnotherCompany();

        // When the owner withdraws the foreign transfer through their own context
        $response = $this->withdrawTransfer([$company, $owner], (string) $foreign->getKey());

        // Then it is refused as absent, and the foreign row is untouched
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertTransferStillOpen((string) $foreign->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * Holding the permission is not the same as being the requester (ruling J2).
     * The guard sits in the interactor and reads the PROJECTION before
     * `retrieve()`, so a factory row exercises it honestly — this is the one row
     * in the class that needs no real stream, because no stream is ever replayed
     * on this path.
     *
     * The arrangement is a state production cannot currently emit: an open
     * transfer's `current_owner_user_id` is always the company's owner, since
     * only the owner may request one and `users.change-role` refuses to mint a
     * second owner. The guard is defence for the day that stops being true —
     * self-service transfer (OQ-1-D18) is the named candidate — and it is a
     * WATCHED RED here, not a born-green pin.
     */
    #[Test]
    public function when_the_caller_is_not_the_original_requester_then_the_withdrawal_is_refused(): void
    {
        // Given an open transfer this owner did not raise
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferRaisedBySomeoneElse($company);

        // When the owner tries to withdraw it
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey());

        // Then identity, not role, is what refuses them
        $this->assertRequesterMismatchRefused($response);
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * The only shape a withdrawal can get wrong. `reason` is optional and
     * `cancelledBy` is auth-resolved, so there is no required-field matrix here —
     * a DataProvider would pad the row count without adding evidence, which is
     * precisely what s152 found when it re-checked reject's seven-row provider
     * and could only give two of them teeth.
     */
    #[Test]
    public function when_the_withdrawal_reason_exceeds_the_bound_then_it_is_refused(): void
    {
        // Given an open transfer this owner raised
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When they withdraw it with prose one character past the contract
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => str_repeat('a', 1001),
        ]);

        // Then the boundary refuses it and the transfer stands
        $this->assertValidationRejected($response, 'reason');
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * An open transfer at $company whose recorded requester is a third user.
     * A named fixture helper carrying the column override is the sanctioned
     * shape — §2.13 bans inline overrides in test BODIES, not in fixtures
     * (the `aTransferDueAt` precedent).
     */
    private function aTransferRaisedBySomeoneElse(Company $company): OwnershipTransferRequest
    {
        return OwnershipTransferRequest::factory()->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => User::factory()->create()->getKey(),
            'target_user_id' => User::factory()->create()->getKey(),
        ]);
    }

    /**
     * The degenerate case for an aggregate command: the transition fired twice.
     * Arranged through a REAL prior T6, so the guard refuses because the state
     * genuinely IS terminal — a factory row would replay an empty stream and
     * refuse for an unrelated reason (s149 C1).
     */
    #[Test]
    public function when_the_transfer_is_already_withdrawn_then_it_is_not_withdrawn_again(): void
    {
        // Given a transfer its requester has already withdrawn
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aCancelledTransferStream($company, $owner);
        $original = $this->currentTransfer($transfer);

        // When they withdraw it a second time
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the state guard refuses and the first withdrawal is untouched
        $this->assertStateGuardRefused($response, 'Only an open transfer can be withdrawn.');
        $this->assertOriginalWithdrawalIntact($original);
    }

    /**
     * A second terminal state, reached by a DIFFERENT actor down a different
     * path. E28 §5 wants the transition table enumerated rather than a single
     * representative refusal, and `Rejected` is the other terminal currently
     * reachable — `Executed` and `Expired` arrive with Tasks 8 and 7, which own
     * their own rows here (the s151 G1 split).
     *
     * Born green off lap 9's `default` arm, which is the shared-mechanism case
     * E12 sanctions; its teeth are that arm's, proven there.
     */
    #[Test]
    public function when_the_transfer_was_already_rejected_then_it_is_not_withdrawn(): void
    {
        // Given a transfer a platform admin has already rejected
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRejectedTransferStream($company, $owner, User::factory()->create());

        // When its requester tries to withdraw it anyway
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the state guard refuses and the rejection stands
        $this->assertStateGuardRefused($response, 'Only an open transfer can be withdrawn.');
        $this->assertTransferStillRejected((string) $transfer->getKey());
    }

    /**
     * The rejection survives a withdrawal attempt: still `Rejected`, and none of
     * the cancellation columns were written over it.
     */
    private function assertTransferStillRejected(string $requestId): void
    {
        $this->assertDatabaseHas('ownership_transfer_requests', [
            'id' => $requestId,
            'status' => OwnershipTransferStatus::Rejected->value,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_source' => null,
            'cancellation_reason' => null,
        ]);
    }

    /**
     * The first withdrawal's record survives the second attempt byte for byte —
     * a refusal that quietly overwrote the original reason or actor would still
     * return 409 and pass a status-only assertion.
     */
    private function assertOriginalWithdrawalIntact(OwnershipTransferRequest $original): void
    {
        $current = $this->currentTransfer($original);
        $this->assertSame($original->cancellation_reason, $current->cancellation_reason);
        $this->assertSame($original->cancelled_by, $current->cancelled_by);
        $this->assertEquals($original->cancelled_at, $current->cancelled_at);
        $this->assertDatabaseCount('stored_events', 2);
    }

    /**
     * SM-1-05 T6 (UC-1-114) — the pre-verification window, end to end.
     *
     * Arranged through a REAL T1 stream: the aggregate must replay genuine
     * history for the `Requested` arm of `withdraw()`'s match to be the thing
     * under test. A factory row would replay an empty stream and the guard would
     * refuse for the wrong reason (s149 C1).
     */
    #[Test]
    public function when_an_owner_withdraws_a_requested_transfer_then_it_is_cancelled_before_verification(): void
    {
        // Given a transfer whose stream carries the real T1 genesis
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());

        // When the requester withdraws it before any verification has happened
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the pre-verification window is recorded end to end
        $this->assertWithdrawalRecorded($response, $owner, 'pre-verification');
    }

    /**
     * SM-1-05 T7 (UC-1-115) — the post-verification window.
     *
     * The asymmetry with T6 is the point: admin verification work HAS happened,
     * so the prior T2 record must survive the withdrawal intact, and the timer
     * being cleared here is a DIFFERENT value (`verified_at + 14d`, not
     * `requested_at + 30d`). At s153 the reject twin turned out to have asserted
     * only one of its two windows' clears, so both are pinned here.
     */
    #[Test]
    public function when_an_owner_withdraws_a_verified_transfer_then_it_is_cancelled_after_verification(): void
    {
        // Given a transfer a platform admin has already identity-verified
        [$company, $owner] = $this->aCompanyWithOwner();
        $verifier = User::factory()->create();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $verifier);

        // When the requester withdraws it before it can be executed
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the post-verification window is recorded, verification intact
        $this->assertWithdrawalRecorded($response, $owner, 'post-verification');
        $this->assertVerificationRecordPreserved($verifier);
    }

    /**
     * API §7 OQ#2, and the precise asymmetry UC-1-115 turns on: a verified
     * transfer that the requester withdraws must LEAVE the platform-admin queue,
     * so no admin is left holding a work item that no longer exists.
     *
     * Asserted through the real admin endpoint rather than by restating its
     * query — the queue defaults to the OPEN set (F5), so a terminal row drops
     * out on its own when `status` flips, and this proves that rather than
     * assuming it.
     */
    #[Test]
    public function when_a_verified_transfer_is_withdrawn_then_it_leaves_the_admin_queue(): void
    {
        // Given a verified transfer sitting in the admin execution queue
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->aPlatformSupportAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, User::factory()->create());
        $this->assertQueueContains($this->getAdminQueueAs($admin), $transfer);

        // When the requester withdraws it
        $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the admin's queue no longer offers it for execution
        $this->assertQueueOmits($this->getAdminQueueAs($admin), $transfer);
    }

    private function assertQueueContains(TestResponse $response, OwnershipTransferRequest $transfer): void
    {
        $response->assertOk();
        $this->assertContains((string) $transfer->getKey(), $response->collect('data')->pluck('id')->all());
    }

    private function assertQueueOmits(TestResponse $response, OwnershipTransferRequest $transfer): void
    {
        $response->assertOk();
        $this->assertNotContains((string) $transfer->getKey(), $response->collect('data')->pluck('id')->all());
    }

    /**
     * The reason this transition is a PATCH and not a DELETE (slice decision D2).
     * A withdrawn request stays readable in the company's own history — that is
     * what makes `cancelled` a state rather than a disappearance, and it is why
     * the API doc's original `DELETE /{id}` misrepresented the semantics.
     */
    #[Test]
    public function when_a_transfer_is_withdrawn_then_it_remains_readable_in_the_company_history(): void
    {
        // Given a transfer the requester withdraws
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());
        $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // When the company reads its transfer history
        $response = $this->getTransferHistory($owner, $company);

        // Then the withdrawn request is still there, in its terminal state
        $response->assertOk();
        $response->assertJsonPath('data.0.id', (string) $transfer->getKey());
        $response->assertJsonPath('data.0.status', OwnershipTransferStatus::Cancelled->value);
    }

    /**
     * The projector's `whereKey` scope, made observable. With a single row in
     * the fixture an unscoped update hits the right one by accident and the
     * assertion proves nothing — s149 C6, and the reason a bystander exists at a
     * second company here.
     */
    #[Test]
    public function when_a_transfer_is_withdrawn_then_no_other_tenants_transfer_is_touched(): void
    {
        // Given this company's transfer and an untouched one elsewhere
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());
        $bystander = $this->aBystanderTransferAtAnotherCompany();

        // When this one is withdrawn
        $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the other tenant's transfer is exactly as it was
        $this->assertTransferStillOpen((string) $bystander->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * H4 parity with reject, and the deliberate asymmetry with verify.
     *
     * §S10b's legal hold freezes ownership CHANGES; a withdrawal changes no
     * ownership and touches no pivot row, so it must still succeed. Blocking it
     * would strand held tenants with permanently un-closable requests, since the
     * expiry scan skips them too (slice D3). Verify refuses under hold (s149 B1);
     * withdraw deliberately does not.
     */
    #[Test]
    public function when_the_company_is_under_legal_hold_then_the_transfer_is_still_withdrawn(): void
    {
        // Given an open transfer at a company under regulatory hold
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());
        $this->placeCompanyUnderLegalHold($company);

        // When the requester withdraws it
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the hold does not stand in the way
        $this->assertWithdrawalRecorded($response, $owner, 'pre-verification');
    }

    /**
     * `cancelled_at` comes from the server clock, not the client and not the
     * row's own timestamps. Travelling to a fixed instant is the only way to
     * tell a real stamp from `now()` evaluated twice.
     */
    #[Test]
    public function when_a_transfer_is_withdrawn_then_the_record_is_stamped_with_the_server_clock(): void
    {
        // Given an open transfer and a clock pinned to a known instant
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());
        $instant = CarbonImmutable::parse('2026-08-01 09:30:00');
        $this->travelTo($instant);

        // When it is withdrawn
        $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the stamp is that instant, to the second
        $this->assertSame(
            $instant->toDateTimeString(),
            $this->currentTransfer($transfer)->cancelled_at?->toDateTimeString(),
        );
    }

    /**
     * The actor is auth-resolved and the body cannot override it.
     *
     * What REDs this test was MEASURED, not reasoned — the reject twin carried a
     * hypothesis that was wrong twice running (s152 replaced a false `all()`
     * claim with an ORDERING claim that was also false). Both variants were run:
     *
     *   A. body key admitted via `only([...,'cancelledBy'])`, auth assigned
     *      LAST  → PASSES. Later-key-wins, so admitting the key is harmless.
     *   B. auth assigned first, body spread AFTER it        → REDs.
     *
     * So this row binds the ORDER, not the key list. Widening `only()` alone
     * would not be caught here, and that is worth knowing rather than assuming.
     */
    #[Test]
    public function when_the_body_names_a_different_canceller_then_the_authenticated_owner_is_recorded(): void
    {
        // Given an open transfer and an impostor id in the body
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());
        $impostor = User::factory()->create();

        // When the withdrawal claims someone else performed it
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
            'cancelledBy' => (string) $impostor->getKey(),
        ]);

        // Then the authenticated owner is what gets recorded
        $response->assertOk();
        $this->assertSame($owner->getKey(), $this->currentTransfer($transfer)->cancelled_by);
    }

    /**
     * `reason` is optional, and this is the row that says so. Its refusal twin
     * pins the bound from OUTSIDE; between them the contract is closed at both
     * ends, which is the D-f lesson from the s149 ledger — a bound pinned only
     * from one side lets the opposite change ship silently.
     */
    #[Test]
    public function when_the_withdrawal_omits_a_reason_then_it_is_accepted(): void
    {
        // Given an open transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());

        // When it is withdrawn with no reason at all
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey());

        // Then it succeeds and the column records the absence honestly
        $response->assertOk();
        $this->assertDatabaseHas('ownership_transfer_requests', [
            'id' => $transfer->getKey(),
            'status' => OwnershipTransferStatus::Cancelled->value,
            'cancellation_reason' => null,
        ]);
    }

    /**
     * The 1000-character bound from the INSIDE. `max:1000` is a string literal,
     * so Infection generates no mutant for it — without an accept row sitting
     * exactly on the bound, a tightened rule would ship green (the D-f finding,
     * paid on the reject twin at s152).
     */
    #[Test]
    public function when_the_withdrawal_reason_sits_at_the_edge_of_the_contract_then_it_is_accepted(): void
    {
        // Given an open transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());
        $reason = str_repeat('a', 1000);

        // When it is withdrawn with prose exactly on the bound
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => $reason,
        ]);

        // Then it is accepted and stored verbatim, not truncated
        $response->assertOk();
        $this->assertSame($reason, $this->currentTransfer($transfer)->cancellation_reason);
    }

    /**
     * SM-1-05 §7 puts the audit reactor on every transition, and both UC
     * postconditions name the row explicitly: `cancelledBy`, the `source`
     * discriminator and the optional reason.
     *
     * The ACTOR is the point. This is the only record of who ended a transfer,
     * and it is the tenant-side counterpart of the rejection row — Lesily Legal's
     * chain-of-custody reads "the requester withdrew it", not "it stopped".
     */
    #[Test]
    public function when_a_transfer_is_withdrawn_then_the_audit_trail_records_who_ended_it(): void
    {
        // Given an open transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());

        // When the requester withdraws it
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then one audit row attributes it to them
        $response->assertOk();
        $this->assertWithdrawalAudited($transfer, $owner);
    }

    /**
     * One row, carrying the tenant, the subject, the actor and the link back to
     * the durable event. `actor_user_id` is nullable, so without pinning it a
     * regression that dropped the actor would keep CI green while destroying
     * exactly the evidence the row exists to hold.
     *
     * `stored_event_id`, `actor_role_code`, `description` and `subject_label` are
     * pinned because a combined revert-check showed none of them were: the row
     * could lose its stored_event_id entirely, name the actor's role as `admin`,
     * and carry a different description, with all 92 rows still green. The first
     * of those is the whole claim this docblock makes — the link back to the
     * durable event — and it is a nullable soft FK, so a dropped value inserts
     * happily. The role code is the sentence Lesily Legal reads: this row must
     * say the REQUESTER ended it, not a platform admin.
     *
     * The count guards a twice-firing reactor: two rows total, T1 genesis + this
     * withdrawal, which the reject twin pins the same way.
     */
    private function assertWithdrawalAudited(OwnershipTransferRequest $transfer, User $requester): void
    {
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $transfer->company_id,
            'event_class' => 'OwnershipTransferCancelled',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $transfer->getKey(),
            'subject_label' => 'Ownership transfer request',
            'action' => 'transfer_withdrawn',
            'description' => 'The requester withdrew the ownership transfer; the current owner retains ownership.',
            'actor_user_id' => $requester->getKey(),
            'actor_role_code' => 'owner',
            'actor_label' => $requester->name,
        ]);

        $this->assertDatabaseCount('audit_trail', 2);
        $this->assertNotNull(
            AuditEntry::query()->where('event_class', 'OwnershipTransferCancelled')->sole()->stored_event_id,
        );
    }

    /**
     * The Minimal Guarantee of BOTH UCs, stated identically in each: roles on
     * `company_user` are NEVER mutated by this path, and neither is
     * `companies.owner_id`. The pivot swap is exclusive to UC-1-093 (T4).
     *
     * The target is attached as a REAL member, and that is load-bearing rather
     * than decorative. Written first against `aVerifiedTransferStream`, this test
     * was TOOTHLESS: that helper creates its target with `User::factory()` and
     * never attaches it to `company_user`, so a revert-check that swapped the
     * target's pivot role matched zero rows and the assertion stayed green. It is
     * deferral E-b from the s153 ledger, met a task earlier than expected.
     * Production could not produce that fixture either — UC-1-028 requires the
     * target to be a company member.
     */
    #[Test]
    public function when_a_transfer_is_withdrawn_then_the_ownership_records_are_left_untouched(): void
    {
        // Given an open transfer whose target really is a member of the company
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aRequestedTransferStream($company, $owner, $target);
        $rolesBefore = $this->currentPivotRoles($company);

        // When the requester withdraws it
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the transfer ended and nobody's ownership moved
        $response->assertOk();
        $this->assertOwnershipRecordsUntouched($company, $rolesBefore);
    }

    /**
     * @return array<string, string>
     */
    private function currentPivotRoles(Company $company): array
    {
        return CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->pluck('role_id', 'user_id')
            ->all();
    }

    /**
     * @param  array<string, string>  $rolesBefore
     */
    private function assertOwnershipRecordsUntouched(Company $company, array $rolesBefore): void
    {
        $this->assertSame($rolesBefore, $this->currentPivotRoles($company));
        $this->assertSame(
            $company->owner_id,
            Company::query()->whereKey($company->getKey())->value('owner_id'),
        );
    }

    /**
     * Ruling J4 — the support team hears about the withdrawal in BOTH windows.
     *
     * The same reactor announces a transfer's arrival at T1, so staying silent
     * on T6 would leave support hearing about arrivals and never about
     * pre-verification disappearances. UC-1-114's "no admin-side cleanup
     * required" concerns the queue ROW, which leaves the open set on its own
     * when `status` flips.
     *
     * The requester is deliberately NOT notified: they are the actor here, the
     * mirror-image of reject, where the admin acts and the requester is told.
     */
    #[Test]
    public function when_a_transfer_is_withdrawn_then_the_support_queue_is_notified(): void
    {
        // Given an open transfer and an allowlisted support admin
        [$company, $owner] = $this->aCompanyWithOwner();
        $supportAdmin = $this->aPlatformSupportAdmin();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());

        // When the requester withdraws it
        $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then support is told, and the actor is not told about their own act
        $this->assertSupportQueueNotifiedOfWithdrawal($supportAdmin, $company, $owner);
    }

    /**
     * The OTHER half of ruling J4, and a separate row rather than a provider
     * because only this window has a verification that had to happen first.
     *
     * It exists because a revert-check proved the T6 row did not hold J4 up on
     * its own: filtering the reactor to `pre-verification` silenced
     * post-verification withdrawals entirely and all 91 rows stayed green. This
     * is the costlier window to lose — an admin has already spent verification
     * effort on the transfer being withdrawn. Same shape as the s153 finding on
     * the reject twin, where only one of two windows had ever been asserted.
     */
    #[Test]
    public function when_a_verified_transfer_is_withdrawn_then_the_support_queue_is_notified(): void
    {
        // Given a verified transfer and an allowlisted support admin
        [$company, $owner] = $this->aCompanyWithOwner();
        $supportAdmin = $this->aPlatformSupportAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, User::factory()->create());

        // When the requester withdraws it after verification
        $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then support is told in this window too
        $this->assertSupportQueueNotifiedOfWithdrawal($supportAdmin, $company, $owner);
    }

    /**
     * Named recipients, not merely "a notification was sent". The body is
     * rendered so `companyName()` is actually observed — a static-substring
     * check alone passes on an empty or wrong tenant name, which is the
     * regression the s152 review caught in the T1/T2 retrofit.
     */
    private function assertSupportQueueNotifiedOfWithdrawal(User $supportAdmin, Company $company, User $requester): void
    {
        Notification::assertSentTo(
            $supportAdmin,
            OwnershipTransferWithdrawnNotification::class,
            fn (OwnershipTransferWithdrawnNotification $notification): bool => $notification->transfer->company_id === $company->getKey()
                && $notification->toArray($supportAdmin)['title'] === 'Ownership transfer withdrawn'
                && str_contains($notification->toArray($supportAdmin)['body'], 'withdrawn')
                && str_contains($notification->toArray($supportAdmin)['body'], $company->name),
        );
        Notification::assertNotSentTo($requester, OwnershipTransferWithdrawnNotification::class);
    }

    /**
     * The T2 evidence outlives the withdrawal. UC-1-115's legal-defensibility
     * chain reads "verifier did their job; the requester later abandoned it", so
     * the verifier's attribution and notes must still be readable on the
     * cancelled row — and the T2 event itself is still in the stream.
     */
    private function assertVerificationRecordPreserved(User $verifier): void
    {
        $transfer = OwnershipTransferRequest::query()->sole();
        $this->assertSame($verifier->getKey(), $transfer->verified_by);
        $this->assertNotNull($transfer->verified_at);
        $this->assertDatabaseHas('stored_events', [
            'event_class' => OwnershipTransferIdentityVerified::class,
        ]);
    }

    /**
     * The whole write: the response, the stored event, the projected row and the
     * SLA timer that a terminal state must not keep.
     *
     * The stored-event assertion is amendment R: a direct read-model write would
     * leave identical columns behind and satisfy every response-level check, so
     * only the event lookup can tell the event-sourced write apart from one that
     * a projection replay would silently revert.
     *
     * $expectedSource is a LITERAL, deliberately, where it used to be a
     * TransitionSource case. The enum form made this assertion read its expected
     * value from the very symbol production writes, so both sides moved together:
     * re-spelling the case as `post_verification` left all 91 rows in this
     * directory green. ERD-1-05 §3 names the hyphenated spelling as canon, and
     * `post-verification` is produced by nothing but T7 — so this is the only
     * place in the suite where that literal is pinned at all.
     *
     * `updated_by` is in the map because the projector writes it and nothing
     * asserted it — an `ArrayItemRemoval` mutant that Infection does generate.
     * The projection is ONE column map rather than six assertSame calls: §2.2
     * Rule 5 caps a helper at 2-5 aspects, and the reject twin was collapsed the
     * same way at s152.
     */
    private function assertWithdrawalRecorded(TestResponse $response, User $requester, string $expectedSource): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.status', OwnershipTransferStatus::Cancelled->value);

        $this->assertDatabaseHas('stored_events', [
            'event_class' => OwnershipTransferCancelled::class,
        ]);

        $this->assertDatabaseHas('ownership_transfer_requests', [
            'status' => OwnershipTransferStatus::Cancelled->value,
            'cancellation_source' => $expectedSource,
            'cancellation_reason' => self::WITHDRAWAL_REASON,
            'cancelled_by' => $requester->getKey(),
            'updated_by' => $requester->getKey(),
            'next_state_transition_at' => null,
        ]);

        $this->assertNotNull(OwnershipTransferRequest::query()->sole()->cancelled_at);
    }

    /**
     * Every seeded company role except `owner`.
     *
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutWithdrawPermission(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    /**
     * The transfer is still in $expected, and no terminal columns were written —
     * a refusal that leaves the row alone is the assertion, not merely the
     * status code.
     */
    private function assertTransferStillOpen(string $requestId, OwnershipTransferStatus $expected): void
    {
        $this->assertDatabaseHas('ownership_transfer_requests', [
            'id' => $requestId,
            'status' => $expected->value,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_source' => null,
        ]);
    }
}
