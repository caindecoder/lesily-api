<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Console\Scheduling\Schedule;

/**
 * OQ-1-155 (ruling R7, Task R1) — the registration guard for the daily expiry
 * scans. Nothing else anywhere detects a scheduled command that was never
 * registered: delete a `Schedule::command` line from `routes/console.php` and
 * the suite stays green, CI stays green, and the command simply never runs in
 * production — trials never end, invitations never lapse, transfers accumulate
 * past their SLA forever, silently.
 *
 * This is a BORN-GREEN characterization pin, stated as such: it pins framework
 * wiring, and that trade is accepted deliberately (M6's objection, conceded by
 * R7) because "this command runs daily in production" has no other observable,
 * and no production exists yet to observe it in. Teeth proven by revert-check
 * at build: removing any one registration line REDs this test naming that scan.
 *
 * One test over a DECLARED LIST, so a fourth scan is one array entry and the
 * guard cannot drift back into one-pinned-two-unpinned (the inconsistency M6
 * rightly refused). Matching is on a SUBSTRING of each event's command string,
 * which carries the full PHP binary path around the scan name.
 *
 * Deliberate divergence from LeaveDates (Rule #15): LD runs 31 scheduled tasks
 * with zero registration coverage and answers the problem at runtime instead
 * (schedule-monitor + health heartbeat) — but a heartbeat detects a dead
 * RUNNER, not a deleted LINE; LD's own `schedule-monitor:sync` deletes the
 * alarm along with a deleted task. The runner question is master-action #116;
 * this guard answers the registration question only.
 */
class ScheduleExpiryScansTest extends FoundationTestCase
{
    /**
     * Every daily scan the platform relies on. A new scan joins the schedule by
     * adding its line to `routes/console.php` AND its name here.
     *
     * @var list<string>
     */
    private const array DECLARED_DAILY_SCANS = [
        'trials:expire',
        'invitations:expire',
        'ownership-transfers:expire-stale',
    ];

    #[Test]
    public function when_the_daily_schedule_is_inspected_then_every_declared_scan_is_registered(): void
    {
        // Given the application's registered schedule
        $registered = collect(app(Schedule::class)->events())
            ->map(fn (object $event): string => (string) $event->command);

        // When each declared daily scan is looked up
        // Then every one of them is registered — failing BY NAME on any missing
        foreach (self::DECLARED_DAILY_SCANS as $scan) {
            $this->assertTrue(
                $registered->contains(fn (string $command): bool => str_contains($command, $scan)),
                "The daily scan '{$scan}' is not registered in routes/console.php — "
                    .'it would silently never run in production.',
            );
        }
    }
}
