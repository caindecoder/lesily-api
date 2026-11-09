<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Entities\BreachAmendment;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Events\BreachCompanyAdded;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class AddLateBreachCompanyTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string REASON = "The export also reached Initech's folder.";

    protected const string ADDED_AT = '2026-05-06 10:00:00';

    protected const string ADDED_AT_ISO = '2026-05-06T10:00:00+00:00';

    protected const string ADDED_AT_JSON = '2026-05-06T10:00:00.000000Z';

    protected Company $initech;

    /** @var list<User> */
    protected array $initechMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
        $this->initech = Company::factory()->named('Initech Systems')->createOne();
        $this->initechMembers = $this->anOwnerAndAdmins($this->initech);
    }

    #[Test]
    public function when_an_unauthenticated_caller_adds_a_company_then_it_is_refused_as_unauthenticated(): void
    {
        // Given an incident whose companies A and B have been notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->stateOf($breachId);

        // When a caller with no token adds Initech to it
        $response = $this->addCompany(null, $breachId, $this->aLateCompany());

        // Then they are refused as unauthenticated and nothing is added
        $this->assertUnauthenticated($response);
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_adds_a_company_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given an incident whose companies A and B have been notified, and company A's owner
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->stateOf($breachId);

        // When the owner adds Initech on the admin surface
        $response = $this->addCompany($owner, $breachId, $this->aLateCompany());

        // Then the admin surface is not acknowledged and nothing is added
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id

        // When the admin adds Initech to it
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then it is not found and nothing is recorded or queued
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertNoHandlersQueuedFor(BreachCompanyNotified::class);
    }

    #[Test, DataProvider('provideInvalidAdditions')]
    public function when_the_addition_is_invalid_then_it_is_refused_and_nothing_is_added(array $override, string $field, string $message): void
    {
        // Given an incident whose companies A and B have been notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->stateOf($breachId);

        // When the admin adds Initech with one value wrong
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany($override));

        // Then the addition is refused on that field and nothing is added
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test]
    public function when_the_incident_is_not_yet_notified_then_the_late_company_is_refused(): void
    {
        // Given an incident still at recorded, affecting company A
        $breachId = $this->aRecordedIncident();
        $before = $this->stateOf($breachId);

        // When the admin adds Initech as a late company
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then it is refused (the list is still corrected through the amendment) and nothing is added
        $this->assertRefusedAsNotOpen($response, 'A company is added late only after the notices; correct the company list instead.');
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test, DataProvider('provideClosedStages')]
    public function when_the_incident_is_closed_then_the_late_company_is_refused(Closure $closed, string $message): void
    {
        // Given a closed incident
        $breachId = $closed($this->anIncident())->createOne()->getKey();
        $before = $this->stateOf($breachId);

        // When the admin adds Initech as a late company
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then it is refused with the stage named, and nothing is added
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test]
    public function when_the_company_is_already_on_the_incident_then_it_is_refused_and_nobody_is_told(): void
    {
        // Given an incident whose companies A and B have been notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->stateOf($breachId);

        // When the admin adds company A again as a late company
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany(['companyId' => $this->companyA->getKey()]));

        // Then it is refused, nothing is added and no second notice is queued
        $this->assertRefusedAsNotOpen($response, 'The company is already on this breach incident.');
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test]
    public function when_a_late_company_is_added_again_then_it_is_refused_and_nobody_is_told_twice(): void
    {
        // Given an incident whose companies A and B have been notified, and Initech already added late
        $breachId = $this->anIncidentNotifiedEarlier();
        $this->addCompany($this->admin, $breachId, $this->aLateCompany());
        $before = $this->stateOf($breachId);

        // When the admin adds Initech again
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then it is refused and no second notice is recorded or queued
        $this->assertRefusedAsNotOpen($response, 'The company is already on this breach incident.');
        $this->assertSame($before, $this->stateOf($breachId));
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_nothing_is_added_and_nobody_is_told(): void
    {
        // Given an incident whose companies A and B have been notified, and an audit trail that refuses every write
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->stateOf($breachId);
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin adds Initech as a late company
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then the request fails closed: neither event is recorded and no row is written
        $response->assertInternalServerError();
        $this->assertNoEventOrRowWritten($breachId, $before);
    }

    public static function provideClosedStages(): array
    {
        return [
            'resolved' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->resolved(), 'A company cannot be added to a resolved breach incident.'],
            'a false alarm' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm(), 'A company cannot be added to a breach incident marked as a false alarm.'],
        ];
    }

    public static function provideInvalidAdditions(): array
    {
        return [
            'no company' => [['companyId' => null], 'companyId', 'The company id field is required.'],
            'a company id that is not a uuid' => [['companyId' => 'initech'], 'companyId', 'The company id field must be a valid UUID.'],
            'a company that does not exist' => [['companyId' => '01980000-0000-7000-8000-000000000000'], 'companyId', 'The selected company id is invalid.'],
            'no data types' => [['dataTypes' => null], 'dataTypes', 'The data types field is required.'],
            'an empty list of data types' => [['dataTypes' => []], 'dataTypes', 'The data types field is required.'],
            'data types that are not a list' => [['dataTypes' => 'payroll'], 'dataTypes', 'The data types field must be an array.'],
            'data types keyed by name' => [['dataTypes' => ['first' => 'payroll']], 'dataTypes', 'The data types field must be a list.'],
            'a data type outside the seven' => [['dataTypes' => ['passwords']], 'dataTypes.0', 'The selected dataTypes.0 is invalid.'],
            'a negative count' => [['estimatedCount' => -1], 'estimatedCount', 'The estimated count field must be at least 0.'],
            'a count beyond the largest the record holds' => [['estimatedCount' => 4294967296], 'estimatedCount', 'The estimated count field must not be greater than 4294967295.'],
            'a count that is not a number' => [['estimatedCount' => 'many'], 'estimatedCount', 'The estimated count field must be an integer.'],
            'no reason' => [['reason' => null], 'reason', 'The reason field is required.'],
            'a reason over 1,000 characters' => [['reason' => str_repeat('a', 1001)], 'reason', 'The reason field must not be greater than 1000 characters.'],
            'a reason that is not text' => [['reason' => ['a list']], 'reason', 'The reason field must be a string.'],
        ];
    }

    #[Test]
    public function when_a_company_is_added_late_then_no_notice_is_sent_until_the_queued_reactor_runs(): void
    {
        // Given an incident at companies_notified affecting companies A and B
        $breachId = $this->anIncidentNotifiedEarlier();

        // When the admin adds Initech as a late company
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then no notice has gone out yet: Initech's notice waits on the queue
        $response->assertOk();
        $this->assertReactorDeferred(BreachCompanyNotified::class, SystemNotification::class);
    }

    #[Test]
    public function when_a_late_company_s_count_is_not_known_then_its_notice_says_so(): void
    {
        // Given an incident at companies_notified affecting companies A and B
        $breachId = $this->anIncidentNotifiedEarlier();

        // When the admin adds Initech with no count, and the notice leaves the queue
        $this->addCompany($this->admin, $breachId, $this->aLateCompany(['estimatedCount' => null]));
        $this->runQueuedHandlersFor(BreachCompanyNotified::class);

        // Then Initech's owner and admins are told the count is not known
        $this->assertCount(3, $this->initechMembers);
        foreach ($this->initechMembers as $member) {
            Notification::assertSentTo($member, SystemNotification::class, fn (SystemNotification $notice): bool => $notice->details['count'] === 'Not yet known');
        }
    }

    #[Test]
    public function when_a_company_on_another_incident_is_added_late_then_that_incident_is_untouched(): void
    {
        // Given an incident naming Initech, and a second incident at companies_notified affecting companies A and B
        $otherBreachId = $this->aNotifiedIncident($this->initech);
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $otherBefore = $this->stateOf($otherBreachId);

        // When the admin adds Initech to the second incident as a late company
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then it is added there, and the first incident's record, events and rows are unchanged
        $response->assertOk();
        $this->assertSame(array_slice($otherBefore, 0, 2), array_slice($this->stateOf($otherBreachId), 0, 2));
    }

    #[Test]
    public function when_two_late_companies_are_added_then_each_audit_row_cites_its_own_event(): void
    {
        // Given an incident at companies_notified affecting companies A and B, and a fourth company found late too
        $breachId = $this->anIncidentNotifiedEarlier();
        $hooli = Company::factory()->named('Hooli Retail')->createOne();

        // When the admin adds Initech, then Hooli
        $this->addCompany($this->admin, $breachId, $this->aLateCompany());
        $this->addCompany($this->admin, $breachId, $this->aLateCompany(['companyId' => $hooli->getKey()]));

        // Then each audit row cites the BreachCompanyAdded event of its own company
        $added = LesilyStoredEvent::query()->where('event_class', BreachCompanyAdded::class)->orderBy('id')->pluck('id')->all();
        $cited = AuditEntry::query()->where('action', 'breach_company_added')->orderBy('id')->pluck('stored_event_id')->all();
        $this->assertCount(2, $added);
        $this->assertSame($added, $cited);
    }

    #[Test]
    public function when_a_late_company_is_added_then_its_owner_sees_the_notice_in_the_company_list(): void
    {
        // Given an incident at companies_notified affecting companies A and B, and Initech's owner
        $breachId = $this->anIncidentNotifiedEarlier();
        $owner = $this->aConsentingMember($this->initech, 'owner');

        // When the admin adds Initech as a late company and Initech's owner lists its breach notices
        $this->addCompany($this->admin, $breachId, $this->aLateCompany());
        $response = $this->listNotices($owner, $this->initech);

        // Then the list holds this incident with Initech's own figures
        $response->assertOk();
        $this->assertSame([[$breachId, ['payroll', 'banking'], 15]], array_map(
            fn (array $notice): array => [$notice['breach_id'], $notice['data_types'], $notice['estimated_count']],
            $response->json('data'),
        ));
    }

    #[Test]
    public function when_a_late_company_is_added_then_its_owner_can_ask_lesily_to_file(): void
    {
        // Given an incident at companies_notified affecting companies A and B, Initech added late, and Initech's owner
        $breachId = $this->anIncidentNotifiedEarlier();
        $owner = $this->aConsentingMember($this->initech, 'owner');
        $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // When Initech's owner asks Lesily to file with the regulator
        $response = $this->requestFiling($owner, $this->initech, $breachId);

        // Then the request is taken and Initech's row carries it
        $response->assertOk();
        $this->assertNotNull(BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->where('company_id', $this->initech->getKey())->sole()->filing_requested_at);
    }

    #[Test]
    public function when_a_company_found_late_is_added_then_it_is_recorded_projected_audited_and_told(): void
    {
        // Given an incident at companies_notified affecting companies A and B, whose owners and admins
        // already hold their notices
        $breachId = $this->anIncidentNotifiedEarlier();
        $this->travelTo(self::ADDED_AT);

        // When the admin adds Initech with its data types, about 15 people and a reason, and the notice leaves the queue
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());
        $this->runQueuedHandlersFor(BreachCompanyNotified::class);

        // Then Initech is added and notified in the event store and the read model, the action is audited,
        // and only Initech's owner and admins hear Initech's own part
        $this->assertLateCompanyAnswered($response);
        $storedEventId = $this->assertLateCompanyStored($breachId);
        $this->assertLateCompanyProjected($breachId);
        $this->assertAddAudited($response, $breachId, $storedEventId);
        $this->assertOnlyInitechTold();
    }

    protected function anIncidentNotifiedEarlier(): string
    {
        $this->anOwnerAndAdmins($this->companyA);
        $this->anOwnerAndAdmins($this->companyB);
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        Queue::fake();

        return $breachId;
    }

    protected function assertLateCompanyAnswered(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.stage', 'companies_notified');
        /** @var list<array<string, mixed>> $companies */
        $companies = $response->json('data.companies');
        $initech = collect($companies)->firstWhere('company_id', $this->initech->getKey());
        $this->assertIsArray($initech);
        $this->assertSame([['payroll', 'banking'], 15, self::ADDED_AT_JSON], [$initech['data_types'], $initech['estimated_count'], $initech['notified_at']]);
        $this->assertSame(['late_company_added', $this->initech->getKey(), self::REASON], [
            $response->json('data.amendments.0.field'),
            $response->json('data.amendments.0.company_id'),
            $response->json('data.amendments.0.reason'),
        ]);
    }

    protected function assertLateCompanyStored(string $breachId): int
    {
        $events = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->orderBy('id')->get();
        $this->assertSame([BreachCompanyAdded::class, BreachCompanyNotified::class], $events->slice(-2)->pluck('event_class')->values()->all());
        $this->assertCount(6, $events);

        $added = $events->firstWhere('event_class', BreachCompanyAdded::class);
        $notified = $events->last();
        $this->assertInstanceOf(LesilyStoredEvent::class, $added);
        $this->assertInstanceOf(LesilyStoredEvent::class, $notified);
        $this->assertEquals([$breachId, $this->initech->getKey(), ['payroll', 'banking'], 15, self::REASON, $this->admin->getKey(), self::ADDED_AT_ISO], [
            $added->event_properties['breachId'],
            $added->event_properties['companyId'],
            $added->event_properties['dataTypes'],
            $added->event_properties['estimatedCount'],
            $added->event_properties['reason'],
            $added->event_properties['addedByUserId'],
            CarbonImmutable::parse($added->event_properties['addedAt'])->toIso8601String(),
        ]);
        $this->assertEquals([$this->initech->getKey(), $this->admin->getKey(), self::ADDED_AT_ISO, $this->initechPart()], [
            $notified->event_properties['companyId'],
            $notified->event_properties['notifiedByUserId'],
            CarbonImmutable::parse($notified->event_properties['notifiedAt'])->toIso8601String(),
            $notified->event_properties['part'],
        ]);

        return (int) $added->getKey();
    }

    protected function assertLateCompanyProjected(string $breachId): void
    {
        $row = BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->where('company_id', $this->initech->getKey())->sole();
        $this->assertSame([['payroll', 'banking'], 15, self::REASON, $this->admin->getKey()], [$row->data_types, $row->estimated_count, $row->added_reason, $row->added_by]);
        $this->assertSame([self::ADDED_AT_ISO, self::ADDED_AT_ISO], [$row->added_at->toIso8601String(), $row->notified_at?->toIso8601String()]);

        $history = BreachAmendment::query()->where('breach_incident_id', $breachId)->sole();
        $this->assertEquals(
            [BreachAmendmentField::LateCompanyAdded, $this->initech->getKey(), null, ['dataTypes' => ['payroll', 'banking'], 'estimatedCount' => 15], self::REASON, $this->admin->getKey()],
            [$history->field, $history->company_id, $history->old_value, $history->new_value, $history->reason, $history->amended_by],
        );
        $this->assertSame(BreachStage::CompaniesNotified, BreachIncidentRecord::query()->findOrFail($breachId)->stage);
    }

    protected function assertAddAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachCompanyAdded',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_company_added',
            'description' => 'Platform admin added a company found after the breach notices and told it.',
            'metadata' => null,
            'recorded_at' => self::ADDED_AT,
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'severity' => 'critical',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->sole();
        $this->assertSame([$correlationId, 'Symfony'], [$entry->request_id, $entry->user_agent]);
    }

    protected function assertOnlyInitechTold(): void
    {
        $this->assertCount(3, $this->initechMembers);
        foreach ($this->initechMembers as $member) {
            Notification::assertSentToTimes($member, SystemNotification::class, 1);
            Notification::assertSentTo($member, SystemNotification::class, fn (SystemNotification $notice): bool => $notice->type === NotificationType::BreachCompanyNotice
                && $notice->companyName === 'Initech Systems'
                && $notice->companyId === $this->initech->getKey()
                && $notice->details === $this->initechPart());
        }
        Notification::assertCount(3);
    }

    /**
     * @return array<string, string>
     */
    protected function initechPart(): array
    {
        return [
            'discovered' => '4 May 2026, 14:00 UTC',
            'deadline' => '7 May 2026, 14:00 UTC',
            'description' => 'A payroll export was emailed to the wrong recipient.',
            'consequences' => 'Salary and bank details may be read by one outside person.',
            'measures' => 'The recipient was asked to delete the file; exports now need a second check.',
            'data_types' => 'Payroll records, Bank details',
            'count' => '15',
        ];
    }

    protected function addCompany(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/companies", $body);
    }

    protected function aLateCompany(array $overrides = []): array
    {
        return [
            'companyId' => $this->initech->getKey(),
            'dataTypes' => ['payroll', 'banking'],
            'estimatedCount' => 15,
            'reason' => self::REASON,
            ...$overrides,
        ];
    }

    /**
     * @return array{0: array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}, 1: int, 2: int}
     */
    protected function stateOf(string $breachId): array
    {
        return [
            $this->snapshotOf($breachId),
            LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count(),
            $this->queuedHandlerCountFor(BreachCompanyNotified::class),
        ];
    }

    protected function assertNothingAdded(string $breachId, array $before): void
    {
        $this->assertNoEventOrRowWritten($breachId, $before);
        $this->assertSame($before[2], $this->queuedHandlerCountFor(BreachCompanyNotified::class));
    }

    protected function assertNoEventOrRowWritten(string $breachId, array $before): void
    {
        $this->assertSame(array_slice($before, 0, 2), array_slice($this->stateOf($breachId), 0, 2));
        $this->assertDatabaseCount('breach_amendments', 0);
        $this->assertDatabaseCount('audit_trail', 0);
    }
}
