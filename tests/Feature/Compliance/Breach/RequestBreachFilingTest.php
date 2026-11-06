<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use Database\Factories\BreachIncidentCompanyFactory;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    protected function aNotifiedIncident(Company ...$companies): string
    {
        $breachId = $this->aRecordedIncident(['companies' => array_map(fn (Company $company): array => [
            'companyId' => $company->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40,
        ], $companies)]);

        $parts = collect($companies)->mapWithKeys(fn (Company $company): array => [
            $company->getKey() => ['description' => 'A payroll export was emailed to the wrong recipient.'],
        ])->all();
        BreachIncident::retrieve($breachId)->notifyCompanies($this->admin, $parts)->persist();

        return $breachId;
    }

    protected function anOwnerOf(Company $company): User
    {
        return $this->aConsentingMember($company, 'owner');
    }

    protected function aFactoryNotice(BreachIncidentRecordFactory $incident, Closure $state): BreachIncidentCompany
    {
        return $state($this->aPartFor($incident->createOne(), $this->companyA))->createOne();
    }

    protected function requestFiling(?User $caller, Company $company, string $breachId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/companies/{$company->getKey()}/breach-notices/{$breachId}/filing-request");
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function snapshotOf(string $breachId): array
    {
        return [
            BreachIncidentRecord::query()->whereKey($breachId)->firstOrFail()->getAttributes(),
            BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->orderBy('id')->get()->map->getAttributes()->all(),
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

    protected function assertRefusedAsNotOpen(TestResponse $response, string $message): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::StateGuardViolation);
        $response->assertJsonPath('message', $message);
    }

    /**
     * @param  array{0: array<string, mixed>, 1: list<array<string, mixed>>}  $before
     */
    protected function assertNothingRequested(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFilingRequested::class)->count());
        $this->assertNoAuditRecorded();
        $this->assertNoHandlersQueuedFor(BreachFilingRequested::class);
    }
}
