<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachResolved;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\UseCases\Commands\AddBreachCompanyCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ResolveBreachIncidentTest extends BreachIncidentsTestCase
{
    protected const string ROOT_CAUSE = 'A payroll export job sent files without checking the recipient.';

    protected const string REMEDIATION = 'Exports now need a second person to confirm the recipient; the job was patched.';

    protected const string SUMMARY = 'One file reached one outside person, who deleted it; no further reading was found.';

    protected const string PAST_THE_DEADLINE = '2026-05-08T10:00:00Z';

    protected const string RESOLVED_AT = '2026-05-08 10:00:00';

    protected const string RESOLVED_AT_ISO = '2026-05-08T10:00:00+00:00';

    protected const string RESOLVED_AT_JSON = '2026-05-08T10:00:00.000000Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
    }

    #[Test]
    public function when_an_unauthenticated_caller_resolves_then_it_is_refused_as_unauthenticated(): void
    {
        // Given an incident whose companies A and B have been notified, its deadline passed
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When a caller with no token resolves it
        $response = $this->resolve(null, $breachId, $this->aResolution());

        // Then they are refused as unauthenticated and nothing is resolved
        $this->assertUnauthenticated($response);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_resolves_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given an incident whose companies A and B have been notified, its deadline passed, and company A's owner
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the owner resolves it on the admin surface
        $response = $this->resolve($owner, $breachId, $this->aResolution());

        // Then the admin surface is not acknowledged and nothing is resolved
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then it is not found and nothing is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }

    #[Test, DataProvider('provideInvalidResolutions')]
    public function when_the_resolution_is_invalid_then_it_is_refused_and_nothing_is_recorded(array $override, string $field, string $message): void
    {
        // Given an incident whose company A has been notified, its deadline passed
        $breachId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it with one fact wrong
        $response = $this->resolve($this->admin, $breachId, $this->aResolution($override));

        // Then the close is refused on that fact and nothing is resolved
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_the_companies_are_not_yet_notified_then_the_close_is_refused(): void
    {
        // Given an incident still at recorded, discovered four days ago
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it with a root cause, remediation steps and a summary
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the close is refused and nothing is resolved
        $this->assertRefusedAsNotOpen($response, 'A breach incident is resolved only after its companies are notified.');
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_the_deadline_has_not_passed_then_the_close_is_refused(): void
    {
        // Given an incident at companies_notified discovered at 2026-05-04 14:00 UTC with no filing request
        $breachId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse('2026-05-07T13:59:59Z'));

        // When the admin resolves it a second before its deadline
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the close is refused and the stage is still companies_notified
        $this->assertRefusedAsNotOpen($response, 'A breach incident is resolved only after its 72-hour deadline has passed.');
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_a_filing_request_waits_then_the_close_is_refused(): void
    {
        // Given an incident at companies_notified past its deadline affecting companies A and B,
        // and company A's owner asked for a filing Lesily has not sent
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the close is refused and nothing is resolved
        $this->assertRefusedAsNotOpen($response, 'A breach incident is resolved only once every filing request has been filed.');
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_a_late_company_s_filing_request_waits_then_the_close_is_refused(): void
    {
        // Given an incident at companies_notified past its deadline affecting company A, with company B
        // added late, and company B's owner asked for a filing Lesily has not sent
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->aLateCompanyOn($breachId, $this->companyB);
        $this->aFilingRequestBy($this->companyB, $breachId);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the close is refused and nothing is resolved
        $this->assertRefusedAsNotOpen($response, 'A breach incident is resolved only once every filing request has been filed.');
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test, DataProvider('provideClosedStages')]
    public function when_the_incident_is_closed_then_the_close_is_refused(Closure $closed, string $message): void
    {
        // Given a closed incident
        $breachId = $closed($this->anIncident())->createOne()->getKey();
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then it is refused with the stage named, and nothing is resolved
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_an_incident_already_resolved_is_resolved_again_then_it_is_refused_and_recorded_once(): void
    {
        // Given an incident company A was notified of, already resolved by the admin after its deadline
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));
        $this->resolve($this->admin, $breachId, $this->aResolution());
        $before = $this->snapshotOf($breachId);

        // When the admin resolves it again
        $response = $this->resolve($this->admin, $breachId, $this->aResolution(['summary' => 'A second close.']));

        // Then it is refused, the incident is unchanged, and the close is recorded and audited once
        $this->assertRefusedAsNotOpen($response, 'A resolved breach incident cannot be resolved again.');
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachResolved::class)->count());
        $this->assertDatabaseCount('audit_trail', 1);
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_nothing_is_resolved(): void
    {
        // Given an incident at companies_notified past its deadline, and an audit trail that refuses every write
        $breachId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the request fails closed: no BreachResolved is recorded and the incident is unchanged
        $this->assertFailedClosed($response);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_resolved_exactly_at_the_deadline_then_the_incident_is_resolved(): void
    {
        // Given an incident at companies_notified discovered at 2026-05-04 14:00 UTC with no filing request
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->travelTo(CarbonImmutable::parse('2026-05-07T14:00:00Z'));

        // When the admin resolves it at the moment its deadline is reached
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the incident is resolved
        $response->assertOk();
        $response->assertJsonPath('data.stage', 'resolved');
    }

    #[Test]
    public function when_every_fact_is_at_its_longest_then_the_incident_is_resolved(): void
    {
        // Given an incident at companies_notified past its deadline
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));
        $longest = [str_repeat('r', 1000), str_repeat('s', 1000), str_repeat('t', 1000)];

        // When the admin resolves it with each fact at 1,000 characters
        $response = $this->resolve($this->admin, $breachId, array_combine(['rootCause', 'remediationSteps', 'summary'], $longest));

        // Then the incident is resolved with every fact kept whole
        $response->assertOk();
        $row = BreachIncidentRecord::query()->findOrFail($breachId);
        $this->assertSame($longest, [$row->root_cause, $row->remediation_steps, $row->resolution_summary]);
    }

    #[Test]
    public function when_a_critical_incident_s_requests_are_all_filed_then_it_closes_under_the_same_rule(): void
    {
        // Given a critical incident at companies_notified whose deadline passed an hour ago, company A's filing request sent
        $breachId = $this->aRecordedIncident(['severity' => 'critical']);
        BreachIncident::retrieve($breachId)->notifyCompanies($this->admin, [
            $this->companyA->getKey() => ['description' => 'A payroll export was emailed to the wrong recipient.'],
        ])->persist();
        $this->aFilingRequestBy($this->companyA, $breachId);
        $this->fileFor($this->companyA, $breachId);
        $this->travelTo(CarbonImmutable::parse('2026-05-07T15:00:00Z'));

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then it is resolved under the same rule as any other severity
        $response->assertOk();
        $response->assertJsonPath('data.stage', 'resolved');
    }

    #[Test]
    public function when_an_incident_is_resolved_then_its_companies_see_it_resolved_in_their_list(): void
    {
        // Given an incident company A was notified of, resolved by the admin after its deadline, and company A's owner
        $breachId = $this->aNotifiedIncident($this->companyA);
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));
        $this->resolve($this->admin, $breachId, $this->aResolution());

        // When company A's owner lists its breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the list shows the incident resolved
        $response->assertOk();
        $this->assertSame([[$breachId, 'resolved']], array_map(
            fn (array $notice): array => [$notice['breach_id'], $notice['stage']],
            $response->json('data'),
        ));
    }

    #[Test]
    public function when_the_body_names_another_incident_then_the_incident_in_the_address_is_resolved(): void
    {
        // Given two incidents company A was notified of, both past their deadline
        $addressed = $this->aNotifiedIncident($this->companyA);
        $other = $this->aNotifiedIncident($this->companyA);
        $otherBefore = $this->snapshotOf($other);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves the first, with the second's id in the body
        $response = $this->resolve($this->admin, $addressed, $this->aResolution(['breachId' => $other]));

        // Then the incident in the address is resolved and the other is untouched
        $response->assertOk();
        $this->assertSame(BreachStage::Resolved, BreachIncidentRecord::query()->findOrFail($addressed)->stage);
        $this->assertSame($otherBefore, $this->snapshotOf($other));
    }

    #[Test]
    public function when_a_platform_admin_resolves_an_incident_then_it_is_recorded_projected_audited_and_nobody_is_told(): void
    {
        // Given an incident at companies_notified affecting companies A and B, its deadline passed;
        // company A's owner asked for a filing and Lesily filed for A; company B did not ask
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $this->fileFor($this->companyA, $breachId);
        Notification::fake();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it with a root cause, remediation steps and a summary
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then the incident is resolved in the event store and the read model, the close is audited,
        // and nobody - neither company nor the regulator - is told
        $this->assertResolvedAnswered($response);
        $storedEventId = $this->assertResolutionStored($breachId);
        $this->assertResolutionProjected($breachId);
        $this->assertResolveAudited($response, $breachId, $storedEventId);
        $this->assertNobodyTold();
    }

    public static function provideClosedStages(): array
    {
        return [
            'resolved' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->resolved(), 'A resolved breach incident cannot be resolved again.'],
            'a false alarm' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm(), 'A breach incident marked as a false alarm cannot be resolved.'],
        ];
    }

    public static function provideInvalidResolutions(): array
    {
        return [
            'no root cause' => [['rootCause' => null], 'rootCause', 'The root cause field is required.'],
            'a root cause that is not text' => [['rootCause' => ['a list']], 'rootCause', 'The root cause field must be a string.'],
            'a root cause over 1,000 characters' => [['rootCause' => str_repeat('a', 1001)], 'rootCause', 'The root cause field must not be greater than 1000 characters.'],
            'no remediation steps' => [['remediationSteps' => null], 'remediationSteps', 'The remediation steps field is required.'],
            'remediation steps that are not text' => [['remediationSteps' => ['a list']], 'remediationSteps', 'The remediation steps field must be a string.'],
            'remediation steps over 1,000 characters' => [['remediationSteps' => str_repeat('a', 1001)], 'remediationSteps', 'The remediation steps field must not be greater than 1000 characters.'],
            'no summary' => [['summary' => null], 'summary', 'The summary field is required.'],
            'a summary that is not text' => [['summary' => ['a list']], 'summary', 'The summary field must be a string.'],
            'a summary over 1,000 characters' => [['summary' => str_repeat('a', 1001)], 'summary', 'The summary field must not be greater than 1000 characters.'],
        ];
    }

    protected function resolve(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/breach-incidents/{$breachId}/resolve", $body);
    }

    protected function aResolution(array $overrides = []): array
    {
        return [
            'rootCause' => self::ROOT_CAUSE,
            'remediationSteps' => self::REMEDIATION,
            'summary' => self::SUMMARY,
            ...$overrides,
        ];
    }

    protected function aLateCompanyOn(string $breachId, Company $company): void
    {
        $command = AddBreachCompanyCommand::from([
            'breachId' => $breachId,
            'companyId' => $company,
            'dataTypes' => ['payroll'],
            'estimatedCount' => 12,
            'reason' => 'The export also reached this company.',
        ]);

        BreachIncident::retrieve($breachId)
            ->addLateCompany($command, $this->admin, ['description' => 'A payroll export was emailed to the wrong recipient.'])
            ->persist();
    }

    protected function fileFor(Company $company, string $breachId): void
    {
        BreachIncident::retrieve($breachId)
            ->fileWithRegulator($this->aFilingCommandFor($breachId, $company), $this->admin, $this->aRegulatorFiling())
            ->persist();
    }

    protected function assertResolvedAnswered(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(
            ['resolved', self::ROOT_CAUSE, self::REMEDIATION, self::SUMMARY, $this->admin->getKey(), self::RESOLVED_AT_JSON],
            [
                $response->json('data.stage'),
                $response->json('data.root_cause'),
                $response->json('data.remediation_steps'),
                $response->json('data.resolution_summary'),
                $response->json('data.resolved_by'),
                $response->json('data.resolved_at'),
            ],
        );
    }

    protected function assertResolutionStored(string $breachId): int
    {
        $resolved = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->latest('id')->firstOrFail();
        $this->assertSame(BreachResolved::class, $resolved->event_class);
        $this->assertSame([$breachId, self::ROOT_CAUSE, self::REMEDIATION, self::SUMMARY, $this->admin->getKey(), self::RESOLVED_AT_ISO], [
            $resolved->event_properties['breachId'],
            $resolved->event_properties['rootCause'],
            $resolved->event_properties['remediationSteps'],
            $resolved->event_properties['summary'],
            $resolved->event_properties['resolvedByUserId'],
            CarbonImmutable::parse($resolved->event_properties['resolvedAt'])->toIso8601String(),
        ]);

        return (int) $resolved->getKey();
    }

    protected function assertResolutionProjected(string $breachId): void
    {
        $row = BreachIncidentRecord::query()->findOrFail($breachId);
        $this->assertSame(
            [BreachStage::Resolved, self::ROOT_CAUSE, self::REMEDIATION, self::SUMMARY, $this->admin->getKey(), self::RESOLVED_AT_ISO],
            [$row->stage, $row->root_cause, $row->remediation_steps, $row->resolution_summary, $row->resolved_by, $row->resolved_at?->toIso8601String()],
        );
    }

    protected function assertResolveAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachResolved',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_resolved',
            'description' => 'Platform admin resolved a data breach incident.',
            'metadata' => null,
            'recorded_at' => self::RESOLVED_AT,
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

    protected function assertNobodyTold(): void
    {
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    protected function assertNothingResolved(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachResolved::class)->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }
}
