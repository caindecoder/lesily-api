<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class NotifyBreachCompaniesTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function when_an_unauthenticated_caller_notifies_then_they_are_refused(): void
    {
        // Given a recorded incident
        $breachId = $this->aRecordedIncident();

        // When a caller with no token presses notify
        $response = $this->notifyCompanies(null, $breachId);

        // Then they are refused as unauthenticated and nobody is notified
        $this->assertUnauthenticated($response);
        $this->assertNothingNotified($breachId);
    }

    protected function assertNothingNotified(string $breachId): void
    {
        $incident = BreachIncidentRecord::query()->findOrFail($breachId);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', '!=', BreachRecorded::class)
            ->count());
        $this->assertNull($incident->companies_notified_at);
        $this->assertSame(0, BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->whereNotNull('notified_at')->count());
        Notification::assertNothingSent();
    }
}
