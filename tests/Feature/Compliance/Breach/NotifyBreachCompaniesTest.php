<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function when_a_company_owner_notifies_then_it_is_not_found_and_nothing_is_sent(): void
    {
        // Given a recorded incident naming company A and A's owner
        $breachId = $this->aRecordedIncident();
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner presses notify
        $response = $this->notifyCompanies($owner, $breachId);

        // Then the admin surface is not acknowledged and nobody is notified
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingNotified($breachId);
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident under that id
        // When the admin presses notify for it
        $response = $this->notifyCompanies($this->admin, $breachId);

        // Then it is not found and nothing is sent
        $this->assertResourceNotFoundNoLeak($response);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownBreachIds(): array
    {
        return [
            'a random uuid' => ['019a5b2e-7c1d-7f00-8a00-000000000000'],
            'not a uuid' => ['not-a-breach'],
        ];
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
