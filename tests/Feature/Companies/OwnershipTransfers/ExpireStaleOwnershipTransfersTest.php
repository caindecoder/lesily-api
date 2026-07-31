<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Projectors\OwnershipTransferProjector;
use App\Companies\OwnershipTransfers\IO\Mails\OwnershipTransferNotificationMail;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExpireOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferExpiredNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferVerificationLapsedNotification;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\IO\Database\Repositories\EloquentOwnershipTransferRequestRepository;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * SM-1-05 T8 + T9 — the daily `ownership-transfers:expire-stale` scan ends
 * transfers that outlived their SLA window (UC-1-116 requested-stage 30d,
 * UC-1-117 verified-stage 14d). The slice's first SYSTEM flow: no HTTP surface,
 * no role gate, no tenant context — the actor is the scheduler.
 *
 * Every row that involves a TRANSFER arranges a REAL stream (the two command-
 * validation rows assemble no transfer at all). The scan reads two different
 * sources — selection queries the projection, the transition replays the stream —
 * so a factory row satisfies the first and is EMPTY for the second, leaving the
 * aggregate with a null status that no longer matches anything the scan could
 * have selected. It records nothing, the scan reports "0 expired", and a
 * careless test passes for entirely the wrong reason. That is s149 C1 with a
 * silent failure mode instead of a throwing one. (Pre-O1 the refusal came from
 * M1's no-op arm; since O1 the `expectedStatus` guard refuses first. Same silent
 * outcome, different mechanism.)
 *
 * That the two reads can disagree is not only a fixture hazard, it is a
 * production one — ruling O1, and the reason the Command carries the state the
 * scan saw.
 */
class ExpireStaleOwnershipTransfersTest extends OwnershipTransfersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
    }

    // Sad — the per-row command refuses a window that is not a positive number of
    // days, and a stage the state machine does not have.
    //
    // The console cannot reach either: it builds every command from a row it has
    // just selected. They guard future callers, which is what the Command's
    // docblock claims by citing `ApplyTrialExpirationCommand` — and that
    // precedent is itself pinned, by `ExpireTrialsCommandTest`'s own rejection
    // provider. The rationale had been copied across without the row.
    //
    // The two rows do NOT carry equal weight, and saying so matters. `min:1` is
    // the only rule here with sole responsibility: nothing infers it, so deleting
    // it silently accepts a zero-day window. The stage rule is belt-and-braces —
    // spatie re-infers `required` + `Enum` from the property's own enum type
    // (`BuiltInTypesRuleInferrer`), so deleting `rules()` wholesale would leave
    // 'archived' refused anyway. It is kept because the inference depends on the
    // property STAYING enum-typed: retype it to string and the explicit rule
    // becomes the only guard left.
    #[Test]
    #[DataProvider('provideRejectedExpiryCommands')]
    public function when_an_expiry_command_is_malformed_then_it_is_refused(array $payload): void
    {
        // Given a malformed per-row expiry command
        $this->expectException(ValidationException::class);

        // When it is assembled
        // Then it never reaches an aggregate
        ExpireOwnershipTransferCommand::validateAndCreate($payload);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provideRejectedExpiryCommands(): array
    {
        return [
            'a window of no days at all' => [['expectedStatus' => 'requested', 'expiredAfterDays' => 0]],
            'a stage the state machine does not have' => [['expectedStatus' => 'archived', 'expiredAfterDays' => 30]],
        ];
    }

    // Sad (F-c, ruling R8) — the replay option is an operator surface, and a
    // cutoff Carbon cannot parse is refused at the boundary with a clear message
    // instead of an uncaught InvalidFormatException. The overdue row in the
    // arrangement is load-bearing: it proves the refusal short-circuits before
    // the interactor ever selects, not merely that the error prints.
    //
    // Rollover strings ('2026-02-30') are deliberately NOT rows here: DateTime
    // parses them by rolling the calendar over, so the guard refuses exactly
    // what Carbon refuses — documenting that boundary rather than inventing a
    // stricter one nothing else enforces.
    #[Test]
    #[DataProvider('provideMalformedCutoffs')]
    public function when_the_scan_is_replayed_at_a_malformed_cutoff_then_it_refuses_to_run(string $cutoff): void
    {
        // Given a requested transfer whose window has already elapsed
        $transfer = $this->anOverdueRequestedTransfer();

        // When the scan is replayed at a cutoff that is not a date
        $command = $this->runExpiryScan($cutoff);

        // Then it refuses to run and the overdue transfer is untouched
        $command->expectsOutputToContain('is not a valid date')->assertFailed();
        $this->assertTransferUnchanged($transfer);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideMalformedCutoffs(): array
    {
        return [
            'garbage text' => ['not-a-date'],
            'an impossible calendar date' => ['2026-13-45'],
        ];
    }

    // Sad (F-c, ruling R8) — a FUTURE cutoff is refused outright. The option
    // exists to replay a night the scheduler missed, so a legitimate cutoff is
    // always in the past; a future one would expire rows that are not yet due,
    // writing facts the timestamps contradict into an append-only stream. The
    // in-window fixture is the sharp half of the claim: before this guard, the
    // run below genuinely expired it.
    #[Test]
    public function when_the_scan_is_replayed_at_a_future_cutoff_then_it_refuses_to_run(): void
    {
        // Given a requested transfer still inside its 30-day window
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        // When the scan is replayed at a cutoff that has not happened yet
        $command = $this->runExpiryScan('2026-08-10 00:00:00');

        // Then it refuses to run and the in-window transfer is untouched
        $command->expectsOutputToContain('in the future')->assertFailed();
        $this->assertTransferUnchanged($transfer);
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
        $this->assertTransferUnchanged($transfer);
    }

    // Sad — a transfer that already ended is never re-ended. Arranged through a
    // REAL T1 → T6 stream, so the exclusion under test is the one production
    // produces rather than a factory row's.
    //
    // BORN GREEN, and stated as such rather than left to read as proof. TWO
    // mechanisms now exclude this row and the test cannot tell them apart: the
    // projector cleared the timer when the withdrawal landed (`NULL < cutoff` is
    // false in SQL), and since ruling O2 the query also names the states it
    // selects. Removing either alone REDs nothing.
    //
    // That is why the predicate went in on correctness grounds rather than
    // test-provable ones. Relying on the timer clear alone made the query depend
    // on an invariant a different class maintains, and owed Task 8 a residual —
    // an `execute()` arm landing terminal without clearing the timer would have
    // been re-selected nightly and silently skipped forever. Naming the states
    // costs one line and owes nothing. This row characterises the exclusion; it
    // does not prove which half did the excluding.
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
        $this->assertTransferUnchanged($transfer);
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
        $this->assertTransferUnchanged($held);
        $this->assertTransferExpiredAtStage($free, 'requested-stage', 30);
    }

    // Sad (F-a, OQ-1-156 — the O4 gap NARROWED to the re-read→persist window;
    // the unlocked re-read is cross-referenced to Task R4's sweep) — a
    // regulatory hold that lands
    // AFTER the scan selected the row must stop the write. Selection excludes
    // held companies, but `legal_hold` moves independently of transfer status,
    // so the O1 guard cannot see it: an unchanged `expectedStatus` says nothing
    // about the hold. The interactor re-reads the hold immediately before the
    // aggregate call and SKIPS, uncounted — matching the SELECT-time skip-only
    // semantics (D3). The unheld sibling is load-bearing: with one row in the
    // selection, a scan that skipped EVERYTHING would look identical (s149 C6).
    #[Test]
    public function when_the_hold_lands_after_the_scan_selected_the_row_then_no_expiry_is_recorded(): void
    {
        // Given two overdue selected transfers, one at a company whose hold
        // landed after selection
        [$heldCompany, $heldOwner] = $this->aCompanyWithOwner();
        $held = $this->aRequestedTransferStream($heldCompany, $heldOwner, $this->aMemberWithRole($heldCompany, 'employee'));
        [$freeCompany, $freeOwner] = $this->aCompanyWithOwner();
        $free = $this->aRequestedTransferStream($freeCompany, $freeOwner, $this->aMemberWithRole($freeCompany, 'employee'));
        $this->travel(31)->days();
        $this->selectionReportsMap([
            (string) $held->getKey() => OwnershipTransferStatus::Requested,
            (string) $free->getKey() => OwnershipTransferStatus::Requested,
        ]);
        $this->placeCompanyUnderLegalHold($heldCompany);

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();

        // Then only the unheld company's transfer expired
        $this->assertTransferUnchanged($held);
        $this->assertTransferExpiredAtStage($free, 'requested-stage', 30);
    }

    // Sad (F-a's vanished-row contract, ruling D4) — a row deleted between the
    // scan's SELECT and its write is skipped, uncounted. The projection row is
    // gone but the STREAM survives, so without the write-time re-read the
    // aggregate — whose replayed state still agrees with `expectedStatus` —
    // would record an expiry event against a row that no longer exists. BORN
    // GREEN, stated as such: the F-a lap built this branch; teeth proven by
    // revert-check at build (a re-read forced to answer clear records the
    // stray event and REDs the no-event assertion).
    #[Test]
    public function when_a_selected_row_vanishes_before_its_write_then_the_scan_skips_it(): void
    {
        // Given an overdue selected transfer whose projection row vanished
        // after selection
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));
        $this->travel(31)->days();
        $this->selectionReportsMap([
            (string) $transfer->getKey() => OwnershipTransferStatus::Requested,
        ]);
        OwnershipTransferRequest::query()->whereKey($transfer->getKey())->delete();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('0 transfer(s) expired.')->assertSuccessful();

        // Then no expiry was recorded against the vanished row
        $this->assertSame(0, $this->expiryEventCount($transfer));
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

        // When the daily scan runs with its cutoff at a given instant relative to
        // that deadline — the clock travels WITH the cutoff, because a replay
        // cutoff must not be in the future (ruling R8) while `--date` stays the
        // scan's own input rather than a clock trick
        $this->travelTo(CarbonImmutable::parse($runAt));

        // Then it expires only if the deadline elapsed before the cutoff
        $this->runExpiryScan($runAt)->expectsOutputToContain($expectedOutput)->assertSuccessful();
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

    // Edge (OQ-1-006's "per-stage configurable" half) — retuning a window changes
    // what the scan enforces AND what it records, for both stages.
    //
    // Nothing pinned this. Every other row asserts 30 or 14, which are exactly
    // what `windowFor()`'s inline `config(..., 30)` / `config(..., 14)` fallbacks
    // return — so misspelling either key, or replacing the method body with
    // `return $status === Requested ? 30 : 14;`, left the whole class green while
    // silently un-tuning production. Retuning `request_ttl_days` would have
    // appeared to work while `expired_after_days` kept recording 30.
    //
    // Overriding the window also moves the anchor the T1/T2 projector writes, so
    // one row pins both reads of each key: the deadline that makes the row due,
    // and the figure the expiry records. `VerifyOwnershipTransferIdentityTest`
    // already owns this idiom for the T2 projector's read.
    #[Test]
    #[DataProvider('provideRetunedWindows')]
    public function when_an_sla_window_is_retuned_then_the_scan_enforces_and_records_the_configured_one(
        string $stage,
        int $window,
        string $expectedSource,
    ): void {
        // Given both windows retuned well away from their defaults
        config([
            'ownership-transfers.request_ttl_days' => 3,
            'ownership-transfers.verified_ttl_days' => 2,
        ]);
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferAwaitingStage($stage, $company, $owner);

        // When the scan runs a day past the retuned deadline
        $this->travel($window + 1)->days();

        // Then it expired on the configured window, and recorded that window
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();
        $this->assertTransferExpiredAtStage($transfer, $expectedSource, $window);
    }

    /**
     * Both stages, because each reads its own config key through its own arm of
     * `windowFor()` — pinning one leaves the other free to fall back.
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function provideRetunedWindows(): array
    {
        return [
            'a retuned requested-stage window' => ['requested', 3, 'requested-stage'],
            'a retuned verified-stage window' => ['verified', 2, 'verified-stage'],
        ];
    }

    protected function aTransferAwaitingStage(string $stage, Company $company, User $owner): OwnershipTransferRequest
    {
        return $stage === 'requested'
            ? $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'))
            : $this->aVerifiedTransferStream($company, $owner, $this->aPlatformSupportAdmin());
    }

    // Edge (`--date` replay) — a missed night replayed at its own cutoff stamps
    // the expiry AT that cutoff, not at the moment the replay happened to run.
    //
    // The option exists so an operator can catch up after the scheduler failed,
    // and the whole point is that the record reads as though the scan had run on
    // time. Stamping `now()` instead produces a row whose `expired_at` precedes
    // the deadline it supposedly outlived — the transfer would read as having
    // expired before its window closed, and `expired_after_days` would describe a
    // window the timestamps contradict, permanently, in an append-only stream.
    //
    // `invitations:expire` already threads its cutoff into the event
    // (`UserMembership::expireIfPending(CarbonInterface $expiredAt)`); `trials:expire`
    // stamps `now()`. The two built scans disagree and this one follows the
    // invitations shape, because only that shape survives a replay (Rule #15).
    #[Test]
    public function when_a_missed_night_is_replayed_then_the_expiry_is_stamped_at_the_replayed_cutoff(): void
    {
        // Given a transfer whose 30-day window elapsed while the scheduler was down
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        // When the missed run is replayed two days late, at the cutoff it should
        // have used — the clock sits PAST the cutoff, which is what makes this a
        // replay rather than a prediction (ruling R8 refuses the future kind)
        $this->travelTo(CarbonImmutable::parse('2026-08-03 08:00:00'));
        $this->runExpiryScan('2026-08-01 09:00:01')->assertSuccessful();

        // Then the expiry is stamped at that cutoff, after the deadline it breached
        $this->assertSame(
            '2026-08-01 09:00:01',
            $this->currentTransfer($transfer)->expired_at?->toDateTimeString(),
        );
    }

    // Edge (idempotency) — a second scan does not expire an already-expired
    // transfer. Two mechanisms exclude it now: the terminal timer clear, and O2's
    // `openStates()` predicate.
    //
    // What this row proves is that no SECOND expiry event exists — the count
    // inside `assertTransferExpiredAtStage`. It deliberately no longer claims to
    // detect re-selection: since the count became event-based, a re-selected row
    // that no-ops adds nothing to it and would print "0" either way. Detecting
    // re-selection would take a counting repository seam, which is available via
    // `selectionReports()` if it is ever worth pinning.
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
        $this->assertTransferUnchanged($inWindow);
    }

    // Edge (ruling M1's no-op arm) — an expiry whose selection AGREED that the
    // transfer was already terminal records nothing at all.
    //
    // Note what this is NOT: the human race (an admin verifying, the owner
    // withdrawing between the SELECT and the write) produces a DISAGREEING
    // selection, which the O1 guard refuses — that is the row below. This row
    // arranges selection and stream agreeing on a terminal state, which the
    // console cannot produce at all since O2 made the query select `openStates()`.
    //
    // So it is driven through the AGGREGATE directly, the same shape
    // ExpireTrialsCommandTest uses for its own console-unreachable no-op arms.
    // The arm is kept because E28 wants every state × event combination written.
    #[Test]
    #[DataProvider('provideNonOpenStates')]
    public function when_the_transfer_is_no_longer_open_at_fire_time_then_no_expiry_is_recorded(string $arrangement): void
    {
        // Given a transfer that already reached a terminal state
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTerminalTransferStream($arrangement, $company, $owner);
        $expiriesBefore = $this->expiryEventCount($transfer);

        // When an expiry fires for it anyway, on a selection that AGREES with its
        // state — so the O1 disagreement guard passes and the no-op arm is what
        // has to refuse it
        OwnershipTransferRequestAggregate::retrieve($transfer->getKey())
            ->expireIfStale(ExpireOwnershipTransferCommand::validateAndCreate([
                'expectedStatus' => $transfer->status,
                'expiredAfterDays' => 30,
                'expiredAt' => CarbonImmutable::now(),
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
     *
     * The deadline comparison was vacuous while every caller arranged a terminal
     * row: the projector nulls the timer on all of them, so it read
     * `assertEquals(null, null)`. The O1 row is what gave it a data set with a
     * live deadline — a Verified transfer holding `verified_at + 14d`, which an
     * expiry firing anyway would have cleared.
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

    // Edge (ruling O1) — the scan selected this row while it was `Requested`, and
    // an admin verified it before the loop reached its write. The selection is
    // therefore stale: the row now carries a FRESH `verified_at + 14d` deadline
    // and is not due at all. UC-1-116 error flow 4b and its Gherkin sc.2 both
    // require the transition to be SKIPPED — "no event emitted", "R1 remains in
    // Verified state" — and SM-1-05 §4 states the T8 guard as "state STILL
    // `Requested`", not "state open".
    //
    // Selection status and replayed status are read from different tables at
    // different instants, so the aggregate cannot detect this on its own; the
    // Command carries what the scan saw and the aggregate refuses on any
    // disagreement. Without that guard the `Verified` arm fires and records an
    // expiry stamped `verified-stage` carrying the 30-day figure the interactor
    // derived from the pre-race status — a pairing ERD-1-05 §3 documents and
    // NOTHING enforces (§4 records that these string-enums carry no CHECK
    // constraint and are validated at the app layer), written permanently into an
    // append-only stream. The aggregate is the only thing standing between the
    // design's intent and a contradiction no migration can later undo.
    #[Test]
    public function when_the_transfer_was_verified_after_the_scan_selected_it_then_no_expiry_is_recorded(): void
    {
        // Given a transfer verified after the scan picked it up as requested
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->aPlatformSupportAdmin());
        $expiriesBefore = $this->expiryEventCount($transfer);

        // When the expiry fires under the window the scan selected it for
        OwnershipTransferRequestAggregate::retrieve($transfer->getKey())
            ->expireIfStale(ExpireOwnershipTransferCommand::validateAndCreate([
                'expectedStatus' => OwnershipTransferStatus::Requested,
                'expiredAfterDays' => 30,
                'expiredAt' => CarbonImmutable::now(),
            ]))
            ->persist();

        // Then nothing was recorded and it keeps its verified deadline
        $this->assertNothingFurtherRecorded($transfer, $expiriesBefore);
    }

    // Edge (ruling O1, through the whole scan) — the aggregate-level row above
    // proves the guard refuses; this proves the SCAN survives the refusal and
    // reports it honestly.
    //
    // It is the only row that can reach a refusal through the console, because
    // the disagreement it needs cannot be arranged by a sequential suite — the
    // two reads happen microseconds apart inside one call. Substituting the
    // repository is the module's existing seam idiom: `AuthTestCase`'s
    // `TotpVerifier` and `BillingTestCase`'s PDF renderer bind an anonymous class
    // at an INTERFACE, `RegisterOwnerTest`'s uniqueness guard extends a CONCRETE
    // class — which is the shape used here. The repository is a genuine
    // architectural boundary, the only place E23 sanctions a double.
    #[Test]
    public function when_a_selection_goes_stale_before_its_row_is_written_then_the_scan_reports_no_expiry(): void
    {
        // Given a verified transfer that the scan still believes is requested
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->aPlatformSupportAdmin());
        $this->selectionReports($transfer, OwnershipTransferStatus::Requested);

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('0 transfer(s) expired.')->assertSuccessful();

        // Then nothing expired, and the reported count says so
        $this->assertTransferUnchanged($transfer);
    }

    /**
     * Makes the scan's SELECT report $status for $transfer, whatever its stream
     * actually holds — the race, made deterministic.
     */
    protected function selectionReports(OwnershipTransferRequest $transfer, OwnershipTransferStatus $status): void
    {
        $this->selectionReportsMap([(string) $transfer->getKey() => $status]);
    }

    /**
     * Makes the scan's SELECT report exactly $rows (`requestId => status`) —
     * the race for several rows at once: the F-a row needs a held row AND an
     * unheld sibling, because a scope assertion needs a row on the other side
     * of the scope (s149 C6).
     *
     * Extends the concrete repository rather than implementing the interface, so
     * only the one method under test diverges and the others keep their real
     * behaviour (the `RegisterOwnerTest` shape) — load-bearing for F-a, whose
     * legal-hold re-read must run through the INHERITED real method against the
     * live database.
     *
     * @param  array<string, OwnershipTransferStatus>  $rows
     */
    protected function selectionReportsMap(array $rows): void
    {
        $this->app->instance(
            OwnershipTransferRequestRepositoryInterface::class,
            new class($rows) extends EloquentOwnershipTransferRequestRepository
            {
                /**
                 * @param  array<string, OwnershipTransferStatus>  $rows
                 */
                public function __construct(private array $rows) {}

                /**
                 * @return array<string, OwnershipTransferStatus>
                 */
                public function listTimerReady(CarbonInterface $asOf): array
                {
                    return $this->rows;
                }
            },
        );
    }

    // Edge (F-b — UC-1-116/117 Main Flow step 9, built at Task R1) — one row's
    // failure must not abort the batch: the rows selected after it still
    // expire, the count keeps meaning what it says, and the failure is REPORTED
    // instead of silently swallowing the rest of the night. The selection order
    // is pinned through the seam so the failing row genuinely sits MID-batch —
    // an unordered real SELECT could have iterated it last, leaving the
    // rows-after-the-failure claim unexercised.
    //
    // The failure is induced with the Task-8 P8 seam — a container-bound
    // throwing subclass of the projector, selective to the middle row's id,
    // bound AFTER arrangement so the T1 projections are real. LD's analog test
    // (PermanentlyDeleteOrphanedUsersOlderThanSevenDaysTest, skipped-and-
    // reported-while-others-deleted) induces its failure organically via an FK
    // obstacle; this scan has no organic failure mode, hence the seam.
    //
    // What the middle row is left as is stated honestly: its stream DID record
    // the expiry (the event was durable before the projector threw), its
    // projection did not move — the pre-existing single-row divergence class.
    // The trade against the old behaviour is stated honestly too: before F-b
    // the abort was LOUD (an uncaught throw, non-zero exit) but killed every
    // later row; F-b keeps the batch running while keeping the failure loud
    // through the report, the failed count and the FAILURE exit (D1). The
    // aggregate thereafter replays as Expired, so a re-selection no-ops rather
    // than double-firing.
    #[Test]
    public function when_a_row_fails_mid_scan_then_the_remaining_rows_still_expire(): void
    {
        // Given three overdue selected transfers, the middle one rigged to fail
        // at its projector — and the config posture the isolation depends on
        // pinned: with catch_exceptions true, spatie would swallow the throw
        // before the interactor's catch and count the divergent row as expired
        $this->assertFalse((bool) config('event-sourcing.catch_exceptions'));
        Exceptions::fake();
        $first = $this->anOverdueRequestedTransfer();
        $middle = $this->anOverdueRequestedTransfer();
        $last = $this->anOverdueRequestedTransfer();
        $this->selectionReportsMap([
            (string) $first->getKey() => OwnershipTransferStatus::Requested,
            (string) $middle->getKey() => OwnershipTransferStatus::Requested,
            (string) $last->getKey() => OwnershipTransferStatus::Requested,
        ]);
        $this->projectorFailsFor($middle);

        // When the daily scan runs
        $this->runExpiryScan()
            ->expectsOutputToContain('2 transfer(s) expired, 1 failed.')
            ->assertFailed();

        // Then the other rows expired, only their requesters were told, and the
        // failure was reported
        $this->assertTransferExpiredAtStage($first, 'requested-stage', 30);
        $this->assertTransferExpiredAtStage($last, 'requested-stage', 30);
        Notification::assertSentTimes(OwnershipTransferExpiredNotification::class, 2);
        $this->assertFailedRowDivergedAndWasReported($middle);
    }

    /**
     * Binds a throwing double of the projector, selective to $transfer — the
     * Task-8 P8 seam: `Projectionist::callEventHandler` re-resolves every
     * handler at dispatch (`app($eventHandler::class)`), so a container binding
     * substitutes it. The subclass inherits `$weight` and full parent behaviour
     * for every OTHER row, which is what lets the neighbouring rows project
     * normally around the failure.
     */
    protected function projectorFailsFor(OwnershipTransferRequest $transfer): void
    {
        $failingId = (string) $transfer->getKey();

        $this->app->bind(
            OwnershipTransferProjector::class,
            fn (): OwnershipTransferProjector => new class($failingId) extends OwnershipTransferProjector
            {
                public function __construct(private readonly string $failingRequestId) {}

                public function onOwnershipTransferExpired(OwnershipTransferExpired $event): void
                {
                    if ($event->requestId === $this->failingRequestId) {
                        throw new RuntimeException('induced mid-scan projector failure');
                    }

                    parent::onOwnershipTransferExpired($event);
                }
            },
        );
    }

    /**
     * $transfer is the row the scan could not finish, left in the divergence's
     * honest shape — which has an unrecoverable half. STREAM: exactly one
     * expiry event, durable before the projector threw; a projection replay
     * heals this half. PROJECTION: unmoved (still `requested`, deadline
     * intact), so the next scan re-selects it and the aggregate no-ops —
     * nightly, forever, with no repeat report after this first one. REACTORS:
     * never ran and are never replayed, so the audit row and the requester's
     * notice for this expiry are permanently absent unless repaired by hand —
     * asserted, not just described. REPORT: carries the row id (review I2, the
     * LD Prune/Orphan idiom) because a Sentry trace without the requestId
     * cannot be traced back to the divergent row by any query.
     */
    protected function assertFailedRowDivergedAndWasReported(OwnershipTransferRequest $transfer): void
    {
        $current = $this->currentTransfer($transfer);

        $this->assertSame(OwnershipTransferStatus::Requested, $current->status);
        $this->assertSame(1, $this->expiryEventCount($transfer));
        $this->assertSame(0, AuditEntry::query()
            ->where('subject_id', $transfer->getKey())
            ->where('event_class', 'OwnershipTransferExpired')
            ->count());
        Exceptions::assertReported(
            fn (RuntimeException $e): bool => str_contains($e->getMessage(), (string) $transfer->getKey())
                && str_contains($e->getMessage(), 'induced mid-scan projector failure'),
        );
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
        $this->assertNoticeRenders($mail, 'Ownership transfer expired', [
            'raise a fresh request',
            $company->name,
        ]);
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

        // Then it carries the subject, the company and whose work lapsed
        $this->assertNoticeRenders($mail, 'Verified ownership transfer expired', [
            'you verified',
            $company->name,
        ]);
    }

    /**
     * $mail carries $expectedSubject and every phrase in $expectedPhrases, and
     * its Blade view actually renders.
     *
     * `render()` is the load-bearing call: it drives the envelope, the content
     * and the view together, so a template that referenced a missing variable
     * would fail here and nowhere else in the suite.
     *
     * The TENANT NAME is one of the phrases every caller passes, and it is the
     * reason this takes a list rather than one string. A static-substring check
     * alone passes on an empty or wrong company name — the regression the s152
     * review caught in the T1/T2 retrofit, which both `RequestOwnershipTransferTest`
     * and `WithdrawOwnershipTransferTest` pin explicitly and which these two rows
     * had lost. Both new notification bodies interpolate `companyName()` and
     * argue in their docblocks that a recipient owning several companies cannot
     * otherwise tell which transfer lapsed; nothing was holding them to it.
     *
     * @param  array<int, string>  $expectedPhrases
     */
    protected function assertNoticeRenders(OwnershipTransferNotificationMail $mail, string $expectedSubject, array $expectedPhrases): void
    {
        $rendered = $mail->render();

        $this->assertSame($expectedSubject, $mail->envelope()->subject);

        foreach ($expectedPhrases as $phrase) {
            $this->assertStringContainsString($phrase, $rendered);
        }
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

    /**
     * BORN-GREEN PIN, declared as such (the OQ-1-155/R7 precedent): the
     * Expired arm rides RequesterNotificationReactor's lap-5 class flip — its
     * Rejected arm carried the watched RED — so this row could never RED on
     * its own. Its teeth are the lap-6 revert-check recorded in the commit:
     * stripping ShouldQueue REDs this row and the reject sibling together.
     */
    #[Test]
    public function when_a_requested_transfer_expires_then_the_requester_notice_is_queued_not_sent_inline(): void
    {
        // Given a requested transfer whose 30-day window has elapsed, with the
        // queue captured
        $this->anOverdueRequestedTransfer();
        Queue::fake();

        // When the daily scan runs
        $this->runExpiryScan()->assertSuccessful();

        // Then the requester notice rides the queued handler job instead of
        // sending inline (same reactor as reject, second event arm)
        $this->assertReactorDeferred(OwnershipTransferExpired::class, OwnershipTransferExpiredNotification::class);
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
     * proves CHANGED at Task R1. Removing `VerifierNotificationReactor`'s
     * source filter does not merely send a spurious notice — a requested-stage
     * transfer has a NULL `verified_by`, so the reactor's `findOrFail` throws.
     * Before R1 that throw killed the whole scan; since R1's per-row catch it
     * is caught, reported and COUNTED, so the scan reports a failed row and
     * exits FAILURE (D1) — and `assertSuccessful()` in the caller REDs on that
     * exit rather than on an uncaught throw. The filter stays load-bearing for
     * the scan's health; the exit-code contract is what now holds it to that.
     */
    protected function assertNoVerifierWasNotified(): void
    {
        Notification::assertSentTimes(OwnershipTransferVerificationLapsedNotification::class, 0);
    }

    // Happy (downstream chain) — after the scan expires a transfer, its own owner
    // reading it back sees a request the SYSTEM ended, not a human. Proves the
    // expiry reaches the surface the requester actually looks at, through the
    // real UC-1-125 endpoint rather than a restatement of the projection.
    #[Test]
    public function when_an_owner_reads_a_transfer_the_scan_expired_then_it_reports_a_system_ended_request(): void
    {
        // Given a transfer the daily scan has expired
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));
        $this->travel(31)->days();
        $this->runExpiryScan()->assertSuccessful();

        // When its owner reads the transfer back
        $response = $this->getTransferStatus($owner, $company, (string) $transfer->getKey());

        // Then it reads as system-ended, with no SLA deadline left to show
        $response->assertOk();
        $response->assertJsonPath('data.status', 'expired');
        $response->assertJsonPath('data.terminating_actor', 'system_timer');
        $response->assertJsonPath('data.next_action', 'Transfer request expired');
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

    /**
     * Runs the scan, optionally at an explicit cutoff via `--date`.
     *
     * The option exists so an operator can replay a missed night, and the class
     * docblock on the console command cites the two built scans as its model —
     * but only `ExpireTrialsCommandTest` actually drives it, so here the option
     * could have been deleted from behaviour with every row still green. The
     * boundary provider now goes through it rather than through the clock, which
     * is also the more faithful shape: the cutoff is the scan's own input.
     */
    protected function runExpiryScan(?string $asOf = null): PendingCommand
    {
        $command = $this->artisan(
            'ownership-transfers:expire-stale',
            $asOf === null ? [] : ['--date' => $asOf],
        );
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
    protected function assertTransferUnchanged(OwnershipTransferRequest $transfer): void
    {
        $current = $this->currentTransfer($transfer);

        $this->assertSame($transfer->status, $current->status);
        $this->assertEquals($transfer->next_state_transition_at, $current->next_state_transition_at);
        $this->assertSame(0, $this->expiryEventCount($transfer));
    }
}
