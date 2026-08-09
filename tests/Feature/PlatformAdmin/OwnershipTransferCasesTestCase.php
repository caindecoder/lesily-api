<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\OwnershipTransferCase;

abstract class OwnershipTransferCasesTestCase extends PlatformAdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seedRbacCatalog();
    }

    protected function anAllowlistedAdmin(): User
    {
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);

        return $admin;
    }

    protected function listCases(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return $this->getJson('/api/v1/admin/ownership-transfer-cases'.$queryString);
    }

    protected function showCase(?User $caller, string $caseId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/ownership-transfer-cases/'.$caseId);
    }

    protected function anOpenCaseFor(Company $company, ?User $proposed = null): OwnershipTransferCase
    {
        $factory = OwnershipTransferCase::factory()->forCompany($company);

        if ($proposed !== null) {
            $factory = $factory->proposedOwner($proposed);
        }

        return $factory->create();
    }
}
