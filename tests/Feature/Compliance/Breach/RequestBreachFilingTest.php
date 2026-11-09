<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use Database\Factories\BreachIncidentCompanyFactory;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;

class RequestBreachFilingTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string REQUESTED_AT = '2026-05-05 09:30:00';

    protected const string REQUESTED_AT_JSON = '2026-05-05T09:30:00.000000Z';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function when_a_guest_asks_lesily_to_file_then_they_are_unauthenticated(): void
    {
        // Given company A was notified of a breach
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When a caller with no token asks Lesily to file for company A
        $response = $this->requestFiling(null, $this->companyA, $notice->breach_incident_id);

        // Then they are refused as unauthenticated and nothing is requested
        $this->assertUnauthenticated($response);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheRequestPermission')]
    public function when_a_member_without_the_request_permission_asks_then_the_role_is_insufficient_and_nothing_is_recorded(string $roleCode): void
    {
        // Given company A was notified of a breach, and a member of A whose role cannot ask Lesily to file
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member asks Lesily to file for company A
        $response = $this->requestFiling($member, $this->companyA, $notice->breach_incident_id);

        // Then the role is refused and nothing is requested
        $this->assertRoleInsufficient($response, 'breach.request-filing');
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    public function when_the_owner_of_another_company_asks_then_company_access_is_denied(): void
    {
        // Given company A was notified of a breach, and the owner of company B
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);
        $outsider = $this->anOwnerOf($this->companyB);

        // When B's owner asks Lesily to file for company A
        $response = $this->requestFiling($outsider, $this->companyA, $notice->breach_incident_id);

        // Then access to company A is denied and nothing is requested
        $this->assertCompanyAccessDenied($response);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    public function when_the_owner_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given company A was notified of a breach, and A's owner accepted the policies before a new privacy policy version
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);
        $owner = $this->anOwnerOf($this->companyA);
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner asks Lesily to file for company A
        $response = $this->requestFiling($owner, $this->companyA, $notice->breach_incident_id);

        // Then the request is refused until they accept, and nothing is requested
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident under that id, and company A's owner
        $owner = $this->anOwnerOf($this->companyA);

        // When the owner asks Lesily to file it for company A
        $response = $this->requestFiling($owner, $this->companyA, $breachId);

        // Then it is not found and nothing is requested
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFilingRequested::class)->count());
        $this->assertNoHandlersQueuedFor(BreachFilingRequested::class);
    }

    #[Test]
    public function when_the_company_is_not_on_the_incident_then_it_is_not_found(): void
    {
        // Given an incident that notified company B only, and company A's owner
        $breachId = $this->aNotifiedIncident($this->companyB);
        $before = $this->snapshotOf($breachId);
        $owner = $this->anOwnerOf($this->companyA);

        // When A's owner asks Lesily to file that incident for company A
        $response = $this->requestFiling($owner, $this->companyA, $breachId);

        // Then it is not found and nothing is requested
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRequested($breachId, $before);
    }

    #[Test]
    #[DataProvider('provideStagesNeverTold')]
    public function when_the_company_has_not_been_told_of_the_incident_then_it_is_not_found(Closure $atStage): void
    {
        // Given an incident naming company A that never reached A, and A's owner
        $notice = $this->aFactoryNotice($atStage($this->anIncident()), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row);
        $before = $this->snapshotOf($notice->breach_incident_id);
        $owner = $this->anOwnerOf($this->companyA);

        // When the owner asks Lesily to file it for company A
        $response = $this->requestFiling($owner, $this->companyA, $notice->breach_incident_id);

        // Then it is not found and nothing is requested
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    /**
     * @return array<string, array{Closure(BreachIncidentRecordFactory): BreachIncidentRecordFactory}>
     */
    public static function provideStagesNeverTold(): array
    {
        return [
            'recorded' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident],
            'a false alarm' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm()],
        ];
    }

    #[Test]
    #[DataProvider('provideClosedFilings')]
    public function when_the_filing_is_no_longer_open_then_the_request_is_refused_with_the_reason(Closure $atStage, Closure $rowState, string $message): void
    {
        // Given company A was told of an incident whose filing is no longer open to a request, and A's owner
        $owner = $this->anOwnerOf($this->companyA);
        $notice = $this->aFactoryNotice($atStage($this->anIncident()), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $rowState($row, $owner->getKey(), $this->admin->getKey()));
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When the owner asks Lesily to file it for company A
        $response = $this->requestFiling($owner, $this->companyA, $notice->breach_incident_id);

        // Then the request is refused with the reason and nothing is requested
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    /**
     * @return array<string, array{Closure(BreachIncidentRecordFactory): BreachIncidentRecordFactory, Closure(BreachIncidentCompanyFactory, string, string): BreachIncidentCompanyFactory, string}>
     */
    public static function provideClosedFilings(): array
    {
        return [
            'already requested' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->companiesNotified(),
                fn (BreachIncidentCompanyFactory $row, string $ownerId): BreachIncidentCompanyFactory => $row->filingRequested($ownerId),
                'Lesily has already been asked to file this breach for the company.',
            ],
            'already filed for' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->companiesNotified(),
                fn (BreachIncidentCompanyFactory $row, string $ownerId, string $adminId): BreachIncidentCompanyFactory => $row->filed($ownerId, $adminId),
                'Lesily has already filed this breach with the regulator for the company.',
            ],
            'resolved' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->resolved(),
                fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified(),
                'A resolved breach incident takes no filing request.',
            ],
        ];
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_nothing_is_recorded_and_nobody_is_alerted(): void
    {
        // Given company A was notified of a breach, A's owner, and an audit trail that refuses every write
        $breachId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($breachId);
        $owner = $this->anOwnerOf($this->companyA);
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the owner asks Lesily to file it for company A
        $response = $this->requestFiling($owner, $this->companyA, $breachId);

        // Then the request fails closed: nothing recorded, so nobody is alerted
        $this->assertFailedClosed($response);
        $this->assertNothingStored($breachId, $before);
    }

    #[Test]
    public function when_the_request_is_recorded_then_no_alert_is_sent_until_the_queued_reactor_runs(): void
    {
        // Given company A was notified of a breach, and A's owner
        $breachId = $this->aNotifiedIncident($this->companyA);
        $owner = $this->anOwnerOf($this->companyA);

        // When the owner asks Lesily to file it for company A
        $response = $this->requestFiling($owner, $this->companyA, $breachId);

        // Then no admin is alerted yet: the alert waits on the queue
        $response->assertOk();
        $this->assertReactorDeferred(BreachFilingRequested::class, SystemNotification::class);
    }

    #[Test]
    public function when_no_platform_admin_is_listed_then_the_gap_is_reported_and_the_request_is_kept(): void
    {
        // Given company A was notified of a breach, A's owner, and no platform admin listed
        $breachId = $this->aNotifiedIncident($this->companyA);
        $owner = $this->anOwnerOf($this->companyA);
        $this->allowlist([]);
        Exceptions::fake();

        // When the owner asks Lesily to file it for company A and the alert leaves the queue
        $this->requestFiling($owner, $this->companyA, $breachId);
        $this->runTheAlerts();

        // Then the missing recipient is reported, nobody is alerted, and the request is kept
        Exceptions::assertReported(fn (RuntimeException $gap): bool => $gap->getMessage() === "No platform admin to alert about the filing request of company {$this->companyA->getKey()} on breach incident {$breachId}.");
        Notification::assertNothingSent();
        $this->assertSame($owner->getKey(), BreachIncidentCompany::query()->whereCompany($this->companyA)->sole()->filing_requested_by);
    }

    #[Test]
    public function when_another_company_already_asked_then_this_company_may_still_ask(): void
    {
        // Given an incident that notified companies A and B, B's request already recorded, and A's owner
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        BreachIncident::retrieve($breachId)
            ->requestFiling(RequestBreachFilingCommand::from(['breachId' => $breachId, 'company' => $this->companyB]), $this->anOwnerOf($this->companyB))
            ->persist();
        $owner = $this->anOwnerOf($this->companyA);
        $this->travelTo(self::REQUESTED_AT);

        // When A's owner asks Lesily to file it for company A
        $response = $this->requestFiling($owner, $this->companyA, $breachId);

        // Then A's request is recorded beside B's, and A's audit row cites A's own request
        $this->assertFilingRequestedFor($response, $this->companyA, $owner);
        $this->assertSame(2, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachFilingRequested::class)->count());
        $requestOfA = LesilyStoredEvent::query()->where('event_class', BreachFilingRequested::class)->where('event_properties->companyId', $this->companyA->getKey())->sole();
        $this->assertSame($requestOfA->getKey(), AuditEntry::query()->sole()->stored_event_id);
    }

    #[Test]
    public function when_the_company_is_on_two_incidents_then_only_the_asked_incident_is_stamped(): void
    {
        // Given company A was notified of two incidents, and A's owner
        $otherId = $this->aNotifiedIncident($this->companyA);
        $askedId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($otherId);
        $owner = $this->anOwnerOf($this->companyA);

        // When the owner asks Lesily to file the second incident for company A
        $response = $this->requestFiling($owner, $this->companyA, $askedId);

        // Then the asked incident's notice is stamped requested and the other is left as it was
        $response->assertOk();
        $this->assertSame([$askedId, 'requested'], [$response->json('data.breach_id'), $response->json('data.filing_status')]);
        $this->assertSame($before, $this->snapshotOf($otherId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $otherId)->where('event_class', BreachFilingRequested::class)->count());
    }

    #[Test]
    public function when_the_company_already_asked_on_another_incident_then_this_request_is_recorded_and_audited(): void
    {
        // Given company A was notified of two incidents and already asked Lesily to file the first, and A's owner
        $firstId = $this->aNotifiedIncident($this->companyA);
        $owner = $this->anOwnerOf($this->companyA);
        BreachIncident::retrieve($firstId)
            ->requestFiling(RequestBreachFilingCommand::from(['breachId' => $firstId, 'company' => $this->companyA]), $owner)
            ->persist();
        $secondId = $this->aNotifiedIncident($this->companyA);

        // When the owner asks Lesily to file the second incident for company A
        $response = $this->requestFiling($owner, $this->companyA, $secondId);

        // Then the second request is recorded, and its audit row cites the second incident's request
        $response->assertOk();
        $request = LesilyStoredEvent::query()->where('aggregate_uuid', $secondId)->where('event_class', BreachFilingRequested::class)->sole();
        $this->assertSame([$request->getKey(), $secondId], [AuditEntry::query()->sole()->stored_event_id, AuditEntry::query()->sole()->subject_id]);
    }

    #[Test]
    public function when_the_owner_asks_then_the_instruction_is_recorded_and_the_notices_list_shows_it_requested(): void
    {
        // Given company A was notified of a breach, and A's owner
        $breachId = $this->aNotifiedIncident($this->companyA);
        $owner = $this->anOwnerOf($this->companyA);
        $this->travelTo(self::REQUESTED_AT);

        // When the owner asks Lesily to file it for company A
        $this->requestFiling($owner, $this->companyA, $breachId);

        // Then the instruction is recorded with A, the owner and when, and A's notices list shows it requested
        $this->assertRequestStored($breachId, $this->companyA, $owner);
        $listed = $this->listNotices($owner, $this->companyA);
        $listed->assertOk();
        $this->assertSame(
            ['requested', self::REQUESTED_AT_JSON, $owner->getKey()],
            [$listed->json('data.0.filing_status'), $listed->json('data.0.filing_requested_at'), $listed->json('data.0.filing_requested_by')],
        );
    }

    #[Test]
    public function when_the_owner_asks_lesily_to_file_then_it_is_recorded_projected_audited_and_the_admins_alerted(): void
    {
        // Given an incident that notified companies A and B, two allowlisted Lesily admins, and A's and B's owners
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->allowlist([$this->admin->getKey(), User::factory()->create()->getKey()]);
        $owner = $this->anOwnerOf($this->companyA);
        $otherOwner = $this->anOwnerOf($this->companyB);
        $this->travelTo(self::REQUESTED_AT);

        // When A's owner asks Lesily to file it for company A and the alerts leave the queue
        $response = $this->requestFiling($owner, $this->companyA, $breachId);
        $this->runTheAlerts();

        // Then the request is recorded, projected and audited for A, each admin is alerted once,
        // and company B's filing and owner are left as they were
        $this->assertFilingRequestedFor($response, $this->companyA, $owner);
        $storedEventId = $this->assertRequestStored($breachId, $this->companyA, $owner);
        $this->assertRequestAudited($response, $breachId, $storedEventId);
        $this->assertAdminsAlertedAbout($this->companyA, $breachId);
        $this->assertCompanyUntouched($breachId, $this->companyB, $otherOwner);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheRequestPermission(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    protected function runTheAlerts(): void
    {
        $this->runQueuedHandlersFor(BreachFilingRequested::class);
    }

    protected function assertFilingRequestedFor(TestResponse $response, Company $company, User $owner): void
    {
        $response->assertOk();
        $this->assertSame(
            [$company->name, 'requested', self::REQUESTED_AT_JSON, $owner->getKey(), null],
            [$response->json('data.company_name'), $response->json('data.filing_status'), $response->json('data.filing_requested_at'), $response->json('data.filing_requested_by'), $response->json('data.filed_at')],
        );

        $notice = BreachIncidentCompany::query()->whereCompany($company)->sole();
        $this->assertNotNull($notice->filing_requested_at);
        $this->assertTrue(CarbonImmutable::parse(self::REQUESTED_AT)->equalTo($notice->filing_requested_at));
        $this->assertSame($owner->getKey(), $notice->filing_requested_by);
    }

    protected function assertRequestAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $this->companyA->getKey(),
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachFilingRequested',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_filing_requested',
            'description' => 'Owner asked Lesily to file a data breach with the Data Protection Authority.',
            'metadata' => null,
            'recorded_at' => self::REQUESTED_AT,
            'actor_role_code' => 'owner',
            'ip_address' => '127.0.0.1',
            'severity' => 'critical',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->sole();
        $requester = LesilyStoredEvent::query()->findOrFail($storedEventId)->event_properties['requestedByUserId'];
        $this->assertSame([$correlationId, 'Symfony', $requester], [$entry->request_id, $entry->user_agent, $entry->actor_user_id]);
    }

    protected function assertAdminsAlertedAbout(Company $company, string $breachId): void
    {
        $admins = User::query()->whereKey(config('platform.admin_user_ids'))->get();
        $this->assertCount(2, $admins);
        foreach ($admins as $admin) {
            Notification::assertSentToTimes($admin, SystemNotification::class, 1);
            Notification::assertSentTo($admin, SystemNotification::class, fn (SystemNotification $alert): bool => $alert->type === NotificationType::BreachFilingRequested
                && $alert->companyName === $company->name
                && $alert->companyId === null
                && $alert->details === ['incident' => $breachId]);
        }
        Notification::assertCount(2);
    }

    protected function assertCompanyUntouched(string $breachId, Company $company, User $owner): void
    {
        $notice = BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->whereCompany($company)->sole();
        $this->assertSame([null, null], [$notice->filing_requested_at, $notice->filing_requested_by]);
        Notification::assertNothingSentTo($owner);
    }

    protected function assertRequestStored(string $breachId, Company $company, User $owner): int
    {
        $request = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', BreachFilingRequested::class)
            ->sole();

        $this->assertSame([$company->getKey(), $owner->getKey(), CarbonImmutable::parse(self::REQUESTED_AT)->toIso8601String()], [
            $request->event_properties['companyId'],
            $request->event_properties['requestedByUserId'],
            CarbonImmutable::parse($request->event_properties['requestedAt'])->toIso8601String(),
        ]);

        return (int) $request->getKey();
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingRequested(string $breachId, array $before): void
    {
        $this->assertNothingStored($breachId, $before);
        $this->assertNoHandlersQueuedFor(BreachFilingRequested::class);
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingStored(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFilingRequested::class)->count());
        $this->assertNoAuditRecorded();
    }
}
