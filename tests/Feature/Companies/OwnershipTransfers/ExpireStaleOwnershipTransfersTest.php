<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExpireOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferExpiredNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferVerificationLapsedNotification;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * SM-1-05 T8 + T9 — the daily `ownership-transfers:expire-stale` scan ends
 * transfers that outlived their SLA window (UC-1-116 requested-stage 30d,
 * UC-1-117 verified-stage 14d). The slice's first SYSTEM flow: no HTTP surface,
 * no role gate, no tenant context — the actor is the scheduler.
 *
 * EVERY row here arranges a REAL stream and travels past the deadline. The scan
 * reads two different sources — selection queries the projection, the transition
 * replays the stream — so a factory row satisfies the first and is EMPTY for the
 * second. Under ruling M1 the aggregate would then see a null status, take the
 * no-op arm and record nothing: the scan reports "0 expired" and a careless test
 * passes for entirely the wrong reason. That is s149 C1 with a silent failure
 * mode instead of a throwing one.
 */
class ExpireStaleOwnershipTransfersTest extends OwnershipTransfersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
    }

    // Sad — a transfer still inside its 30-day window is not due, so the daily
    // scan selects nothing and the request stays open (the `<` cutoff excludes it).
    #[Test]
    public function when_no_transfer_has_passed_its_deadline_then_the_scan_expires_nothing(): void
    {
        // Given a requested transfer still within its window
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        // When the daily scan runs while the window is still open
        $this->runExpiryScan()->expectsOutputToContain('0 transfer(s) expired.')->assertSuccessful();

        // Then the transfer is untouched
        $this->assertTransferStillOpen($transfer);
    }

    // Sad — a transfer that already ended is never re-ended. Arranged through a
    // REAL T1 → T6 stream, so the exclusion under test is the one production
    // produces rather than a factory row's.
    //
    // BORN GREEN, and stated as such rather than left to read as proof. The
    // design surface (ERD-1-05 §4, both UC bodies, cd §3) describes the scan as
    // `next_state_transition_at < now() AND status IN ('requested','verified')`,
    // but the status half has no reachable failure: every terminal transition
    // CLEARS the timer in the same projector update, and `NULL < cutoff` is false
    // in SQL, so a terminal row is already unselectable. Adding the predicate
    // anyway would be a third mechanism guarding a case the other two make
    // impossible — a row that reads as evidence and pins nothing (the s151 E2 /
    // D-g class). This test therefore CHARACTERISES the timer-clear exclusion; it
    // does not prove a status filter, because there is none to prove.
    //
    // The residual risk is Task 8's: if `execute()` ever lands a terminal row
    // WITHOUT clearing the timer, the scan would re-select it every night and
    // M1's no-op would silently skip it forever. That is assertable where the
    // arm is written, and belongs there.
    #[Test]
    public function when_a_transfer_has_already_ended_then_the_scan_leaves_it_untouched(): void
    {
        // Given a withdrawn transfer, now well past the deadline it once carried
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aCancelledTransferStream($company, $owner);
        $this->travel(31)->days();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('0 transfer(s) expired.')->assertSuccessful();

        // Then it is untouched — still cancelled, with no expiry event
        $this->assertTransferStillOpen($transfer);
    }

    // Sad (slice-#11 D3, skip-only legal hold) — a company frozen by a regulatory
    // hold is excluded from the scan, so its overdue transfer survives while an
    // unheld one beside it expires. The second transfer is load-bearing: with one
    // company in the fixture a scan that skipped EVERYTHING would look identical
    // (s149 C6 — a scope assertion needs a row on the other side of the scope).
    #[Test]
    public function when_the_company_is_under_legal_hold_then_its_overdue_transfer_is_left_open(): void
    {
        // Given two equally overdue transfers, one at a company under legal hold
        [$heldCompany, $heldOwner] = $this->aCompanyWithOwner();
        $this->placeCompanyUnderLegalHold($heldCompany);
        $held = $this->aRequestedTransferStream($heldCompany, $heldOwner, $this->aMemberWithRole($heldCompany, 'employee'));

        [$freeCompany, $freeOwner] = $this->aCompanyWithOwner();
        $free = $this->aRequestedTransferStream($freeCompany, $freeOwner, $this->aMemberWithRole($freeCompany, 'employee'));

        $this->travel(31)->days();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();

        // Then only the unheld company's transfer expired
        $this->assertTransferStillOpen($held);
        $this->assertTransferExpiredAtStage($free, 'requested-stage', 30);
    }

    // Edge (boundary) — a transfer expires only once its deadline has elapsed
    // STRICTLY. The exactly-at row is the one that kills a `<` -> `<=` mutation;
    // both built scans carry the same three-row provider.
    #[Test]
    #[DataProvider('provideDeadlineBoundary')]
    public function when_the_scan_runs_then_a_transfer_expires_only_once_its_deadline_has_elapsed(
        string $runAt,
        string $expectedOutput,
    ): void {
        // Given a requested transfer whose 30-day deadline falls on 2026-08-01 09:00:00
        [$company, $owner] = $this->aCompanyWithOwner();
        $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        // When the daily scan runs at a given instant relative to that deadline
        $this->travelTo(CarbonImmutable::parse($runAt));

        // Then it expires only if the deadline elapsed before the cutoff
        $this->runExpiryScan()->expectsOutputToContain($expectedOutput)->assertSuccessful();
    }

    /**
     * The stream is arranged at T0 = 2026-07-02 09:00:00, so the T1 projector
     * writes `next_state_transition_at` = T0 + 30d = 2026-08-01 09:00:00. The
     * exactly-at row makes `next_state_transition_at < now()` strictly false.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideDeadlineBoundary(): array
    {
        return [
            'a day before the deadline' => ['2026-07-31 09:00:00', '0 transfer(s) expired.'],
            'exactly at the deadline' => ['2026-08-01 09:00:00', '0 transfer(s) expired.'],
            'a second after the deadline' => ['2026-08-01 09:00:01', '1 transfer(s) expired.'],
        ];
    }

    // Edge (idempotency) — a second scan does not expire an already-expired
    // transfer. The exclusion is the terminal timer clear, and re-selection would
    // be visible in the count even though M1's no-op would stop a second event.
    #[Test]
    public function when_an_expired_transfer_is_scanned_again_then_it_is_not_expired_a_second_time(): void
    {
        // Given a transfer an earlier scan already expired
        $transfer = $this->anOverdueRequestedTransfer();
        $this->runExpiryScan()->assertSuccessful();

        // When the daily scan runs again
        $this->runExpiryScan()->expectsOutputToContain('0 transfer(s) expired.')->assertSuccessful();

        // Then the expiry was recorded exactly once
        $this->assertTransferExpiredAtStage($transfer, 'requested-stage', 30);
    }

    // Edge (batch) — one run ends EVERY due transfer across tenants, not just the
    // first, and leaves an in-window one open. Three companies, because the scan
    // is cross-tenant by design and a single-tenant fixture would not show it.
    #[Test]
    public function when_transfers_are_overdue_across_several_companies_then_every_due_one_expires(): void
    {
        // Given two overdue transfers at different companies, and one still in-window
        $firstDue = $this->anOverdueRequestedTransfer();
        $secondDue = $this->anOverdueRequestedTransfer();

        [$freshCompany, $freshOwner] = $this->aCompanyWithOwner();
        $inWindow = $this->aRequestedTransferStream($freshCompany, $freshOwner, $this->aMemberWithRole($freshCompany, 'employee'));

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('2 transfer(s) expired.')->assertSuccessful();

        // Then both due transfers expired and the in-window one is untouched
        $this->assertTransferExpiredAtStage($firstDue, 'requested-stage', 30);
        $this->assertTransferExpiredAtStage($secondDue, 'requested-stage', 30);
        $this->assertTransferStillOpen($inWindow);
    }

    // Edge (ruling M1's no-op arm) — an expiry that arrives after the transfer
    // already left the open set records nothing at all. This is the scan racing a
    // human: an admin verifying or executing, or the owner withdrawing, between
    // the SELECT and this row's write.
    //
    // Driven through the AGGREGATE directly, because the repository's timer
    // predicate makes the arm unreachable from the console — the same shape
    // ExpireTrialsCommandTest uses for its own console-unreachable no-op arms.
    #[Test]
    #[DataProvider('provideNonOpenStates')]
    public function when_the_transfer_is_no_longer_open_at_fire_time_then_no_expiry_is_recorded(string $arrangement): void
    {
        // Given a transfer that already reached a terminal state
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTerminalTransferStream($arrangement, $company, $owner);
        $expiriesBefore = $this->expiryEventCount($transfer);

        // When an expiry fires for it anyway
        OwnershipTransferRequestAggregate::retrieve($transfer->getKey())
            ->expireIfStale(ExpireOwnershipTransferCommand::validateAndCreate([
                'requestId' => $transfer->getKey(),
                'expiredAfterDays' => 30,
            ]))
            ->persist();

        // Then nothing changed and no FURTHER expiry was recorded
        $this->assertNothingFurtherRecorded($transfer, $expiriesBefore);
    }

    /**
     * $transfer is exactly as it was: same state, same deadline, and no expiry
     * event beyond the $expiriesBefore it already carried.
     *
     * The before-count is a parameter rather than a hard zero, and the
     * already-expired data set is why: that arrangement legitimately carries one
     * expiry event, so a "no expiry events" assertion would fail for the right
     * reason on the wrong row. What this proves is that the no-op arm added
     * NOTHING, which is the claim regardless of what the stream already held.
     */
    protected function assertNothingFurtherRecorded(OwnershipTransferRequest $transfer, int $expiriesBefore): void
    {
        $current = $this->currentTransfer($transfer);

        $this->assertSame($transfer->status, $current->status);
        $this->assertEquals($transfer->next_state_transition_at, $current->next_state_transition_at);
        $this->assertSame($expiriesBefore, $this->expiryEventCount($transfer));
    }

    protected function expiryEventCount(OwnershipTransferRequest $transfer): int
    {
        return LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExpired::class)
            ->where('event_properties->requestId', $transfer->getKey())
            ->count();
    }

    /**
     * The terminal states an expiry can collide with TODAY. `Executed` is absent
     * because `execute()` does not exist until Task 8 — the same reason rows were
     * deferred out of Tasks 5 and 6, not an oversight.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideNonOpenStates(): array
    {
        return [
            'a withdrawn transfer' => ['cancelled'],
            'a rejected transfer' => ['rejected'],
            'an already-expired transfer' => ['expired'],
        ];
    }

    protected function aTerminalTransferStream(string $arrangement, Company $company, User $owner): OwnershipTransferRequest
    {
        return match ($arrangement) {
            'cancelled' => $this->aCancelledTransferStream($company, $owner),
            'rejected' => $this->aRejectedTransferStream($company, $owner, $this->aPlatformSupportAdmin()),
            default => $this->anExpiredTransferStream($company, $owner),
        };
    }

    // Edge (email leg) — the requester's notice renders, and says which company
    // and that a fresh request is possible.
    //
    // This row exists because the base test case fakes notifications, so no
    // channel method ever runs: `title()`, `body()`, `toMail()`, the envelope and
    // the Blade view would ALL be unexecuted by the suite without it, and
    // codecov/patch would reject them. That is not hypothetical — at s152 the
    // entire email leg of this module turned out to be exercised by zero tests
    // for exactly this reason.
    #[Test]
    public function when_an_expiry_notice_is_rendered_then_it_names_the_company_and_the_resubmission_path(): void
    {
        // Given a transfer at a company with a known name
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        // When the expiry notice is rendered for its requester
        $mail = (new OwnershipTransferExpiredNotification($transfer))->toMail($owner);

        // Then it carries the subject, the company and the resubmission path
        $this->assertNoticeRenders($mail, 'Ownership transfer expired', 'raise a fresh request');
    }

    // Edge (email leg) — the verifier's notice renders, and says the transfer
    // THEY verified lapsed. Same coverage reason as the row above; a second
    // subclass means a second unexecuted title()/body() pair without it.
    #[Test]
    public function when_a_lapsed_verification_notice_is_rendered_then_it_names_the_transfer_it_verified(): void
    {
        // Given a verified transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $verifier = $this->aPlatformSupportAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $verifier);

        // When the lapsed-verification notice is rendered for its verifier
        $mail = (new OwnershipTransferVerificationLapsedNotification($transfer))->toMail($verifier);

        // Then it carries the subject and says the verification was theirs
        $this->assertNoticeRenders($mail, 'Verified ownership transfer expired', 'you verified');
    }

    /**
     * $mail carries $expectedSubject and $expectedPhrase, and its Blade view
     * actually renders.
     *
     * `render()` is the load-bearing call: it drives the envelope, the content
     * and the view together, so a template that referenced a missing variable
     * would fail here and nowhere else in the suite.
     */
    protected function assertNoticeRenders(Mailable $mail, string $expectedSubject, string $expectedPhrase): void
    {
        $rendered = $mail->render();

        $this->assertSame($expectedSubject, $mail->envelope()->subject);
        $this->assertStringContainsString($expectedPhrase, $rendered);
    }

    // Happy (UC-1-116 sc.1, T8) — a requested transfer that outlived its 30-day
    // window is ended by the scan: the aggregate records the expiry at the
    // requested stage, the projection goes terminal and the SLA timer clears.
    #[Test]
    public function when_a_requested_transfer_outlives_its_thirty_day_window_then_it_expires_at_the_requested_stage(): void
    {
        // Given a requested transfer whose 30-day window has elapsed
        $transfer = $this->anOverdueRequestedTransfer();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();

        // Then it is expired at the requested stage and its requester is told
        $this->assertTransferExpiredAtStage($transfer, 'requested-stage', 30);
        $this->assertRequesterWasNotifiedOfExpiry($transfer);
        $this->assertExpiryAudited($transfer);
    }

    // Happy (UC-1-117 sc.1, T9) — a VERIFIED transfer that outlived its shorter
    // 14-day window expires at the verified stage. The window is shorter because
    // execution is a single admin click, so a stale verification is the risk.
    #[Test]
    public function when_a_verified_transfer_outlives_its_fourteen_day_window_then_it_expires_at_the_verified_stage(): void
    {
        // Given a verified transfer whose 14-day window has elapsed
        $transfer = $this->anOverdueVerifiedTransfer();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();

        // Then it is expired at the verified stage, on the 14-day window
        $this->assertTransferExpiredAtStage($transfer, 'verified-stage', 14);
        $this->assertRequesterWasNotifiedOfExpiry($transfer);
        $this->assertVerifierWasNotifiedOfLapse($transfer);
    }

    // Happy (UC-1-116, the T8 half of the dual-notification asymmetry) — nobody
    // verified a requested-stage transfer, so there is no verifier to tell. The
    // paired negative of the T9 row above: without it the source filter is
    // asserted from one side only, which is precisely how the equivalent filter
    // went unenforced at s155 (91 tests stayed green while it was silenced).
    #[Test]
    public function when_a_transfer_expires_before_it_was_verified_then_no_verifier_is_notified(): void
    {
        // Given a requested transfer whose 30-day window has elapsed, never verified
        $this->anOverdueRequestedTransfer();

        // When the daily scan runs
        $this->runExpiryScan()->assertSuccessful();

        // Then no verification-lapsed notice goes out
        $this->assertNoVerifierWasNotified();
    }

    /**
     * No verification-lapsed notice was sent to anyone.
     *
     * The revert-check for this row is worth stating precisely, because what it
     * proves is stronger than the assertion reads. Removing `VerifierNotificationReactor`'s
     * source filter does not merely send a spurious notice — a requested-stage
     * transfer has a NULL `verified_by`, so the reactor's `findOrFail` throws and
     * the whole scan fails. The filter is load-bearing for the scan's survival,
     * not only for its etiquette, and `assertSuccessful()` in the caller is what
     * catches that half.
     */
    protected function assertNoVerifierWasNotified(): void
    {
        Notification::assertSentTimes(OwnershipTransferVerificationLapsedNotification::class, 0);
    }

    /**
     * A requested transfer whose 30-day window has just elapsed — arranged
     * through a REAL T1 stream, then the clock moved past the deadline the T1
     * projector wrote.
     */
    protected function anOverdueRequestedTransfer(): OwnershipTransferRequest
    {
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        $this->travel(31)->days();

        return $transfer;
    }

    /**
     * A verified transfer whose 14-day post-verification window has just
     * elapsed — a REAL T1 → T2 stream, so the deadline under test is the one the
     * T2 projector RESET (verified_at + 14d), not the T1 anchor it replaced.
     */
    protected function anOverdueVerifiedTransfer(): OwnershipTransferRequest
    {
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->aPlatformSupportAdmin());

        $this->travel(15)->days();

        return $transfer;
    }

    /**
     * $transfer reached the Expired terminal at $expectedSource: the projection
     * carries the stage and the elapsed-window figure, the SLA timer is cleared,
     * and a stored OwnershipTransferExpired event backs it.
     *
     * `$expectedSource` is a plain STRING, and callers pass literals. Taking a
     * `TransitionSource` and asserting `$stage->value` would read the expected
     * value from the same enum production renders from, so both sides would move
     * together and the assertion could not fail — the s155 L1 defect exactly
     * (respelling `PostVerification` as `post_verification` left 91 tests green).
     * The withdraw and reject classes pin their own `*_source` values as literals
     * for this reason; ERD-1-05 §3 is the canon these literals answer to.
     *
     * The stored-event assertion is amendment R: `expired_at`/`expiry_source`
     * are projected columns, so a direct read-model write would satisfy every
     * other assertion here while leaving the aggregate silent — and be reverted
     * on the next projection replay.
     */
    protected function assertTransferExpiredAtStage(
        OwnershipTransferRequest $transfer,
        string $expectedSource,
        int $afterDays,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame([
            'status' => OwnershipTransferStatus::Expired,
            'expiry_source' => $expectedSource,
            'expired_after_days' => $afterDays,
            'next_state_transition_at' => null,
        ], [
            'status' => $current->status,
            'expiry_source' => $current->expiry_source,
            'expired_after_days' => $current->expired_after_days,
            'next_state_transition_at' => $current->next_state_transition_at,
        ]);
        $this->assertNotNull($current->expired_at);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExpired::class)
            ->where('event_properties->requestId', $transfer->getKey())
            ->count());
    }

    /**
     * The transfer's original requester was told it lapsed — exactly once.
     *
     * The times-assertion is the load-bearing half: `assertSentTo` alone stays
     * green if the reactor fires for every recipient it can reach, which is the
     * shape that let T1 and T2 emit byte-identical notices (OQ-1-144). It is
     * scoped to this notification class rather than a global `assertCount`,
     * because the T1 genesis in the arrangement legitimately notifies the
     * support queue first.
     */
    protected function assertRequesterWasNotifiedOfExpiry(OwnershipTransferRequest $transfer): void
    {
        $requester = User::query()->findOrFail($transfer->current_owner_user_id);

        Notification::assertSentTo($requester, OwnershipTransferExpiredNotification::class);
        Notification::assertSentTimes(OwnershipTransferExpiredNotification::class, 1);
    }

    /**
     * The admin who performed the prior T2 verification was told their work
     * lapsed unexecuted — UC-1-117's dual-notification asymmetry, and the whole
     * reason T9 differs from T8.
     *
     * The verifier is resolved from the projection's `verified_by` (ruling M3),
     * written once at T2 by the table's sole writer straight from that event's
     * own payload field. UC-1-117 asks for a `stored_events` read instead; that
     * would be the first production read of the event store anywhere in the app
     * and would couple this reactor to an event class name and payload shape
     * (OQ-1-108) — the coupling J6 kept out of payloads.
     */
    protected function assertVerifierWasNotifiedOfLapse(OwnershipTransferRequest $transfer): void
    {
        $verifier = User::query()->findOrFail($this->currentTransfer($transfer)->verified_by);

        Notification::assertSentTo($verifier, OwnershipTransferVerificationLapsedNotification::class);
        Notification::assertSentTimes(OwnershipTransferVerificationLapsedNotification::class, 1);
    }

    /**
     * The expiry left a chain-of-custody row naming no human actor.
     *
     * Every column here is pinned, and that is deliberate: at s155 the equivalent
     * audit assertion could not fail — `stored_event_id` stripped, the role code
     * changed to `admin` and the description rewritten all left 92 tests green.
     * The actor triple is asserted NULL rather than skipped, because "no human
     * did this" is the row's whole claim (ruling M7) and an unasserted null is
     * indistinguishable from lost attribution.
     *
     * `stored_event_id` is a nullable soft FK, so a dropped value inserts happily
     * — asserting it non-null is what keeps the audit row pointing at the event
     * that justifies it.
     */
    protected function assertExpiryAudited(OwnershipTransferRequest $transfer): void
    {
        $entry = AuditEntry::query()
            ->where('subject_id', $transfer->getKey())
            ->where('event_class', 'OwnershipTransferExpired')
            ->sole();

        $this->assertSame([
            'action' => 'transfer_expired',
            'company_id' => $transfer->company_id,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
        ], [
            'action' => $entry->action,
            'company_id' => $entry->company_id,
            'actor_user_id' => $entry->actor_user_id,
            'actor_role_code' => $entry->actor_role_code,
            'actor_label' => $entry->actor_label,
        ]);
        $this->assertNotNull($entry->stored_event_id);
        $this->assertSame(
            'The ownership transfer expired before it was completed; the current owner retains ownership.',
            $entry->description,
        );
    }

    protected function runExpiryScan(): PendingCommand
    {
        $command = $this->artisan('ownership-transfers:expire-stale');
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * $transfer was NOT expired: status and SLA deadline unchanged, and no
     * OwnershipTransferExpired event carries its id.
     *
     * The stored-event count is the load-bearing half. An unchanged projection
     * proves nothing about whether the aggregate recorded, and under ruling M1 a
     * mis-arranged fixture fails SILENTLY rather than throwing — so a row that
     * only compared columns would stay green against an empty stream.
     */
    protected function assertTransferStillOpen(OwnershipTransferRequest $transfer): void
    {
        $current = $this->currentTransfer($transfer);

        $this->assertSame($transfer->status, $current->status);
        $this->assertEquals($transfer->next_state_transition_at, $current->next_state_transition_at);
        $this->assertSame(0, $this->expiryEventCount($transfer));
    }
}
