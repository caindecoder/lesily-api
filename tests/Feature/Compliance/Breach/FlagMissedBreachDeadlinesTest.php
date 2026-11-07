<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FlagMissedBreachDeadlinesTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string THE_NIGHT_AFTER = '2026-05-08 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::THE_NIGHT_AFTER);
        Queue::fake();
    }

    #[Test]
    public function when_the_date_is_not_a_date_then_the_scan_is_refused_and_nothing_is_flagged(): void
    {
        // Given an incident still recorded past its deadline
        $this->aRecordedIncident();

        // When the daily scan runs with a date that is not a date
        $this->runFlagMissedDeadlines('not-a-date')->expectsOutputToContain('The --date option must be a date, such as 2026-10-31.')->assertFailed();

        // Then nothing is flagged
        $this->assertNothingFlagged();
    }

    #[Test]
    public function when_the_date_is_in_the_future_then_the_scan_is_refused_and_nothing_is_flagged(): void
    {
        // Given an incident still recorded past its deadline
        $this->aRecordedIncident();

        // When the daily scan runs with a date later than now
        $this->runFlagMissedDeadlines('next week')->expectsOutputToContain('The --date option cannot be later than now.')->assertFailed();

        // Then nothing is flagged
        $this->assertNothingFlagged();
    }

    protected function runFlagMissedDeadlines(?string $date = null): PendingCommand
    {
        $command = $this->artisan('breaches:flag-missed-deadlines', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    protected function assertNothingFlagged(): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachDeadlineMissed::class)->count());
        $this->assertSame(0, BreachIncidentRecord::query()->whereNotNull('deadline_missed_at')->count());
        $this->assertNoHandlersQueuedFor(BreachDeadlineMissed::class);
    }
}
