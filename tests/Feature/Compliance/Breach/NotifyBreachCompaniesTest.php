<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
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
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class NotifyBreachCompaniesTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string NOTIFIED_AT = '2026-05-05 14:00:00';

    protected const string NOTIFIED_AT_JSON = '2026-05-05T14:00:00.000000Z';

    protected Company $acme;

    protected Company $globex;

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

    #[Test]
    public function when_a_low_severity_incident_is_notified_then_its_companies_are_told_too(): void
    {
        // Given a recorded low-severity incident naming company A, which has an owner and two admins
        $breachId = $this->aRecordedIncident(['severity' => 'low']);
        $this->anOwnerAndAdmins($this->companyA);
        $this->travelTo(self::NOTIFIED_AT);

        // When the admin presses notify and the notices leave the queue
        $response = $this->notifyCompanies($this->admin, $breachId);
        $this->runTheNotices();

        // Then the incident is at companies notified and A's owner and both admins are told
        $this->assertNotified($response, $breachId);
        Notification::assertCount(3);
    }

    #[Test]
    public function when_a_company_count_is_not_known_then_its_notice_says_so(): void
    {
        // Given a recorded incident naming company A with no estimated count, and A's owner
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => null],
        ]]);
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the admin presses notify and the notice leaves the queue
        $this->notifyCompanies($this->admin, $breachId);
        $this->runTheNotices();

        // Then A's notice says the number of people affected is not yet known
        $this->assertToldOnceEach([$owner], $this->companyA, $this->partFor('Personal details', 'Not yet known'));
    }

    #[Test]
    public function when_a_company_has_other_members_then_only_the_owner_and_admins_are_told(): void
    {
        // Given a recorded incident naming company A, which has an owner, two admins,
        // an HR officer, a payroll officer, two employees and an admin who was removed
        $breachId = $this->aRecordedIncident();
        $toldMembers = $this->anOwnerAndAdmins($this->companyA);
        foreach (['hr_officer', 'payroll_officer', 'employee', 'employee'] as $role) {
            $this->aCompanyMember($this->companyA, $role);
        }
        $this->aRemovedMember($this->companyA, 'admin');

        // When the admin presses notify and the notices leave the queue
        $this->notifyCompanies($this->admin, $breachId);
        $this->runTheNotices();

        // Then only the owner and the two current admins are told, once each
        $this->assertToldExactly($toldMembers, $this->companyA);
    }

    #[Test]
    public function when_two_companies_are_notified_then_each_hears_only_its_own_part(): void
    {
        // Given a recorded incident naming Acme Holdings (personal and bank details, 120 people)
        // and Globex Traders (payroll records, 30 people), each with an owner and an admin
        $breachId = $this->anIncidentNamingAcmeAndGlobex();

        // When the admin presses notify and the notices leave the queue
        $this->notifyCompanies($this->admin, $breachId);
        $this->runTheNotices();

        // Then each company's owner and admin hear that company's own part and nothing of the other's
        $this->assertAcmeAndGlobexToldTheirOwnParts();
    }

    protected function anIncidentNamingAcmeAndGlobex(): string
    {
        $this->acme = Company::factory()->named('Acme Holdings')->createOne();
        $this->globex = Company::factory()->named('Globex Traders')->createOne();
        $this->acmeMembers = [$this->aCompanyMember($this->acme, 'owner'), $this->aCompanyMember($this->acme, 'admin')];
        $this->globexMembers = [$this->aCompanyMember($this->globex, 'owner'), $this->aCompanyMember($this->globex, 'admin')];

        return $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->acme->getKey(), 'dataTypes' => ['pii', 'banking'], 'estimatedCount' => 120],
            ['companyId' => $this->globex->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 30],
        ]]);
    }

    protected function assertAcmeAndGlobexToldTheirOwnParts(): void
    {
        $this->assertToldOnceEach($this->acmeMembers, $this->acme, $this->partFor('Personal details, Bank details', '120'));
        $this->assertToldOnceEach($this->globexMembers, $this->globex, $this->partFor('Payroll records', '30'));
        Notification::assertCount(4);
    }

    #[Test]
    #[DataProvider('provideDataTypes')]
    public function when_any_data_type_is_named_then_its_notice_has_words_for_it(string $dataType, string $words): void
    {
        // Given a recorded incident naming company A with one data type, and A's owner
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => [$dataType], 'estimatedCount' => 40],
        ]]);
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the admin presses notify and the notice leaves the queue
        $this->notifyCompanies($this->admin, $breachId);
        $this->runTheNotices();

        // Then the notice names that data type in words
        $this->assertToldOnceEach([$owner], $this->companyA, $this->partFor($words, '40'));
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
            ->mapWithKeys(fn (BreachDataType $type): array => [$type->value => [$type->value, $words[$type->value] ?? 'no words for '.$type->value]])
            ->all();
    }

    #[Test]
    public function when_an_admin_notifies_the_companies_then_they_are_recorded_projected_audited_and_told(): void
    {
        // Given a recorded incident naming Acme Holdings and Globex Traders, each with an owner and an admin
        $breachId = $this->anIncidentNamingAcmeAndGlobex();
        $this->travelTo(self::NOTIFIED_AT);

        // When the admin presses notify and the notices leave the queue
        $response = $this->notifyCompanies($this->admin, $breachId);
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
        $events = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->orderBy('id')->get();
        $this->assertSame(
            [BreachRecorded::class, BreachCompanyNotified::class, BreachCompanyNotified::class, BreachCompaniesNotified::class],
            $events->pluck('event_class')->all(),
        );

        $parts = $events->where('event_class', BreachCompanyNotified::class)
            ->mapWithKeys(fn (LesilyStoredEvent $event): array => [$event->event_properties['companyId'] => [
                $event->event_properties['notifiedByUserId'],
                CarbonImmutable::parse($event->event_properties['notifiedAt'])->toIso8601String(),
                $event->event_properties['part'],
            ]])->all();
        $notifiedAt = CarbonImmutable::parse(self::NOTIFIED_AT)->toIso8601String();
        $this->assertEquals([
            $this->acme->getKey() => [$this->admin->getKey(), $notifiedAt, $this->partFor('Personal details, Bank details', '120')],
            $this->globex->getKey() => [$this->admin->getKey(), $notifiedAt, $this->partFor('Payroll records', '30')],
        ], $parts);

        $closing = $events->last();
        $this->assertSame([$this->admin->getKey(), $notifiedAt], [
            $closing->event_properties['notifiedByUserId'],
            CarbonImmutable::parse($closing->event_properties['notifiedAt'])->toIso8601String(),
        ]);

        return (int) $closing->getKey();
    }

    protected function assertNotifyAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
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
            Notification::assertSentTo($recipient, SystemNotification::class, fn (SystemNotification $notice): bool => $notice->type === NotificationType::BreachCompanyNotice
                && $notice->companyName === $company->name
                && $notice->companyId === $company->getKey()
                && $notice->details === $part);
        }
    }

    protected function aRemovedMember(Company $company, string $roleCode): void
    {
        $member = $this->aCompanyMember($company, $roleCode);

        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $member->getKey())
            ->update(['deleted_at' => now()]);
    }

    /**
     * @param  list<User>  $recipients
     */
    protected function assertToldExactly(array $recipients, Company $company): void
    {
        $this->assertNotEmpty($recipients);
        foreach ($recipients as $recipient) {
            Notification::assertSentToTimes($recipient, SystemNotification::class, 1);
            Notification::assertSentTo($recipient, SystemNotification::class, fn (SystemNotification $notice): bool => $notice->type === NotificationType::BreachCompanyNotice
                && $notice->companyName === $company->name
                && $notice->companyId === $company->getKey());
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

        $incident = BreachIncidentRecord::query()->with('companies')->findOrFail($breachId);
        $this->assertSame(BreachStage::CompaniesNotified, $incident->stage);
        $this->assertNotEmpty($incident->companies);
        foreach ([$incident->companies_notified_at, ...$incident->companies->pluck('notified_at')] as $stamp) {
            $this->assertTrue(CarbonImmutable::parse(self::NOTIFIED_AT)->equalTo($stamp));
        }
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
