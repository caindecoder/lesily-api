<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;
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

    #[Test]
    #[DataProvider('provideLaterStages')]
    public function when_the_incident_is_past_recorded_then_the_notify_is_refused_with_its_stage_named(string $stage): void
    {
        // Given an incident that has moved past Recorded
        $incident = match ($stage) {
            'companies_notified' => $this->anIncident()->companiesNotified(),
            'resolved' => $this->anIncident()->resolved(),
            'false_alarm' => $this->anIncident()->falseAlarm(),
            default => $this->fail('Unknown stage: '.$stage),
        };
        $incident = $incident->withCompany($this->companyA, ['pii'], 40)->createOne();
        $before = $this->snapshotOf($incident);

        // When the admin presses notify
        $response = $this->notifyCompanies($this->admin, $incident->getKey());

        // Then it is refused with its stage named, and the incident is left as it was
        $this->assertRefusedAt($response, $stage);
        $this->assertIncidentUnchanged($incident, $before);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLaterStages(): array
    {
        return [
            'companies notified (a re-press)' => ['companies_notified'],
            'resolved' => ['resolved'],
            'false alarm' => ['false_alarm'],
        ];
    }

    #[Test]
    public function when_the_companies_are_notified_then_no_notice_leaves_before_the_commit(): void
    {
        // Given a recorded incident naming company A, which has an owner and two admins
        $breachId = $this->aRecordedIncident();
        $this->anOwnerAndAdmins($this->companyA);

        // When the admin presses notify
        $response = $this->notifyCompanies($this->admin, $breachId);

        // Then no notice has gone out yet: each company's notice waits on the queue for the commit
        $response->assertOk();
        $this->assertReactorDeferred(BreachCompanyNotified::class, SystemNotification::class);
    }

    protected function assertRefusedAt(TestResponse $response, string $stage): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::StateGuardViolation);
        $response->assertJsonPath('message', "Only a recorded breach incident can have its companies notified; this incident is {$stage}.");
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function snapshotOf(BreachIncidentRecord $incident): array
    {
        return [
            BreachIncidentRecord::query()->findOrFail($incident->getKey())->getAttributes(),
            BreachIncidentCompany::query()->where('breach_incident_id', $incident->getKey())->get()->map->getAttributes()->all(),
        ];
    }

    /**
     * @param  array{0: array<string, mixed>, 1: list<array<string, mixed>>}  $before
     */
    protected function assertIncidentUnchanged(BreachIncidentRecord $incident, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($incident));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $incident->getKey())->count());
        $this->assertDatabaseCount('audit_trail', 0);
        Notification::assertNothingSent();
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
