<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Values\RegulatorFiling;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use Database\Factories\BreachIncidentCompanyFactory;
use Tests\Feature\PlatformAdmin\PlatformAdminTestCase;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;
use App\Compliance\Breach\UseCases\Commands\FileBreachWithRegulatorCommand;

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

    protected function aFilingRequestBy(Company $company, string $breachId): User
    {
        $owner = $this->anOwnerOf($company);

        BreachIncident::retrieve($breachId)
            ->requestFiling(RequestBreachFilingCommand::from(['breachId' => $breachId, 'company' => $company]), $owner)
            ->persist();

        return $owner;
    }

    protected function anIncidentFiledFor(Company $company): string
    {
        $breachId = $this->aNotifiedIncident($company);
        $this->aFilingRequestBy($company, $breachId);

        BreachIncident::retrieve($breachId)
            ->fileWithRegulator($this->aFilingCommandFor($breachId, $company), $this->admin, $this->aRegulatorFiling())
            ->persist();

        return $breachId;
    }

    protected function aFilingCommandFor(string $breachId, Company $company): FileBreachWithRegulatorCommand
    {
        return FileBreachWithRegulatorCommand::from(['breachId' => $breachId, 'company' => $company]);
    }

    protected function aRegulatorFiling(): RegulatorFiling
    {
        return new RegulatorFiling('Acme (Pvt) Ltd', 'dpa@regulator.test', ['count' => '40'], hash('sha256', 'the regulator notice'));
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

    protected function aPartFor(BreachIncidentRecord $incident, Company $company): BreachIncidentCompanyFactory
    {
        return BreachIncidentCompany::factory()->forIncident($incident, $company);
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

    /**
     * @param  list<string>  $breachIds
     */
    protected function assertNoticesListed(TestResponse $response, array $breachIds): void
    {
        $response->assertOk();
        $this->assertSame($breachIds, $response->json('data.*.breach_id'));
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

    protected function listNoticesWithBody(User $actor, Company $company, array $body): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->json('GET', "/api/v1/companies/{$company->getKey()}/breach-notices", $body);
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

    protected function anOwnerOf(Company $company): User
    {
        return $this->aConsentingMember($company, 'owner');
    }

    protected function aFactoryNotice(BreachIncidentRecordFactory $incident, Closure $state): BreachIncidentCompany
    {
        return $state($this->aPartFor($incident->createOne(), $this->companyA))->createOne();
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    protected function snapshotOf(string $breachId): array
    {
        return [
            BreachIncidentRecord::query()->whereKey($breachId)->firstOrFail()->getAttributes(),
            BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    protected function assertRefusedAsNotOpen(TestResponse $response, string $message): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::StateGuardViolation);
        $response->assertJsonPath('message', $message);
    }
}
