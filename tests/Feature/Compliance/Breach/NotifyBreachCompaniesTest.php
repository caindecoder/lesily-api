<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Enums\BreachDataType;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class NotifyBreachCompaniesTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string NOTIFIED_AT = '2026-05-05 14:00:00';

    protected const string NOTIFIED_AT_JSON = '2026-05-05T14:00:00.000000Z';

    protected Company $acmeCompany;

    protected Company $globexCompany;

    /** @var list<User> */
    protected array $acmeMembers;

    /** @var list<User> */
    protected array $globexMembers;

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

        // When the owner presses notify
        $response = $this->notifyCompanies($this->owner, $breachId);

        // Then the admin surface is not acknowledged and nobody is notified
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingNotified($breachId);
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident under that id
        // When the platform admin presses notify for it
        $response = $this->notifyCompanies($this->platformAdmin, $breachId);

        // Then it is not found and no notice is queued
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoHandlersQueuedFor(BreachCompanyNotified::class);
    }

    #[Test]
    #[DataProvider('provideLaterStages')]
    public function when_the_incident_is_past_recorded_then_the_notify_is_refused_with_its_stage_named(string $stage): void
    {
        // Given an incident that has moved past Recorded
        $breachIncidentRecordFactory = match ($stage) {
            'companies_notified' => $this->anIncident()->companiesNotified(),
            'resolved' => $this->anIncident()->resolved(),
            'false_alarm' => $this->anIncident()->falseAlarm(),
            default => $this->fail('Unknown stage: '.$stage),
        };
        $breachIncident = $breachIncidentRecordFactory->withCompany($this->contosoCompany, ['pii'], 40)->createOne();
        $before = $this->snapshotOf($breachIncident->getKey());

        // When the platform admin presses notify
        $response = $this->notifyCompanies($this->platformAdmin, $breachIncident->getKey());

        // Then it is refused with its stage named, and the incident is left as it was
        $this->assertRefusedAt($response, $stage);
        $this->assertIncidentUnchanged($breachIncident, $before);
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
    public function when_the_audit_row_cannot_be_written_then_nobody_is_notified(): void
    {
        // Given a recorded incident naming companies A and B, and an audit trail that refuses every write
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40],
            ['companyId' => $this->otherCompany->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 7],
        ]]);
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the platform admin presses notify
        $response = $this->notifyCompanies($this->platformAdmin, $breachId);

        // Then the request fails closed: nothing recorded and the incident still Recorded
        $this->assertFailedClosed($response);
        $this->assertStillRecorded($breachId);
    }

    #[Test]
    public function when_the_companies_are_notified_then_no_notice_is_sent_until_the_queued_reactor_runs(): void
    {
        // Given a recorded incident naming company A, which has an owner and two admins
        $breachId = $this->aRecordedIncident();
        $this->aCompanyMember($this->company, 'admin');

        // When the platform admin presses notify
        $response = $this->notifyCompanies($this->platformAdmin, $breachId);

        // Then no notice has gone out yet: each company's notice waits on the queue
        $response->assertOk();
        $this->assertReactorDeferred(BreachCompanyNotified::class, SystemNotification::class);
    }

    #[Test]
    public function when_a_low_severity_incident_is_notified_then_its_companies_are_told_too(): void
    {
        // Given a recorded low-severity incident naming company A, which has an owner and two admins
        $breachId = $this->aRecordedIncident(['severity' => 'low']);
        $this->aCompanyMember($this->company, 'admin');
        $this->travelTo(self::NOTIFIED_AT);

        // When the platform admin presses notify and the notices leave the queue
        $response = $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then the incident is at companies notified and A's owner and both admins are told
        $this->assertNotified($response, $breachId);
        Notification::assertCount(3);
    }

    #[Test]
    public function when_a_company_count_is_not_known_then_its_notice_says_so(): void
    {
        // Given a recorded incident naming company A with no estimated count, and A's owner and admin
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => null],
        ]]);

        // When the platform admin presses notify and the notice leaves the queue
        $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then A's notice says the number of people affected is not yet known
        $this->assertToldOnceEach([$this->owner, $this->admin], $this->contosoCompany, $this->partFor('Personal details', 'Not yet known'));
    }

    #[Test]
    public function when_a_company_has_other_members_then_only_the_owner_and_admins_are_told(): void
    {
        // Given a recorded incident naming company A, which has an owner, two admins,
        // an HR officer, a payroll officer, two employees and an admin who was removed
        $breachId = $this->aRecordedIncident();
        $toldMembers = [$this->owner, $this->admin, $this->aCompanyMember($this->company, 'admin')];
        foreach (['hr_officer', 'payroll_officer', 'employee'] as $role) {
            $this->aCompanyMember($this->contosoCompany, $role);
        }
        $admin = $this->aCompanyMember($this->company, 'admin');
        $this->softDeleteMembership($this->company, $admin);

        // When the platform admin presses notify and the notices leave the queue
        $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then only the owner and the two current admins are told, once each
        $this->assertToldExactly($toldMembers, $this->contosoCompany);
    }

    #[Test]
    public function when_two_companies_are_notified_then_each_hears_only_its_own_part(): void
    {
        // Given a recorded incident naming Acme Holdings (personal and bank details, 120 people)
        // and Globex Traders (payroll records, 30 people), each with an owner and an admin
        $breachId = $this->anIncidentNamingAcmeAndGlobex();

        // When the platform admin presses notify and the notices leave the queue
        $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then each company's owner and admin hear that company's own part and nothing of the other's
        $this->assertAcmeAndGlobexToldTheirOwnParts();
    }

    protected function anIncidentNamingAcmeAndGlobex(): string
    {
        $this->acmeCompany = Company::factory()->named('Acme Holdings')->createOne();
        $this->globexCompany = Company::factory()->named('Globex Traders')->createOne();
        $this->acmeMembers = [$this->aCompanyMember($this->acmeCompany, 'owner'), $this->aCompanyMember($this->acmeCompany, 'admin')];
        $this->globexMembers = [$this->aCompanyMember($this->globexCompany, 'owner'), $this->aCompanyMember($this->globexCompany, 'admin')];

        return $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->acmeCompany->getKey(), 'dataTypes' => ['pii', 'banking'], 'estimatedCount' => 120],
            ['companyId' => $this->globexCompany->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 30],
        ]]);
    }

    protected function assertAcmeAndGlobexToldTheirOwnParts(): void
    {
        $this->assertToldOnceEach($this->acmeMembers, $this->acmeCompany, $this->partFor('Personal details, Bank details', '120'));
        $this->assertToldOnceEach($this->globexMembers, $this->globexCompany, $this->partFor('Payroll records', '30'));
        Notification::assertCount(4);
    }

    #[Test]
    public function when_two_incidents_are_notified_then_each_audit_row_cites_its_own_event(): void
    {
        // Given two recorded incidents, the first already notified
        $first = $this->aRecordedIncident();
        $second = $this->aRecordedIncident();
        $this->notifyCompanies($this->platformAdmin, $first);

        // When the platform admin notifies the second
        $this->notifyCompanies($this->platformAdmin, $second);

        // Then the second's audit row cites the second's own notified event
        $this->assertSame(
            $this->notifiedEventIdOf($second),
            AuditEntry::query()->where('subject_id', $second)->sole()->stored_event_id,
        );
        $this->assertNotSame($this->notifiedEventIdOf($first), $this->notifiedEventIdOf($second));
    }

    #[Test]
    public function when_a_company_is_named_on_two_incidents_then_notifying_one_leaves_the_other_untouched(): void
    {
        // Given two recorded incidents, both naming company A
        $notified = $this->aRecordedIncident();
        $untouched = $this->aRecordedIncident();

        // When the platform admin notifies the first
        $this->notifyCompanies($this->platformAdmin, $notified);

        // Then the second incident is still Recorded and company A's row on it carries no notice stamp
        $this->assertStillRecorded($untouched);
    }

    protected function notifiedEventIdOf(string $breachId): int
    {
        return (int) LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', BreachCompaniesNotified::class)
            ->sole()
            ->getKey();
    }

    #[Test]
    public function when_a_company_has_nobody_to_tell_then_the_gap_is_reported_and_the_others_are_told(): void
    {
        // Given a recorded incident naming company A (an owner and an admin) and company B (no members)
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40],
            ['companyId' => $this->otherCompany->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 7],
        ]]);
        $toldMembers = [$this->owner, $this->admin];
        Exceptions::fake();

        // When the platform admin presses notify and the notices leave the queue
        $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then B's missing recipients are reported and A's owner and admin are still told
        Exceptions::assertReported(fn (RuntimeException $runtimeException): bool => $runtimeException->getMessage() === "No owner or admin of company {$this->otherCompany->getKey()} to tell about breach incident {$breachId}.");
        $this->assertToldExactly($toldMembers, $this->contosoCompany);
    }

    #[Test]
    #[DataProvider('provideDataTypes')]
    public function when_any_data_type_is_named_then_its_notice_has_words_for_it(string $dataType, string $words): void
    {
        // Given a recorded incident naming company A with one data type, and A's owner and admin
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => [$dataType], 'estimatedCount' => 40],
        ]]);

        // When the platform admin presses notify and the notice leaves the queue
        $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then the notice names that data type in words
        $this->assertToldOnceEach([$this->owner, $this->admin], $this->contosoCompany, $this->partFor($words, '40'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideDataTypes(): array
    {
        $words = [
            'pii' => 'Personal details',
            'payroll' => 'Payroll records',
            'banking' => 'Bank details',
            'nic' => 'National identity card numbers',
            'epf_etf' => 'EPF and ETF records',
            'medical' => 'Medical records',
            'other' => 'Other personal data',
        ];

        return collect(BreachDataType::cases())
            ->mapWithKeys(fn (BreachDataType $breachDataType): array => [$breachDataType->value => [$breachDataType->value, $words[$breachDataType->value]]])
            ->all();
    }

    #[Test]
    public function when_an_admin_notifies_the_companies_then_they_are_recorded_projected_audited_and_told(): void
    {
        // Given a recorded incident naming Acme Holdings and Globex Traders, each with an owner and an admin
        $breachId = $this->anIncidentNamingAcmeAndGlobex();
        $this->travelTo(self::NOTIFIED_AT);

        // When the platform admin presses notify and the notices leave the queue
        $response = $this->notifyCompanies($this->platformAdmin, $breachId);
        $this->runTheNotices();

        // Then each company's notice is recorded and projected, the action is audited,
        // and each company's owner and admin hear that company's own part
        $this->assertNotified($response, $breachId);
        $storedEventId = $this->assertNotificationsStored($breachId);
        $this->assertNotifyAudited($response, $breachId, $storedEventId);
        $this->assertAcmeAndGlobexToldTheirOwnParts();
    }

    protected function assertNotificationsStored(string $breachId): int
    {
        $eloquentStoredEvents = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->orderBy('id')->get();
        $this->assertSame(
            [BreachRecorded::class, BreachCompanyNotified::class, BreachCompanyNotified::class, BreachCompaniesNotified::class],
            $eloquentStoredEvents->pluck('event_class')->all(),
        );

        $parts = $eloquentStoredEvents->where('event_class', BreachCompanyNotified::class)
            ->mapWithKeys(fn (EloquentStoredEvent $eloquentStoredEvent): array => [$eloquentStoredEvent->event_properties['companyId'] => [
                $eloquentStoredEvent->event_properties['notifiedByUserId'],
                CarbonImmutable::parse($eloquentStoredEvent->event_properties['notifiedAt'])->toIso8601String(),
                $eloquentStoredEvent->event_properties['part'],
            ]])->all();
        $notifiedAt = CarbonImmutable::parse(self::NOTIFIED_AT)->toIso8601String();
        $this->assertEquals([
            $this->acmeCompany->getKey() => [$this->platformAdmin->getKey(), $notifiedAt, $this->partFor('Personal details, Bank details', '120')],
            $this->globexCompany->getKey() => [$this->platformAdmin->getKey(), $notifiedAt, $this->partFor('Payroll records', '30')],
        ], $parts);

        $closingEloquentStoredEvent = $eloquentStoredEvents->last();
        $this->assertInstanceOf(LesilyStoredEvent::class, $closingEloquentStoredEvent);
        $this->assertSame([$this->platformAdmin->getKey(), $notifiedAt], [
            $closingEloquentStoredEvent->event_properties['notifiedByUserId'],
            CarbonImmutable::parse($closingEloquentStoredEvent->event_properties['notifiedAt'])->toIso8601String(),
        ]);

        return (int) $closingEloquentStoredEvent->getKey();
    }

    protected function assertNotifyAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachCompaniesNotified',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_companies_notified',
            'description' => 'Platform admin notified the companies affected by a data breach.',
            'metadata' => null,
            'recorded_at' => self::NOTIFIED_AT,
            'actor_user_id' => $this->platformAdmin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->platformAdmin->name,
            'ip_address' => '127.0.0.1',
            'severity' => 'critical',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $auditEntry = AuditEntry::query()->where('request_id', $correlationId)->sole();
        $this->assertSame('Symfony', $auditEntry->user_agent);
    }

    /**
     * @return array<string, string>
     */
    protected function partFor(string $dataTypes, string $count): array
    {
        return [
            'discovered' => '4 May 2026, 14:00 UTC',
            'deadline' => '7 May 2026, 14:00 UTC',
            'description' => 'A payroll export was emailed to the wrong recipient.',
            'consequences' => 'Salary and bank details may be read by one outside person.',
            'measures' => 'The recipient was asked to delete the file; exports now need a second check.',
            'data_types' => $dataTypes,
            'count' => $count,
        ];
    }

    /**
     * @param  list<User>  $recipients
     * @param  array<string, string>  $part
     */
    protected function assertToldOnceEach(array $recipients, Company $company, array $part): void
    {
        $this->assertNotEmpty($recipients);
        foreach ($recipients as $recipient) {
            Notification::assertSentToTimes($recipient, SystemNotification::class, 1);
            Notification::assertSentTo($recipient, SystemNotification::class, fn (SystemNotification $systemNotification): bool => $systemNotification->notificationType === NotificationType::BreachCompanyNotice
                && $systemNotification->companyName === $company->name
                && $systemNotification->companyId === $company->getKey()
                && $systemNotification->details === $part);
        }
    }

    /**
     * @param  list<User>  $recipients
     */
    protected function assertToldExactly(array $recipients, Company $company): void
    {
        $this->assertNotEmpty($recipients);
        foreach ($recipients as $recipient) {
            Notification::assertSentToTimes($recipient, SystemNotification::class, 1);
            Notification::assertSentTo($recipient, SystemNotification::class, fn (SystemNotification $systemNotification): bool => $systemNotification->notificationType === NotificationType::BreachCompanyNotice
                && $systemNotification->companyName === $company->name
                && $systemNotification->companyId === $company->getKey());
        }
        Notification::assertCount(count($recipients));
    }

    protected function runTheNotices(): void
    {
        $this->runQueuedHandlersFor(BreachCompanyNotified::class);
    }

    protected function assertNotified(TestResponse $response, string $breachId): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.stage', 'companies_notified');
        $this->assertSame([self::NOTIFIED_AT_JSON], array_values(array_unique([
            $response->json('data.companies_notified_at'),
            ...array_column($response->json('data.companies'), 'notified_at'),
        ])));

        $breachIncident = BreachIncidentRecord::query()->with('companies')->findOrFail($breachId);
        $this->assertSame(BreachStage::CompaniesNotified, $breachIncident->stage);
        $this->assertNotEmpty($breachIncident->companies);
        foreach ([$breachIncident->companies_notified_at, ...$breachIncident->companies->pluck('notified_at')] as $stamp) {
            $this->assertTrue(CarbonImmutable::parse(self::NOTIFIED_AT)->equalTo($stamp));
        }
    }

    protected function assertRefusedAt(TestResponse $response, string $stage): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::StateGuardViolation);
        $response->assertJsonPath('message', "Only a recorded breach incident can have its companies notified; this incident is {$stage}.");
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertIncidentUnchanged(BreachIncidentRecord $breachIncident, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachIncident->getKey()));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $breachIncident->getKey())->count());
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
        $this->assertNoHandlersQueuedFor(BreachCompanyNotified::class);
    }

    protected function assertNothingNotified(string $breachId): void
    {
        $this->assertStillRecorded($breachId);
        $this->assertNoHandlersQueuedFor(BreachCompanyNotified::class);
    }

    protected function assertStillRecorded(string $breachId): void
    {
        $breachIncident = BreachIncidentRecord::query()->whereKey($breachId)->firstOrFail();

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', '!=', BreachRecorded::class)
            ->count());
        $this->assertNull($breachIncident->companies_notified_at);
        $this->assertSame(0, BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->whereNotNull('notified_at')->count());
    }
}
