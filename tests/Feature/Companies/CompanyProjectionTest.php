<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use App\Companies\Enums\CompanyStatus;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Companies\UseCases\Commands\CreateCompanyCommand;

class CompanyProjectionTest extends CompaniesTestCase
{
    use CreatesTrialCompanies;
    use RefreshDatabase;

    // Happy — the read side mirrors a genesis: creating a company projects a trial row
    // carrying the registration payload, with the owner linked (closes OQ-1-105).
    #[Test]
    public function when_a_company_is_created_then_a_trial_row_is_projected_with_a_30_day_trial_window(): void
    {
        // Given a fixed point in time, a new company id, and an owner-signup command
        $this->travelTo('2026-06-01 00:00:00');
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommand($companyId);

        // When the company is created and persisted (the projector runs synchronously)
        Company::retrieve($companyId)->create($command)->persist();

        // Then the read model shows a trial company expiring in 30 days, with no plan yet,
        // carrying the registration payload and the owner link
        $row = CompanyReadModel::findOrFail($companyId);

        $this->assertCompanyOnTrial($row, 'Contoso', '2026-07-01 00:00:00');
        $this->assertRegistrationProjected($row, $command);
    }

    protected function assertCompanyOnTrial(CompanyReadModel $row, string $name, string $trialEndsAt): void
    {
        $this->assertSame($name, $row->name);
        $this->assertSame(CompanyStatus::Trial, $row->status);
        $this->assertNull($row->current_billing_plan);
        $this->assertSame($trialEndsAt, $row->trial_ends_at?->toDateTimeString());
    }

    protected function assertRegistrationProjected(CompanyReadModel $row, CreateCompanyCommand $command): void
    {
        $this->assertSame($command->brNumber, $row->br_number);
        $this->assertSame($command->epfNumber, $row->epf_number);
        $this->assertSame($command->country, $row->country);
        $this->assertSame($command->ownerUserId, $row->owner_id);
    }
}
