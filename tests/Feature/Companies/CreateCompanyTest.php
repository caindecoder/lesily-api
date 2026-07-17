<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Exceptions\UnsupportedCountryException;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * Create-company genesis at the domain seam (conv-plan §2.14 Rule C): register
 * mints fresh UUIDs, so the re-genesis and unsupported-country guards below are
 * unreachable via HTTP — they protect future callers (UC-1-031 additional-company
 * path) and replay integrity, and are provable only at this seam.
 */
class CreateCompanyTest extends CompaniesTestCase
{
    use CreatesTrialCompanies;
    use RefreshDatabase;

    // Sad — genesis is illegal from any state but [*]: an existing company cannot
    // be re-created, and the rejection must leave the original genesis untouched.
    #[Test]
    public function when_a_company_that_already_exists_is_created_again_then_it_is_rejected(): void
    {
        // Given a company that was already created
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);

        // When the same company is created again
        try {
            Company::retrieve($companyId)->create($this->buildCreateCompanyCommand($companyId, 'Contoso Again'));
            $this->fail('Re-creating an existing company must be rejected.');
        } catch (CompanyAlreadyExistsException) {
            // Then it is rejected and the original genesis is untouched
            $this->assertSame(2, LesilyStoredEvent::query()->whereForCompany($companyId)->count());
        }
    }

    // Sad — only launch-market countries have defaults to seed: an unsupported
    // country is rejected at the aggregate seam (the HTTP 422 country rule is the
    // slice-05 guard above it), and the rejection must persist nothing.
    #[Test]
    public function when_a_company_is_created_for_an_unsupported_country_then_it_is_rejected(): void
    {
        // Given an owner-signup command for a country without seeded defaults
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommandForCountry($companyId, 'US');

        // When the company is created
        try {
            Company::retrieve($companyId)->create($command);
            $this->fail('Creating a company for an unsupported country must be rejected.');
        } catch (UnsupportedCountryException) {
            // Then it is rejected and nothing is persisted for the company
            $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($companyId)->count());
        }
    }

    protected function buildCreateCompanyCommandForCountry(string $companyId, string $country): CreateCompanyCommand
    {
        return CreateCompanyCommand::validateAndCreate(
            ['country' => $country] + $this->buildCreateCompanyCommand($companyId)->toArray()
        );
    }
}
