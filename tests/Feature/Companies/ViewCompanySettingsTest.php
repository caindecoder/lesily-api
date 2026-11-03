<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UC-1-056 (narrowed) + UC-1-057 — GET /api/v1/companies/{companyId}/settings:
 * the company's country and, per setting, the country default, the company's
 * own value, the value that applies and whether it follows the country.
 */
class ViewCompanySettingsTest extends CompaniesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        config(['app.debug' => false]);
    }

    #[Test]
    public function when_unauthenticated_then_the_settings_request_is_rejected(): void
    {
        // Given an existing company and no authenticated session
        $company = Company::factory()->create();

        // When a guest requests its settings
        $response = $this->viewCompanySettingsAsGuest($company->getKey());

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_a_member_requests_another_companys_settings_then_company_access_is_denied(): void
    {
        // Given a member of company A holding company B's id
        $companyA = Company::factory()->withSettings()->create();
        $companyB = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($companyA);

        // When they request company B's settings
        $response = $this->viewCompanySettings($member, $companyB->getKey());

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }

    #[Test]
    #[DataProvider('provideUnknownCompanyIds')]
    public function when_the_requested_company_is_unknown_then_the_settings_are_not_found(string $companyId): void
    {
        // Given a member of a real company
        $member = $this->aCompanyMember(Company::factory()->withSettings()->create());

        // When they request the settings of an unknown company id
        $response = $this->viewCompanySettings($member, $companyId);

        // Then the resource is not found
        $this->assertResourceNotFound($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownCompanyIds(): array
    {
        return [
            'nonexistent uuid' => ['0193abcd-0000-7000-8000-000000000000'],
            'garbage string' => ['not-a-company-id'],
            'numeric value' => ['12345'],
        ];
    }

    #[Test]
    public function when_a_manager_requests_the_settings_then_the_role_is_insufficient(): void
    {
        // Given a manager of the company — the one seeded role without companies.view
        $company = Company::factory()->withSettings()->create();
        $manager = $this->aCompanyMember($company, 'manager');

        // When they request the company's settings
        $response = $this->viewCompanySettings($manager, $company->getKey());

        // Then their role is insufficient for companies.view
        $this->assertRoleInsufficient($response, 'companies.view');
    }

    protected function viewCompanySettings(User $user, string $companyId): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/companies/'.$companyId.'/settings');
    }

    protected function viewCompanySettingsAsGuest(string $companyId): TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$companyId.'/settings');
    }
}
