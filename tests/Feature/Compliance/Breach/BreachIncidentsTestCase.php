<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Breach\Events\BreachRecorded;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use Tests\Feature\PlatformAdmin\PlatformAdminTestCase;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

abstract class BreachIncidentsTestCase extends PlatformAdminTestCase
{
    protected User $admin;

    protected Company $companyA;

    protected Company $companyB;

    protected ?string $idempotencyKey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seedRbacCatalog();
        Notification::fake();

        $this->admin = $this->anAllowlistedAdmin();
        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->idempotencyKey = (string) Str::uuid();
    }

    protected function anAllowlistedAdmin(): User
    {
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);

        return $admin;
    }

    protected function anIncident(): BreachIncidentRecordFactory
    {
        return BreachIncidentRecord::factory()->recordedBy($this->admin);
    }

    protected function aRecordedIncident(array $overrides = []): string
    {
        $breachId = Str::uuid7()->toString();

        BreachIncident::retrieve($breachId)
            ->record(RecordBreachCommand::from($this->validPayload($overrides)), $this->admin)
            ->persist();

        return $breachId;
    }

    protected function notifyCompanies(?User $caller, string $breachId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/notifications/companies");
    }

    /**
     * @return list<User>
     */
    protected function anOwnerAndAdmins(Company $company): array
    {
        return [
            $this->aCompanyMember($company, 'owner'),
            $this->aCompanyMember($company, 'admin'),
            $this->aCompanyMember($company, 'admin'),
        ];
    }

    protected function aNoticeFor(BreachIncidentRecordFactory $incident, Company $company, CarbonImmutable $notifiedAt): BreachIncidentCompany
    {
        return BreachIncidentCompany::factory()
            ->forIncident($incident->createOne(), $company)
            ->notifiedAt($notifiedAt)
            ->createOne();
    }

    protected function aConsentingMember(Company $company, string $roleCode): User
    {
        $member = $this->aCompanyMember($company, $roleCode);
        $this->aConsentRecordedBy($member, $company);

        return $member;
    }

    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
    }

    protected function assertNothingListed(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.pagination.total', 0);
    }

    protected function listNotices(?User $actor, Company $company, array $query = []): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->getJson("/api/v1/companies/{$company->getKey()}/breach-notices?".http_build_query($query));
    }

    protected function recordBreach(?User $caller, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        $headers = $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];

        return $this->postJson('/api/v1/admin/breach-incidents', $body, $headers);
    }

    protected function validPayload(array $overrides = []): array
    {
        return [
            'description' => 'A payroll export was emailed to the wrong recipient.',
            'likelyConsequences' => 'Salary and bank details may be read by one outside person.',
            'measures' => 'The recipient was asked to delete the file; exports now need a second check.',
            'dateDiscovered' => '2026-05-04T14:00:00Z',
            'severity' => 'high',
            'companies' => [
                ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40],
            ],
            ...$overrides,
        ];
    }

    protected function assertFailedClosed(TestResponse $response): void
    {
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $response->assertJsonMissingPath('data');
    }

    protected function assertNothingRecorded(): void
    {
        $this->assertDatabaseCount('breach_incidents', 0);
        $this->assertDatabaseCount('breach_incident_companies', 0);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachRecorded::class)->count());
        $this->assertNoAuditRecorded();
    }

    /**
     * @param  array<string, array{list<string>, ?int}>  $companies  each company id with its data types and count
     */
    protected function assertIncidentProjected(string $breachId, array $companies): void
    {
        $this->assertDatabaseHas('breach_incidents', ['id' => $breachId, 'stage' => 'recorded']);
        $this->assertDatabaseCount('breach_incident_companies', count($companies));
        $this->assertNotEmpty($companies);

        foreach ($companies as $companyId => [$dataTypes, $estimatedCount]) {
            $row = BreachIncidentCompany::query()
                ->where('breach_incident_id', $breachId)
                ->where('company_id', $companyId)
                ->sole();
            $this->assertSame([$dataTypes, $estimatedCount, $this->admin->getKey()], [$row->data_types, $row->estimated_count, $row->added_by]);
            $this->assertTrue($row->added_at->equalTo(BreachIncidentRecord::query()->findOrFail($breachId)->recorded_at));
        }
    }
}
