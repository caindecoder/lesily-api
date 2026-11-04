<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Breach\Events\BreachRecorded;
use Tests\Feature\PlatformAdmin\PlatformAdminTestCase;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
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
            $this->assertSame([$dataTypes, $estimatedCount], [$row->data_types, $row->estimated_count]);
        }
    }
}
