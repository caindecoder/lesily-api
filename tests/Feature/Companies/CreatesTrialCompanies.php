<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use App\Companies\Aggregates\Company;
use App\Companies\UseCases\Commands\CreateCompanyCommand;

/**
 * Builds the owner-signup genesis for tests: a real owner user (companies.owner_id
 * is a RESTRICT FK to users) + per-company-unique BR/EPF derived from the company
 * id (both columns are globally UNIQUE per ERD-1-02).
 */
trait CreatesTrialCompanies
{
    protected function createCompanyOnTrial(string $companyId, string $name = 'Contoso'): void
    {
        Company::retrieve($companyId)
            ->create($this->buildCreateCompanyCommand($companyId, $name))
            ->persist();
    }

    protected function buildCreateCompanyCommand(string $companyId, string $name = 'Contoso', ?string $ownerUserId = null): CreateCompanyCommand
    {
        // The genesis projection enrolls the owner against the seeded 'owner'
        // role (CompanyProjector) — the catalog is part of the genesis fixture.
        $this->seedRbacCatalog();

        return CreateCompanyCommand::from([
            'name' => $name,
            'brNumber' => "BR-{$companyId}",
            'epfNumber' => "EPF-{$companyId}",
            'country' => 'LK',
            'ownerUserId' => $ownerUserId ?? User::factory()->create()->id,
        ]);
    }
}
