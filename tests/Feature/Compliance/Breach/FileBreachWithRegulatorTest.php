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
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Database\Factories\BreachIncidentRecordFactory;
use Database\Factories\BreachIncidentCompanyFactory;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Foundation\Notifications\Enums\NotificationType;
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

    protected const string FILED_AT_WORDS = '6 May 2026, 11:15 UTC';

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
        $breachIncidentCompany = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory->filingRequested($this->owner->getKey()));
        $before = $this->snapshotOf($breachIncidentCompany->breach_incident_id);

        // When a caller with no token files it for company A
        $response = $this->fileFor(null, $breachIncidentCompany->breach_incident_id, $this->contosoCompany->getKey());

        // Then they are refused as unauthenticated and nothing is filed
        $this->assertUnauthenticated($response);
        $this->assertNothingFiled($breachIncidentCompany->breach_incident_id, $before);
    }

    #[Test]
    public function when_a_company_owner_files_then_it_is_not_found_and_nothing_is_sent(): void
    {
        // Given company A's owner asked Lesily to file an incident A was told of
        $breachIncidentCompany = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory->filingRequested($this->owner->getKey()));
        $before = $this->snapshotOf($breachIncidentCompany->breach_incident_id);

        // When the owner files it for company A on the admin surface
        $response = $this->fileFor($this->owner, $breachIncidentCompany->breach_incident_id, $this->contosoCompany->getKey());

        // Then the admin surface is not acknowledged and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($breachIncidentCompany->breach_incident_id, $before);
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident carries the id

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // Then it is not found and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
    }

    #[Test]
    #[DataProvider('provideCompaniesNotOnTheIncident')]
    public function when_the_company_cannot_be_found_on_the_incident_then_it_is_not_found(Closure $companyId): void
    {
        // Given company B asked Lesily to file an incident only B was told of
        $breachId = $this->aNotifiedIncident($this->otherCompany);
        $this->aFilingRequestBy($this->otherCompany, $breachId);
        $before = $this->snapshotOf($breachId);

        // When a platform admin files it for a company that is not on it
        $response = $this->fileFor($this->platformAdmin, $breachId, $companyId($this));

        // Then it is not found and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($breachId, $before);
    }

    /**
     * @return array<string, array{Closure(self): string}>
     */
    public static function provideCompaniesNotOnTheIncident(): array
    {
        return [
            'a company not on it' => [fn (self $fileBreachWithRegulatorTest): string => $fileBreachWithRegulatorTest->contosoCompany->getKey()],
            'an unknown company' => [fn (self $fileBreachWithRegulatorTest): string => '019a5b2e-7c1d-7f00-8a00-0000000000c0'],
            'not a company id' => [fn (self $fileBreachWithRegulatorTest): string => 'not-a-company'],
        ];
    }

    #[Test]
    public function when_no_regulator_address_is_configured_then_the_filing_is_unavailable_and_nothing_is_recorded(): void
    {
        // Given company A asked Lesily to file an incident it was told of, and no regulator address is configured
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $before = $this->snapshotOf($breachId);
        config(['platform.regulator_address' => '']);
        Exceptions::fake();

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

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
        // Given company A is on an incident whose filing is not open to the platform admin
        $breachIncidentCompany = $this->aFactoryNotice($atStage($this->anIncident()), fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory): BreachIncidentCompanyFactory => $rowState($breachIncidentCompanyFactory, $this->owner->getKey(), $this->platformAdmin->getKey()));
        $before = $this->snapshotOf($breachIncidentCompany->breach_incident_id);

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachIncidentCompany->breach_incident_id, $this->contosoCompany->getKey());

        // Then the filing is refused with the reason and nothing is filed
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingFiled($breachIncidentCompany->breach_incident_id, $before);
    }

    /**
     * @return array<string, array{Closure(BreachIncidentRecordFactory): BreachIncidentRecordFactory, Closure(BreachIncidentCompanyFactory, string, string): BreachIncidentCompanyFactory, string}>
     */
    public static function provideFilingsNotOpen(): array
    {
        return [
            'the company has not asked' => [
                fn (BreachIncidentRecordFactory $breachIncidentRecordFactory): BreachIncidentRecordFactory => $breachIncidentRecordFactory->companiesNotified(),
                fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory->notified(),
                'The company has not asked Lesily to file this breach.',
            ],
            'already filed for' => [
                fn (BreachIncidentRecordFactory $breachIncidentRecordFactory): BreachIncidentRecordFactory => $breachIncidentRecordFactory->companiesNotified(),
                fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory, string $ownerId, string $platformAdminId): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory->filed($ownerId, $platformAdminId),
                'Lesily has already filed this breach with the regulator for the company.',
            ],
            'the companies are not yet notified' => [
                fn (BreachIncidentRecordFactory $breachIncidentRecordFactory): BreachIncidentRecordFactory => $breachIncidentRecordFactory,
                fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory,
                'A breach is filed with the regulator only after its companies are notified.',
            ],
            'resolved' => [
                fn (BreachIncidentRecordFactory $breachIncidentRecordFactory): BreachIncidentRecordFactory => $breachIncidentRecordFactory->resolved(),
                fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory, string $ownerId): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory->filingRequested($ownerId),
                'A resolved breach incident is not filed with the regulator.',
            ],
            'a false alarm' => [
                fn (BreachIncidentRecordFactory $breachIncidentRecordFactory): BreachIncidentRecordFactory => $breachIncidentRecordFactory->falseAlarm(),
                fn (BreachIncidentCompanyFactory $breachIncidentCompanyFactory): BreachIncidentCompanyFactory => $breachIncidentCompanyFactory,
                'A false alarm is not filed with the regulator.',
            ],
        ];
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_nothing_is_filed_and_nothing_is_sent(): void
    {
        // Given company A asked Lesily to file an incident it was told of, and the audit trail cannot be written
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $before = $this->snapshotOf($breachId);
        AuditEntry::creating(fn () => throw new RuntimeException('audit store down'));

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // Then the request fails closed and the filing is rolled back
        $this->assertFailedClosed($response);
        $this->assertNothingFiledOrAudited($breachId, $before);
    }

    #[Test]
    public function when_another_company_on_the_incident_asked_then_only_the_filed_company_is_stamped(): void
    {
        // Given companies A and B both asked Lesily to file one incident
        $breachId = $this->aNotifiedIncident($this->contosoCompany, $this->otherCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $this->aFilingRequestBy($this->otherCompany, $breachId);

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // Then only A's row is filed and B's request still waits
        $response->assertOk();
        $this->assertNotNull($this->filingOf($breachId, $this->contosoCompany)->filed_at);
        $otherBreachIncidentCompany = $this->filingOf($breachId, $this->otherCompany);
        $this->assertNull($otherBreachIncidentCompany->filed_at);
        $this->assertNotNull($otherBreachIncidentCompany->filing_requested_at);
        $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
    }

    #[Test]
    public function when_the_company_is_on_two_incidents_then_only_the_filed_incident_is_stamped(): void
    {
        // Given company A asked Lesily to file two incidents
        $first = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $first);
        $second = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $second);

        // When a platform admin files the second for company A
        $response = $this->fileFor($this->platformAdmin, $second, $this->contosoCompany->getKey());

        // Then only the second incident's row is filed
        $response->assertOk();
        $response->assertJsonPath('data.id', $second);
        $this->assertNotNull($this->filingOf($second, $this->contosoCompany)->filed_at);
        $this->assertNull($this->filingOf($first, $this->contosoCompany)->filed_at);
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $first)->where('event_class', BreachFiledWithRegulator::class)->count());
    }

    #[Test]
    public function when_two_incidents_are_filed_then_each_audit_row_cites_its_own_event(): void
    {
        // Given company A asked Lesily to file two incidents, and the first is filed
        $first = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $first);
        $second = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $second);
        $this->fileFor($this->platformAdmin, $first, $this->contosoCompany->getKey());

        // When a platform admin files the second for company A
        $response = $this->fileFor($this->platformAdmin, $second, $this->contosoCompany->getKey());

        // Then each incident's audit row cites that incident's filing
        $response->assertOk();
        $this->assertAuditCitesTheFilingOf($first, $this->contosoCompany);
        $this->assertAuditCitesTheFilingOf($second, $this->contosoCompany);
    }

    #[Test]
    public function when_both_companies_on_an_incident_are_filed_for_then_each_audit_row_cites_its_own_filing(): void
    {
        // Given companies A and B both asked Lesily to file one incident, and A is filed
        $breachId = $this->aNotifiedIncident($this->contosoCompany, $this->otherCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $this->aFilingRequestBy($this->otherCompany, $breachId);
        $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // When a platform admin files it for company B
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->otherCompany->getKey());

        // Then each company's filing has its own audit row
        $response->assertOk();
        $this->assertAuditCitesTheFilingOf($breachId, $this->contosoCompany);
        $this->assertAuditCitesTheFilingOf($breachId, $this->otherCompany);
    }

    #[Test]
    public function when_the_filing_is_recorded_then_nothing_is_sent_until_the_queued_reactors_run(): void
    {
        // Given company A asked Lesily to file an incident it was told of
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

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
            ['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => ['pii', 'nic'], 'estimatedCount' => 120],
            ['companyId' => $this->otherCompany->getKey(), 'dataTypes' => ['banking'], 'estimatedCount' => 30],
        ]);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);

        // When a platform admin files it for company A and the queued reactors run
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        $this->runTheFilingReactors();

        // Then the regulator reads A's name, its own data types and its count — and nothing of B
        $response->assertOk();
        $notice = $this->theRegulatorMail()->noticeText();
        $this->assertStringContainsString($this->contosoCompany->name, $notice);
        $this->assertStringContainsString('Personal data affected: Personal details, National identity card numbers. People affected: 120.', $notice);
        foreach ([$this->otherCompany->name, 'Bank details', 'People affected: 30', '150'] as $absent) {
            $this->assertStringNotContainsString($absent, $notice);
        }
    }

    #[Test]
    public function when_a_fact_was_corrected_after_the_notice_then_the_filing_carries_the_current_figure(): void
    {
        // Given company A was told 40 people were affected, the figure since corrected to 145, and A asked Lesily to file
        $breachId = $this->aNotifiedIncidentOf([['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40]]);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $this->correctTheCount($breachId, $this->contosoCompany, 145);

        // When a platform admin files it for company A and the queued reactors run
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        $this->runTheFilingReactors();

        // Then the regulator reads 145, while the notice A was sent still records 40
        $response->assertOk();
        $this->assertStringContainsString('People affected: 145.', $this->theRegulatorMail()->noticeText());
        $this->assertSame(['145', '40'], [$this->partOf(BreachFiledWithRegulator::class, $breachId)['count'], $this->partOf(BreachCompanyNotified::class, $breachId)['count']]);
    }

    #[Test]
    public function when_the_company_count_is_not_known_then_the_regulator_mail_says_so(): void
    {
        // Given company A was told of an incident with no count yet, and asked Lesily to file
        $breachId = $this->aNotifiedIncidentOf([['companyId' => $this->contosoCompany->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => null]]);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);

        // When a platform admin files it for company A and the queued reactors run
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        $this->runTheFilingReactors();

        // Then the regulator reads that the count is not yet known
        $response->assertOk();
        $this->assertStringContainsString('People affected: Not yet known.', $this->theRegulatorMail()->noticeText());
    }

    #[Test]
    public function when_the_mail_layout_changes_before_it_is_sent_then_its_notice_still_hashes_to_the_filing(): void
    {
        // Given company A's filing was recorded, and the app's name in the mail layout changes before the queue runs
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        config(['app.name' => 'Lesily (renamed)']);

        // When the queued reactors run
        $this->runTheFilingReactors();

        // Then the notice the regulator receives still hashes to the stored value
        $response->assertOk();
        $this->assertRegulatorMailed((string) $this->filingOf($breachId, $this->contosoCompany)->filed_body_hash);
    }

    #[Test]
    public function when_the_regulator_address_changes_before_the_mail_is_sent_then_it_goes_to_the_address_filed(): void
    {
        // Given company A's filing was recorded, and the configured regulator address changes before the queue runs
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        config(['platform.regulator_address' => 'new-dpa@regulator.test']);

        // When the queued reactors run
        $this->runTheFilingReactors();

        // Then the mail goes to the address the filing named
        $response->assertOk();
        $this->assertRegulatorMailed((string) $this->filingOf($breachId, $this->contosoCompany)->filed_body_hash);
    }

    #[Test]
    public function when_an_admin_files_for_a_company_twice_then_the_second_is_refused_and_one_mail_is_queued(): void
    {
        // Given company A's filing was recorded
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // When a platform admin files it for company A again
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // Then the second filing is refused, and one filing and one send wait in the queue
        $this->assertRefusedAsNotOpen($response, 'Lesily has already filed this breach with the regulator for the company.');
        $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
        $this->assertSame(1, $this->queuedHandlerCountFor(BreachFiledWithRegulator::class));
    }

    #[Test]
    public function when_the_company_has_other_members_then_only_the_owner_and_admins_are_told_it_was_filed(): void
    {
        // Given company A asked Lesily to file, and A has an admin, an HR officer and an employee besides its owner
        $breachId = $this->aNotifiedIncident($this->contosoCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $others = [$this->aCompanyMember($this->company, 'hr_officer'), $this->member];
        $this->travelTo(CarbonImmutable::parse(self::FILED_AT));

        // When a platform admin files it for company A and the queued reactors run
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        $this->runTheFilingReactors();

        // Then only the owner and the admin are told
        $response->assertOk();
        $this->assertToldFiled($this->contosoCompany, $breachId);
        foreach ($others as $member) {
            Notification::assertNothingSentTo($member);
        }
    }

    #[Test]
    public function when_an_admin_files_for_a_company_that_asked_then_it_is_recorded_projected_and_audited(): void
    {
        // Given an incident told to companies A and B, where only A's owner asked Lesily to file
        $breachId = $this->aNotifiedIncident($this->contosoCompany, $this->otherCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $this->travelTo(CarbonImmutable::parse(self::FILED_AT));

        // When a platform admin files it for company A
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());

        // Then A's filing is recorded, stamped on A's row and audited, and B is untouched
        $this->assertFiledFor($response, $this->contosoCompany, $this->platformAdmin);
        $storedEventId = $this->assertFilingStored($breachId, $this->contosoCompany, $this->platformAdmin);
        $this->assertFilingAudited($response, $breachId, $storedEventId);
        $this->assertSame([null, null], [$this->filingOf($breachId, $this->otherCompany)->filed_at, $this->filingOf($breachId, $this->otherCompany)->filed_body_hash]);
    }

    #[Test]
    public function when_the_filing_is_recorded_then_the_regulator_is_mailed_the_body_its_hash_was_taken_from(): void
    {
        // Given an incident told to companies A and B, where only A's owner asked Lesily to file
        $breachId = $this->aNotifiedIncident($this->contosoCompany, $this->otherCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);

        // When a platform admin files it for company A and the queued reactors run
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        $this->runTheFilingReactors();

        // Then one mail goes to the configured regulator address, its body the one the stored hash was taken from
        $response->assertOk();
        $this->assertRegulatorMailed((string) $this->filingOf($breachId, $this->contosoCompany)->filed_body_hash);
    }

    #[Test]
    public function when_the_filing_is_recorded_then_the_owner_and_admins_are_told_and_the_other_company_hears_nothing(): void
    {
        // Given an incident told to companies A and B, each with an owner and an admin, where only A asked Lesily to file
        $breachId = $this->aNotifiedIncident($this->contosoCompany, $this->otherCompany);
        $this->aFilingRequestBy($this->contosoCompany, $breachId);
        $others = [$this->aCompanyMember($this->otherCompany, 'owner'), $this->aCompanyMember($this->otherCompany, 'admin')];
        $this->travelTo(CarbonImmutable::parse(self::FILED_AT));

        // When a platform admin files it for company A and the queued reactors run
        $response = $this->fileFor($this->platformAdmin, $breachId, $this->contosoCompany->getKey());
        $this->runTheFilingReactors();

        // Then A's owner and admin are told Lesily filed for them, and B's people hear nothing
        $response->assertOk();
        $this->assertToldFiled($this->contosoCompany, $breachId);
        foreach ($others as $member) {
            Notification::assertNothingSentTo($member);
        }
    }

    protected function fileFor(?User $actor, string $breachId, string $companyId): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/companies/{$companyId}/filing");
    }

    /**
     * @param  list<array{companyId: string, dataTypes: list<string>, estimatedCount: ?int}>  $companies
     */
    protected function aNotifiedIncidentOf(array $companies): string
    {
        $breachId = $this->aRecordedIncident(['companies' => $companies]);
        $this->notifyCompanies($this->platformAdmin, $breachId);

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

    protected function assertToldFiled(Company $company, string $breachId): void
    {
        $recipients = $company->ownerAndAdmins();
        $this->assertCount(2, $recipients);

        foreach ($recipients as $recipient) {
            Notification::assertSentToTimes($recipient, SystemNotification::class, 1);
            Notification::assertSentTo($recipient, SystemNotification::class, fn (SystemNotification $systemNotification): bool => $systemNotification->type === NotificationType::BreachFiledWithRegulator
                && $systemNotification->companyName === $company->name
                && $systemNotification->companyId === $company->getKey()
                && $systemNotification->details === ['incident' => $breachId, 'filed_at' => self::FILED_AT_WORDS]);
        }
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
        $breachRegulatorNoticeMail = $this->theRegulatorMail();

        $this->assertTrue($breachRegulatorNoticeMail->hasTo(self::REGULATOR));
        $this->assertSame($bodyHash, hash('sha256', $breachRegulatorNoticeMail->noticeText()));
        $this->assertStringContainsString($breachRegulatorNoticeMail->noticeText(), html_entity_decode(strip_tags($breachRegulatorNoticeMail->render()), ENT_QUOTES));
    }

    protected function filingOf(string $breachId, Company $company): BreachIncidentCompany
    {
        return BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->whereCompany($company)->sole();
    }

    protected function assertFiledFor(TestResponse $response, Company $company, User $platformAdmin): void
    {
        $response->assertOk();
        $companies = $response->json('data.companies');
        $this->assertIsArray($companies);
        $block = collect($companies)->firstWhere('company_id', $company->getKey());
        $this->assertSame(['companies_notified', self::FILED_AT_JSON], [$response->json('data.stage'), $block['filed_at'] ?? null]);

        $breachId = $response->json('data.id');
        $this->assertIsString($breachId);
        $breachIncidentCompany = $this->filingOf($breachId, $company);
        $this->assertNotNull($breachIncidentCompany->filed_at);
        $this->assertTrue(CarbonImmutable::parse(self::FILED_AT)->equalTo($breachIncidentCompany->filed_at));
        $this->assertSame($platformAdmin->getKey(), $breachIncidentCompany->filed_by);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $breachIncidentCompany->filed_body_hash);
    }

    protected function assertFilingStored(string $breachId, Company $company, User $platformAdmin): int
    {
        $eloquentStoredEvent = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachFiledWithRegulator::class)->sole();
        $properties = $eloquentStoredEvent->event_properties;

        $this->assertSame(
            [$breachId, $company->getKey(), $company->name, $platformAdmin->getKey(), self::REGULATOR, $this->filingOf($breachId, $company)->filed_body_hash],
            [$properties['breachId'], $properties['companyId'], $properties['companyName'], $properties['filedByUserId'], $properties['regulatorAddress'], $properties['bodyHash']],
        );
        $this->assertTrue(CarbonImmutable::parse(self::FILED_AT)->equalTo(CarbonImmutable::parse($properties['filedAt'])));

        return (int) $eloquentStoredEvent->getKey();
    }

    protected function assertFilingAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
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

    protected function assertAuditCitesTheFilingOf(string $breachId, Company $company): void
    {
        $eloquentStoredEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', BreachFiledWithRegulator::class)
            ->where('event_properties->companyId', $company->getKey())
            ->sole();

        $this->assertDatabaseHas('audit_trail', ['stored_event_id' => $eloquentStoredEvent->getKey(), 'subject_id' => $breachId, 'action' => 'breach_filed_with_regulator']);
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingFiledOrAudited(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingFiled(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFiledWithRegulator::class)->count());
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
        $this->assertNoHandlersQueuedFor(BreachFiledWithRegulator::class);
    }
}
