<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The registration guard for the daily scans (OQ-1-155, ruling R7): a scan deleted from
 * routes/console.php would otherwise never run in production with every gate green. Guarded in
 * both directions over one declared list — a declared scan must exist as a command AND be
 * scheduled; every scheduled command must be declared (ruling D3). A deliberate divergence from
 * LeaveDates, which answers the runner question at runtime and the registration question not at all.
 *
 * @testclass-carveout: infra-primitive — pins scheduler wiring; no HTTP seam exists for it.
 */
class ScheduleExpiryScansTest extends FoundationTestCase
{
    /**
     * Every daily scan the platform relies on. A new scan joins by adding its line to
     * routes/console.php AND its name here.
     *
     * @var list<string>
     */
    private const array DECLARED_DAILY_SCANS = [
        'trials:expire',
        'invitations:expire',
    ];

    #[Test]
    #[DataProvider('provideDeclaredDailyScans')]
    public function when_the_daily_schedule_is_inspected_then_the_declared_scan_is_registered(string $scan): void
    {
        // Given the application's registered schedule
        // When the declared scan is looked up
        // Then it exists as a real command AND a schedule line runs it — failing by name on either half
        $this->assertArrayHasKey(
            $scan,
            Artisan::all(),
            "The daily scan '{$scan}' does not resolve to an artisan command — its schedule line would run nothing.",
        );
        $this->assertTrue(
            $this->scheduledCommandStrings()->contains(
                fn (string $command): bool => $this->runsScan($command, $scan),
            ),
            "The daily scan '{$scan}' is not registered in routes/console.php — it would silently never run in production.",
        );
    }

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
                "Undeclared scheduled command [{$command}] — declare it in DECLARED_DAILY_SCANS so the registration guard covers it.",
            );
        }
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

    /**
     * @return Collection<int, string>
     */
    protected function scheduledCommandStrings(): Collection
    {
        return collect(app(Schedule::class)->events())
            ->map(fn (Event $event): string => (string) $event->command);
    }

    /**
     * Whether $command — the event's full string, which carries the PHP binary and artisan path
     * around the name — runs exactly $scan (token-boundary match, so `trials:expire` cannot match a
     * future `trials:expire-v2`).
     */
    protected function runsScan(string $command, string $scan): bool
    {
        return preg_match('/artisan[\'"]?\s+'.preg_quote($scan, '/').'(\s|$)/', $command) === 1;
    }
}
