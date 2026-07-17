<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
