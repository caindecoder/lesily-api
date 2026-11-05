<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
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
        $companies = BreachIncidentCompany::query()->with('company')->where('breach_incident_id', $incident->getKey())->orderBy('company_id')->get();
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
