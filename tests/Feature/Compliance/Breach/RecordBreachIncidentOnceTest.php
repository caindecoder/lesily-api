<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachIncidentAlreadyRecordedException;

/**
 * Breach genesis at the domain seam (conv-plan §2.14 Rule C): the record
 * interactor mints a fresh uuid7, so the re-fire guard is unreachable via
 * HTTP — it protects future callers and replay integrity, and is provable
 * only at this seam.
 */
class RecordBreachIncidentOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_a_recorded_incident_is_recorded_again_then_it_is_refused(): void
    {
        // Given an incident already recorded
        $breachId = Str::uuid7()->toString();
        $command = RecordBreachCommand::from($this->validPayload());
        BreachIncident::retrieve($breachId)->record($command, $this->admin)->persist();

        // When the same incident is recorded again
        try {
            BreachIncident::retrieve($breachId)->record($command, $this->admin);
            $this->fail('Recording an incident twice must be refused.');
        } catch (BreachIncidentAlreadyRecordedException) {
            // Then it is refused and the first record stands alone
            $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', BreachRecorded::class)->count());
            $this->assertDatabaseCount('breach_incidents', 1);
        }
    }
}
