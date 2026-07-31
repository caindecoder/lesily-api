<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OQ-1-155 (ruling R7, Task R1) — the registration guard for the daily expiry
 * scans. Nothing else anywhere detects a scheduled command that was never
 * registered: delete a `Schedule::command` line from `routes/console.php` and
 * the suite stays green, CI stays green, and the command simply never runs in
 * production — trials never end, invitations never lapse, transfers accumulate
 * past their SLA forever, silently.
 *
 * Guarded in BOTH directions over one declared list (rulings R7 + D3): each
 * declared scan must exist as a command AND be registered (a registered name
 * that resolves to no command shells out to nothing nightly — the same silent
 * failure class), and every scheduled command must be declared, so a future
 * schedule entry cannot slip in un-guarded. These are BORN-GREEN
 * characterization pins, stated as such: they pin framework wiring, a trade
 * accepted deliberately (M6's objection, conceded by R7) because "this command
 * runs daily in production" has no other observable, and no production exists
 * yet to observe it in. Teeth proven by revert-check at build: removing a
 * registration line REDs the declared-direction row naming that scan;
 * scheduling an undeclared command REDs the symmetric row naming the command.
 *
 * Deliberate divergence from LeaveDates (Rule #15): LD runs 31 scheduled tasks
 * with zero registration coverage and answers the problem at runtime instead
 * (schedule-monitor + health heartbeat) — but a heartbeat detects a dead
 * RUNNER, not a deleted LINE; LD's own `schedule-monitor:sync` deletes the
 * alarm along with a deleted task. The runner question is master-action #116;
 * this guard answers the registration question only — cadence is deliberately
 * unpinned.
 *
 * @testclass-carveout: infra-primitive — pins scheduler wiring
 * (routes/console.php registrations + command existence); no HTTP seam exists
 * for this behaviour.
 */
class ScheduleExpiryScansTest extends FoundationTestCase
{
    /**
     * Every daily scan the platform relies on. A new scan joins the schedule by
     * adding its line to `routes/console.php` AND its name here — and only
     * here: the undeclared-direction test below fails on any scheduled command
     * this list does not name.
     *
     * @var list<string>
     */
    private const array DECLARED_DAILY_SCANS = [
        'trials:expire',
        'invitations:expire',
        'ownership-transfers:expire-stale',
    ];

    #[Test]
    #[DataProvider('provideDeclaredDailyScans')]
    public function when_the_daily_schedule_is_inspected_then_the_declared_scan_is_registered(string $scan): void
    {
        // Given the application's registered schedule
        // When the declared scan is looked up
        // Then it exists as a real command AND a schedule line runs it —
        // failing by name on either half
        $this->assertArrayHasKey(
            $scan,
            Artisan::all(),
            "The daily scan '{$scan}' does not resolve to an artisan command — its schedule line would run nothing.",
        );
        $this->assertTrue(
            $this->scheduledCommandStrings()->contains(
                fn (string $command): bool => $this->runsScan($command, $scan),
            ),
            "The daily scan '{$scan}' is not registered in routes/console.php — "
                .'it would silently never run in production.',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideDeclaredDailyScans(): array
    {
        $rows = [];

        foreach (self::DECLARED_DAILY_SCANS as $scan) {
            $rows[$scan] = [$scan];
        }

        return $rows;
    }

    // The symmetric direction (ruling D3) — an undeclared scheduled command is
    // the same forgetting in reverse: it would run nightly with no registration
    // guard watching it. Forcing every schedule entry through the declared list
    // is what keeps the guard's coverage total rather than historical.
    #[Test]
    public function when_a_command_is_scheduled_then_it_is_a_declared_daily_scan(): void
    {
        // Given the application's registered schedule
        $scheduled = $this->scheduledCommandStrings();

        // When each scheduled command is checked against the declared list
        // Then none runs undeclared
        foreach ($scheduled as $command) {
            $this->assertTrue(
                collect(self::DECLARED_DAILY_SCANS)->contains(
                    fn (string $scan): bool => $this->runsScan($command, $scan),
                ),
                "Undeclared scheduled command [{$command}] — declare it in "
                    .'DECLARED_DAILY_SCANS so the registration guard covers it.',
            );
        }
    }

    /**
     * @return Collection<int, string>
     */
    protected function scheduledCommandStrings(): Collection
    {
        return collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => (string) $event->command);
    }

    /**
     * Whether $command — the event's full string, which carries the PHP binary
     * and artisan path around the name — runs exactly $scan. The token must end
     * at a boundary, so `trials:expire` cannot match a future
     * `trials:expire-v2` (prefix-ambiguity would let a renamed scan read as
     * still registered).
     */
    protected function runsScan(string $command, string $scan): bool
    {
        return preg_match('/artisan[\'"]?\s+'.preg_quote($scan, '/').'(\s|$)/', $command) === 1;
    }
}
