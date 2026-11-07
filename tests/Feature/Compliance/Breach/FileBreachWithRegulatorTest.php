<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Database\Factories\BreachIncidentRecordFactory;
use Database\Factories\BreachIncidentCompanyFactory;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Mail\BreachRegulatorNoticeMail;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\RegulatorAddressNotConfiguredException;

class FileBreachWithRegulatorTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string REGULATOR = 'dpa@regulator.test';

    protected const string FILED_AT = '2026-05-06 11:15:00';

    protected const string FILED_AT_JSON = '2026-05-06T11:15:00.000000Z';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        config(['platform.regulator_address' => self::REGULATOR]);
    }

    #[Test]
    public function when_an_unauthenticated_caller_files_then_they_are_refused(): void
    {
        // Given company A asked Lesily to file an incident it was told of
        $owner = $this->anOwnerOf($this->companyA);
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->filingRequested($owner->getKey()));
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When a caller with no token files it for company A
        $response = $this->fileFor(null, $notice->breach_incident_id, $this->companyA->getKey());

        // Then they are refused as unauthenticated and nothing is filed
        $this->assertUnauthenticated($response);
        $this->assertNothingFiled($notice->breach_incident_id, $before);
    }

    #[Test]
    public function when_a_company_owner_files_then_it_is_not_found_and_nothing_is_sent(): void
    {
        // Given company A's owner asked Lesily to file an incident A was told of
        $owner = $this->anOwnerOf($this->companyA);
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->filingRequested($owner->getKey()));
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When the owner files it for company A on the admin surface
        $response = $this->fileFor($owner, $notice->breach_incident_id, $this->companyA->getKey());

        // Then the admin surface is not acknowledged and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($notice->breach_incident_id, $before);
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident carries the id

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then it is not found and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
    }

    #[Test]
    #[DataProvider('provideCompaniesNotOnTheIncident')]
    public function when_the_company_cannot_be_found_on_the_incident_then_it_is_not_found(Closure $companyId): void
    {
        // Given company B asked Lesily to file an incident only B was told of
        $breachId = $this->aNotifiedIncident($this->companyB);
        $this->aFilingRequestBy($this->companyB, $breachId);
        $before = $this->snapshotOf($breachId);

        // When an admin files it for a company that is not on it
        $response = $this->fileFor($this->admin, $breachId, $companyId($this));

        // Then it is not found and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($breachId, $before);
    }

    #[Test]
    public function when_no_regulator_address_is_configured_then_the_filing_is_unavailable_and_nothing_is_recorded(): void
    {
        // Given company A asked Lesily to file an incident it was told of, and no regulator address is configured
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $before = $this->snapshotOf($breachId);
        config(['platform.regulator_address' => '']);
        Exceptions::fake();

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then the filing is unavailable, the gap is reported and nothing is filed
        $this->assertRefusedWith($response, Response::HTTP_SERVICE_UNAVAILABLE, ApiErrorCode::RegulatorFilingUnavailable);
        $response->assertJsonPath('message', 'Filing with the regulator is unavailable: no regulator address is configured.');
        Exceptions::assertReported(RegulatorAddressNotConfiguredException::class);
        $this->assertNothingFiled($breachId, $before);
    }

    #[Test]
    #[DataProvider('provideFilingsNotOpen')]
    public function when_the_filing_is_not_open_then_it_is_refused_with_the_reason(Closure $atStage, Closure $rowState, string $message): void
    {
        // Given company A is on an incident whose filing is not open to the admin
        $owner = $this->anOwnerOf($this->companyA);
        $notice = $this->aFactoryNotice($atStage($this->anIncident()), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $rowState($row, $owner->getKey(), $this->admin->getKey()));
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $notice->breach_incident_id, $this->companyA->getKey());

        // Then the filing is refused with the reason and nothing is filed
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingFiled($notice->breach_incident_id, $before);
    }

    /**
     * @return array<string, array{Closure(BreachIncidentRecordFactory): BreachIncidentRecordFactory, Closure(BreachIncidentCompanyFactory, string, string): BreachIncidentCompanyFactory, string}>
     */
    public static function provideFilingsNotOpen(): array
    {
        return [
            'the company has not asked' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->companiesNotified(),
                fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified(),
                'The company has not asked Lesily to file this breach.',
            ],
            'already filed for' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->companiesNotified(),
                fn (BreachIncidentCompanyFactory $row, string $ownerId, string $adminId): BreachIncidentCompanyFactory => $row->filed($ownerId, $adminId),
                'Lesily has already filed this breach with the regulator for the company.',
            ],
            'the companies are not yet notified' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident,
                fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row,
                'A breach is filed with the regulator only after its companies are notified.',
            ],
            'resolved' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->resolved(),
                fn (BreachIncidentCompanyFactory $row, string $ownerId): BreachIncidentCompanyFactory => $row->filingRequested($ownerId),
                'A resolved breach incident is not filed with the regulator.',
            ],
            'a false alarm' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm(),
                fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row,
                'A false alarm is not filed with the regulator.',
            ],
        ];
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_nothing_is_filed_and_nothing_is_sent(): void
    {
        // Given company A asked Lesily to file an incident it was told of, and the audit trail cannot be written
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $before = $this->snapshotOf($breachId);
        AuditEntry::creating(fn () => throw new RuntimeException('audit store down'));

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then the request fails closed and the filing is rolled back
        $this->assertFailedClosed($response);
        $this->assertNothingFiledOrAudited($breachId, $before);
    }

    #[Test]
    public function when_another_company_on_the_incident_asked_then_only_the_filed_company_is_stamped(): void
    {
        // Given companies A and B both asked Lesily to file one incident
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $this->aFilingRequestBy($this->companyB, $breachId);

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then only A's row is filed and B's request still waits
        $response->assertOk();
        $this->assertNotNull($this->filingOf($breachId, $this->companyA)->filed_at);
        $this->assertSame([null, false], [$this->filingOf($breachId, $this->companyB)->filed_at, $this->filingOf($breachId, $this->companyB)->filing_requested_at === null]);
        $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
    }

    #[Test]
    public function when_the_company_is_on_two_incidents_then_only_the_filed_incident_is_stamped(): void
    {
        // Given company A asked Lesily to file two incidents
        $first = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $first);
        $second = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $second);

        // When an admin files the second for company A
        $response = $this->fileFor($this->admin, $second, $this->companyA->getKey());

        // Then only the second incident's row is filed
        $response->assertOk();
        $response->assertJsonPath('data.id', $second);
        $this->assertNotNull($this->filingOf($second, $this->companyA)->filed_at);
        $this->assertNull($this->filingOf($first, $this->companyA)->filed_at);
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $first)->where('event_class', BreachFiledWithRegulator::class)->count());
    }

    #[Test]
    public function when_two_incidents_are_filed_then_each_audit_row_cites_its_own_event(): void
    {
        // Given company A asked Lesily to file two incidents, and the first is filed
        $first = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $first);
        $second = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $second);
        $this->fileFor($this->admin, $first, $this->companyA->getKey())->assertOk();

        // When an admin files the second for company A
        $response = $this->fileFor($this->admin, $second, $this->companyA->getKey());

        // Then each incident's audit row cites that incident's filing
        $response->assertOk();
        $this->assertAuditCitesTheFilingOf($first, $this->companyA);
        $this->assertAuditCitesTheFilingOf($second, $this->companyA);
    }

    #[Test]
    public function when_both_companies_on_an_incident_are_filed_for_then_each_audit_row_cites_its_own_filing(): void
    {
        // Given companies A and B both asked Lesily to file one incident, and A is filed
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $this->aFilingRequestBy($this->companyB, $breachId);
        $this->fileFor($this->admin, $breachId, $this->companyA->getKey())->assertOk();

        // When an admin files it for company B
        $response = $this->fileFor($this->admin, $breachId, $this->companyB->getKey());

        // Then each company's filing has its own audit row
        $response->assertOk();
        $this->assertAuditCitesTheFilingOf($breachId, $this->companyA);
        $this->assertAuditCitesTheFilingOf($breachId, $this->companyB);
    }

    #[Test]
    public function when_the_filing_is_recorded_then_nothing_is_sent_until_the_queued_reactors_run(): void
    {
        // Given company A asked Lesily to file an incident it was told of
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $breachId);

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then nothing is sent yet: the mail and the notice wait for the queued reactors
        $response->assertOk();
        $this->assertReactorDeferred(BreachFiledWithRegulator::class, SystemNotification::class);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function when_the_regulator_mail_is_built_then_it_carries_only_the_filed_companys_part(): void
    {
        // Given company A (personal details, NIC numbers; 120 people) and company B (bank details; 30 people)
        // were told of one incident, and only A's owner asked Lesily to file
        $breachId = $this->aNotifiedIncidentOf([
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii', 'nic'], 'estimatedCount' => 120],
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['banking'], 'estimatedCount' => 30],
        ]);
        $this->aFilingRequestBy($this->companyA, $breachId);

        // When an admin files it for company A and the queued reactors run
        $this->fileFor($this->admin, $breachId, $this->companyA->getKey())->assertOk();
        $this->runTheFilingReactors();

        // Then the regulator reads A's name, its own data types and its count — and nothing of B
        $body = $this->theRegulatorMail()->render();
        $this->assertStringContainsString(e($this->companyA->name), $body);
        $this->assertStringContainsString('Personal data affected: Personal details, National identity card numbers. People affected: 120.', $body);
        foreach ([e($this->companyB->name), 'Bank details', 'People affected: 30', '150'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    #[Test]
    public function when_a_fact_was_corrected_after_the_notice_then_the_filing_carries_the_current_figure(): void
    {
        // Given company A was told 40 people were affected, the figure since corrected to 145, and A asked Lesily to file
        $breachId = $this->aNotifiedIncidentOf([['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40]]);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $this->correctTheCount($breachId, $this->companyA, 145);

        // When an admin files it for company A and the queued reactors run
        $this->fileFor($this->admin, $breachId, $this->companyA->getKey())->assertOk();
        $this->runTheFilingReactors();

        // Then the regulator reads 145, while the notice A was sent still records 40
        $this->assertStringContainsString('People affected: 145.', $this->theRegulatorMail()->render());
        $this->assertSame(['145', '40'], [$this->partOf(BreachFiledWithRegulator::class, $breachId)['count'], $this->partOf(BreachCompanyNotified::class, $breachId)['count']]);
    }

    #[Test]
    public function when_the_company_count_is_not_known_then_the_regulator_mail_says_so(): void
    {
        // Given company A was told of an incident with no count yet, and asked Lesily to file
        $breachId = $this->aNotifiedIncidentOf([['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => null]]);
        $this->aFilingRequestBy($this->companyA, $breachId);

        // When an admin files it for company A and the queued reactors run
        $this->fileFor($this->admin, $breachId, $this->companyA->getKey())->assertOk();
        $this->runTheFilingReactors();

        // Then the regulator reads that the count is not yet known
        $this->assertStringContainsString('People affected: Not yet known.', $this->theRegulatorMail()->render());
    }

    /**
     * @return array<string, array{Closure(self): string}>
     */
    public static function provideCompaniesNotOnTheIncident(): array
    {
        return [
            'a company not on it' => [fn (self $test): string => $test->companyA->getKey()],
            'an unknown company' => [fn (self $test): string => '019a5b2e-7c1d-7f00-8a00-0000000000c0'],
            'not a company id' => [fn (self $test): string => 'not-a-company'],
        ];
    }

    #[Test]
    public function when_an_admin_files_for_a_company_that_asked_then_it_is_recorded_projected_and_audited(): void
    {
        // Given an incident told to companies A and B, where only A's owner asked Lesily to file
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $this->travelTo(CarbonImmutable::parse(self::FILED_AT));

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then A's filing is recorded, stamped on A's row and audited, and B is untouched
        $this->assertFiledFor($response, $this->companyA, $this->admin);
        $filing = $this->assertFilingStored($breachId, $this->companyA, $this->admin);
        $this->assertFilingAudited($response, $breachId, (int) $filing->getKey());
        $this->assertSame([null, null], [$this->filingOf($breachId, $this->companyB)->filed_at, $this->filingOf($breachId, $this->companyB)->filed_body_hash]);
    }

    #[Test]
    public function when_the_filing_is_recorded_then_the_regulator_is_mailed_the_body_its_hash_was_taken_from(): void
    {
        // Given an incident told to companies A and B, where only A's owner asked Lesily to file
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->aFilingRequestBy($this->companyA, $breachId);

        // When an admin files it for company A and the queued reactors run
        $this->fileFor($this->admin, $breachId, $this->companyA->getKey())->assertOk();
        $this->runTheFilingReactors();

        // Then one mail goes to the configured regulator address, its body the one the stored hash was taken from
        $this->assertRegulatorMailed((string) $this->filingOf($breachId, $this->companyA)->filed_body_hash);
    }

    protected function fileFor(?User $caller, string $breachId, string $companyId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/companies/{$companyId}/filing");
    }

    /**
     * @param  list<array{companyId: string, dataTypes: list<string>, estimatedCount: ?int}>  $companies
     */
    protected function aNotifiedIncidentOf(array $companies): string
    {
        $breachId = $this->aRecordedIncident(['companies' => $companies]);
        $this->notifyCompanies($this->admin, $breachId)->assertOk();

        return $breachId;
    }

    protected function correctTheCount(string $breachId, Company $company, int $count): void
    {
        BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->whereCompany($company)->update(['estimated_count' => $count]);
    }

    /**
     * @return array<string, string>
     */
    protected function partOf(string $eventClass, string $breachId): array
    {
        return LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', $eventClass)->sole()->event_properties['part'];
    }

    protected function runTheFilingReactors(): void
    {
        $this->runQueuedHandlersFor(BreachFiledWithRegulator::class);
    }

    protected function theRegulatorMail(): BreachRegulatorNoticeMail
    {
        Mail::assertQueuedCount(1);

        return Mail::queued(BreachRegulatorNoticeMail::class)->sole();
    }

    protected function assertRegulatorMailed(string $bodyHash): void
    {
        $mail = $this->theRegulatorMail();

        $this->assertTrue($mail->hasTo(self::REGULATOR));
        $this->assertSame($bodyHash, hash('sha256', $mail->render()));
    }

    protected function filingOf(string $breachId, Company $company): BreachIncidentCompany
    {
        return BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->whereCompany($company)->sole();
    }

    protected function assertFiledFor(TestResponse $response, Company $company, User $admin): void
    {
        $response->assertOk();
        $block = collect($response->json('data.companies'))->firstWhere('company_id', $company->getKey());
        $this->assertSame(['companies_notified', self::FILED_AT_JSON], [$response->json('data.stage'), $block['filed_at'] ?? null]);

        $filing = $this->filingOf($response->json('data.id'), $company);
        $this->assertNotNull($filing->filed_at);
        $this->assertTrue(CarbonImmutable::parse(self::FILED_AT)->equalTo($filing->filed_at));
        $this->assertSame($admin->getKey(), $filing->filed_by);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $filing->filed_body_hash);
    }

    protected function assertFilingStored(string $breachId, Company $company, User $admin): LesilyStoredEvent
    {
        $event = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachFiledWithRegulator::class)->sole();
        $properties = $event->event_properties;

        $this->assertSame(
            [$breachId, $company->getKey(), $company->name, $admin->getKey(), self::REGULATOR, $this->filingOf($breachId, $company)->filed_body_hash],
            [$properties['breachId'], $properties['companyId'], $properties['companyName'], $properties['filedByUserId'], $properties['regulatorAddress'], $properties['bodyHash']],
        );
        $this->assertTrue(CarbonImmutable::parse(self::FILED_AT)->equalTo(CarbonImmutable::parse($properties['filedAt'])));

        return $event;
    }

    protected function assertFilingAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachFiledWithRegulator',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_filed_with_regulator',
            'description' => 'Platform admin filed a data breach with the Data Protection Authority for a company that asked.',
            'metadata' => null,
            'recorded_at' => self::FILED_AT,
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

    protected function assertAuditCitesTheFilingOf(string $breachId, Company $company): void
    {
        $filing = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', BreachFiledWithRegulator::class)
            ->where('event_properties->companyId', $company->getKey())
            ->sole();

        $this->assertDatabaseHas('audit_trail', ['stored_event_id' => $filing->getKey(), 'subject_id' => $breachId, 'action' => 'breach_filed_with_regulator']);
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingFiledOrAudited(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
        $this->assertNoAuditRecorded();
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingFiled(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
        $this->assertNoAuditRecorded();
        $this->assertNoHandlersQueuedFor(BreachFiledWithRegulator::class);
    }
}
