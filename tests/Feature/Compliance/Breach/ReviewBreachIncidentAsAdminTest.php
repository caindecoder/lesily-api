<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Entities\BreachAmendment;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;

class ReviewBreachIncidentAsAdminTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_an_unauthenticated_caller_opens_an_incident_then_they_are_refused(): void
    {
        // Given a recorded incident
        $incident = $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();

        // When a caller with no token opens it
        $response = $this->showIncident(null, $incident->getKey());

        // Then they are refused as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoDetailReadRecorded();
    }

    #[Test]
    public function when_a_company_owner_opens_an_incident_then_it_is_not_found_and_nothing_is_audited(): void
    {
        // Given an incident naming company A, and company A's owner, who is not a platform admin
        $incident = $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner opens the incident
        $response = $this->showIncident($owner, $incident->getKey());

        // Then the admin surface is not acknowledged — not even their own company's incident — and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoDetailReadRecorded();
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident with this id
        // When the admin opens it
        $response = $this->showIncident($this->admin, $breachId);

        // Then it is not found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoDetailReadRecorded();
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_the_incident_is_not_shown(): void
    {
        // Given an incident, and an audit write that fails after its row is inserted —
        // only the read's transaction can take the row back out
        $incident = $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then the read fails closed — no incident data, and the evidence row was rolled back with it
        $this->assertFailedClosed($response);
        $this->assertNoDetailReadRecorded();
    }

    #[Test]
    #[DataProvider('provideCompanyCounts')]
    public function when_some_companies_have_no_estimated_count_then_the_sum_counts_only_the_known_ones(?int $countA, ?int $countB, ?int $expectedTotal): void
    {
        // Given an incident whose two companies carry these counts — a null count is not yet known
        $incident = $this->anIncident()
            ->withCompany($this->companyA, ['pii'], $countA)
            ->withCompany($this->companyB, ['payroll'], $countB)
            ->createOne();

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then the total adds the known counts only, and is empty when none is known
        $response->assertOk();
        $this->assertSame($expectedTotal, $response->json('data.total_estimated_count'));
    }

    #[Test]
    public function when_an_incident_names_several_companies_then_they_are_listed_by_name(): void
    {
        // Given an incident naming Zeta Traders and then Acme Holdings — added in that order, Zeta first by id
        $zeta = Company::factory()->named('Zeta Traders')->createOne();
        $acme = Company::factory()->named('Acme Holdings')->createOne();
        $incident = $this->anIncident()
            ->withCompany($zeta, ['pii'], 4)
            ->withCompany($acme, ['payroll'], 9)
            ->createOne();

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then the companies are listed by name, whatever order they were added in
        $response->assertOk();
        $this->assertSame(['Acme Holdings', 'Zeta Traders'], array_column($response->json('data.companies'), 'name'));
    }

    #[Test]
    public function when_an_admin_opens_an_incident_then_every_company_and_the_sum_are_shown_and_the_read_is_audited(): void
    {
        // Given an incident whose companies were notified — company A's owner has asked Lesily to file
        $incident = $this->anIncident()->companiesNotified()->createOne();
        $owner = $this->aCompanyMember($this->companyA, 'owner');
        BreachIncidentCompany::factory()->forIncident($incident, $this->companyA)->withFigures(['pii'], 40)->filingRequested($owner->getKey())->createOne();
        BreachIncidentCompany::factory()->forIncident($incident, $this->companyB)->withFigures(['payroll', 'banking'], 7)->notified()->createOne();

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then every fact of the incident and of each company is shown with the sum, and the read left its evidence
        $this->assertIncidentShown($response, $incident);
        $this->assertDetailReadAudited($response, $incident);
    }

    #[Test]
    public function when_a_resolved_incident_is_opened_then_its_resolution_missed_deadline_and_filing_are_shown(): void
    {
        // Given an incident that missed its deadline, was filed with the regulator for company A, then was resolved
        $incident = $this->anIncident()->deadlineMissed()->resolved()->createOne();
        $owner = $this->aCompanyMember($this->companyA, 'owner');
        BreachIncidentCompany::factory()->forIncident($incident, $this->companyA)->filed($owner->getKey(), $this->admin->getKey())->createOne();

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then the missed deadline, the resolution and the filing are each shown in their own field
        $response->assertOk();
        $this->assertSame(
            [
                '2026-05-08T00:00:00.000000Z', '2026-05-09T14:00:00.000000Z', $this->admin->getKey(),
                $incident->root_cause, $incident->remediation_steps, $incident->resolution_summary,
                '2026-05-07T14:00:00.000000Z',
            ],
            [
                $response->json('data.deadline_missed_at'), $response->json('data.resolved_at'), $response->json('data.resolved_by'),
                $response->json('data.root_cause'), $response->json('data.remediation_steps'), $response->json('data.resolution_summary'),
                $response->json('data.companies.0.filed_at'),
            ],
        );
    }

    #[Test]
    public function when_a_false_alarm_is_opened_then_its_reason_and_findings_are_shown(): void
    {
        // Given an incident closed as a false alarm
        $incident = $this->anIncident()->falseAlarm()->withCompany($this->companyA, ['pii'], 40)->createOne();

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then when, by whom, why and what was found are each shown in their own field
        $response->assertOk();
        $this->assertSame(
            ['2026-05-05T14:00:00.000000Z', $this->admin->getKey(), $incident->false_alarm_reason, $incident->false_alarm_findings],
            [
                $response->json('data.false_alarm_at'), $response->json('data.false_alarm_by'),
                $response->json('data.false_alarm_reason'), $response->json('data.false_alarm_findings'),
            ],
        );
    }

    #[Test]
    public function when_an_incident_was_corrected_then_its_history_is_shown_newest_first_with_who_when_and_why(): void
    {
        // Given an incident corrected twice - its description on 5 May, then on 6 May its severity
        // and company A's data types - with the rows stored out of that order
        $incident = $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();
        $correction = BreachAmendment::factory()->forIncident($incident);
        $correction->amendedAt('2026-05-06 10:00:00')->changing(BreachAmendmentField::Severity, 'high', 'critical')->createOne(['reason' => 'The export held bank details.']);
        $correction->amendedAt('2026-05-05 09:00:00')->createOne();
        $correction->amendedAt('2026-05-06 10:00:00')->forCompany($this->companyA)->changing(BreachAmendmentField::DataTypes, ['pii'], ['pii', 'banking'])->createOne(['reason' => 'The export held bank details.']);

        // When the admin opens the incident
        $response = $this->showIncident($this->admin, $incident->getKey());

        // Then the history lists the 6 May request first, its changes by field, then the 5 May correction
        $response->assertOk();
        $this->assertSame([
            ['field' => 'data_types', 'company_id' => $this->companyA->getKey(), 'old_value' => ['pii'], 'new_value' => ['pii', 'banking'], 'reason' => 'The export held bank details.', 'amended_by' => $this->admin->getKey(), 'amended_at' => '2026-05-06T10:00:00.000000Z'],
            ['field' => 'severity', 'company_id' => null, 'old_value' => 'high', 'new_value' => 'critical', 'reason' => 'The export held bank details.', 'amended_by' => $this->admin->getKey(), 'amended_at' => '2026-05-06T10:00:00.000000Z'],
            ['field' => 'description', 'company_id' => null, 'old_value' => 'A payroll export was emailed to the wrong recipient.', 'new_value' => 'Payroll and bank export left on a shared drive', 'reason' => 'An earlier log entry showed the export reached a second shared folder.', 'amended_by' => $this->admin->getKey(), 'amended_at' => '2026-05-05T09:00:00.000000Z'],
        ], $response->json('data.amendments'));
    }

    /**
     * @return array<string, array{?int, ?int, ?int}>
     */
    public static function provideCompanyCounts(): array
    {
        return [
            'one count unknown' => [40, null, 40],
            'every count unknown' => [null, null, null],
            'a known zero' => [0, null, 0],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownBreachIds(): array
    {
        return [
            'a random uuid' => ['0199a3f0-0000-7000-8000-00000000dead'],
            'not a uuid' => ['not-a-uuid'],
        ];
    }

    protected function showIncident(?User $caller, string $breachId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/breach-incidents/'.$breachId);
    }

    protected function assertIncidentShown(TestResponse $response, BreachIncidentRecord $incident): void
    {
        $response->assertOk();
        $companies = BreachIncidentCompany::query()->with('company')->where('breach_incident_id', $incident->getKey())->get()->sortBy(fn (BreachIncidentCompany $company): string => $company->company->name)->values();
        $this->assertCount(2, $companies);

        $response->assertExactJson(['data' => [
            'id' => $incident->getKey(),
            'stage' => 'companies_notified',
            'severity' => 'high',
            'description' => $incident->description,
            'likely_consequences' => $incident->likely_consequences,
            'measures' => $incident->measures,
            'date_discovered' => '2026-05-04T14:00:00.000000Z',
            'deadline' => '2026-05-07T14:00:00.000000Z',
            'recorded_at' => '2026-05-04T14:00:00.000000Z',
            'recorded_by' => $this->admin->getKey(),
            'deadline_missed_at' => null,
            'companies_notified_at' => '2026-05-05T14:00:00.000000Z',
            'resolved_at' => null,
            'resolved_by' => null,
            'root_cause' => null,
            'remediation_steps' => null,
            'resolution_summary' => null,
            'false_alarm_at' => null,
            'false_alarm_by' => null,
            'false_alarm_reason' => null,
            'false_alarm_findings' => null,
            'companies' => $companies->map(fn (BreachIncidentCompany $company): array => [
                'company_id' => $company->company_id,
                'name' => $company->company->name,
                'data_types' => $company->data_types,
                'estimated_count' => $company->estimated_count,
                'notified_at' => '2026-05-05T14:00:00.000000Z',
                'filing_requested_at' => $company->company_id === $this->companyA->getKey() ? '2026-05-06T14:00:00.000000Z' : null,
                'filing_requested_by' => $company->filing_requested_by,
                'filed_at' => null,
            ])->all(),
            'total_estimated_count' => 47,
            'amendments' => [],
        ]]);
    }

    protected function assertDetailReadAudited(TestResponse $response, BreachIncidentRecord $incident): void
    {
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->where('action', 'breach_admin_viewed')->sole();

        $this->assertDatabaseHas('audit_trail', [
            'id' => $entry->getKey(),
            'company_id' => null,
            'stored_event_id' => null,
            'event_class' => 'BreachIncidentViewedByAdmin',
            'subject_type' => 'breach_incident',
            'subject_id' => $incident->getKey(),
            'subject_label' => null,
            'description' => 'Platform admin viewed a data breach.',
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'request_id' => $correlationId,
            'metadata' => null,
            'severity' => 'info',
        ]);
    }

    protected function assertNoDetailReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_admin_viewed']);
    }
}
