<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\PlatformAdmin\PlatformAdminTestCase;

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
        $this->assertNoAuditRecorded();
    }
}
