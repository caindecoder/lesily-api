<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Entities\CompanyDataProtectionOfficer;

/**
 * UC-1-146 alt 2a — the owner or an admin reads the company's recorded data protection
 * officer: GET /api/v1/companies/{companyId}/data-protection-officer.
 */
class ViewDataProtectionOfficerTest extends CompaniesTestCase
{
    use FreeTierCompany;

    protected const array DPO = ['name' => 'Nimal Silva', 'email' => 'dpo@contoso.lk', 'phone' => '+94771234567'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_unauthenticated_then_the_dpo_request_is_rejected(): void
    {
        // Given a company whose DPO is recorded
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When a guest asks for it
        $response = $this->viewDataProtectionOfficerAsGuest($this->company->getKey());

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_a_member_requests_another_companys_dpo_then_company_access_denied(): void
    {
        // Given the owner of company A, and company B whose DPO is recorded
        $companyB = Company::factory()->create();
        CompanyDataProtectionOfficer::factory()->for($companyB)->create();

        // When owner A asks for company B's DPO
        $response = $this->viewDataProtectionOfficer($this->owner, $companyB->getKey());

        // Then company access is denied
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    public function when_the_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given an owner, and a company id that matches no company
        $unknownCompanyId = (string) Str::uuid();

        // When they ask for the unknown company's DPO
        $response = $this->viewDataProtectionOfficer($this->owner, $unknownCompanyId);

        // Then the resource is not found
        $this->assertResourceNotFound($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutThePermission')]
    public function when_a_role_lacks_the_permission_then_the_dpo_is_withheld(string $roleCode): void
    {
        // Given a member whose role does not hold companies.update, in a company whose DPO is recorded
        $member = $this->memberWithRole($roleCode);
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When they ask for the DPO
        $response = $this->viewDataProtectionOfficer($member, $this->company->getKey());

        // Then the role is insufficient
        $this->assertRoleInsufficient($response, 'companies.update');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutThePermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    #[Test]
    public function when_no_dpo_is_recorded_then_it_is_not_found(): void
    {
        // Given the owner of a company with no DPO recorded (the ready-made company)

        // When they ask for the DPO
        $response = $this->viewDataProtectionOfficer($this->owner, $this->company->getKey());

        // Then it is not found
        $this->assertResourceNotFound($response);
    }

    #[Test]
    public function when_the_owner_of_two_companies_views_one_then_only_that_companys_dpo_is_returned(): void
    {
        // Given the owner of two companies, each with its own DPO
        $companyB = Company::factory()->create();
        $companyB->users()->attach($this->owner, ['role_id' => $this->seededRole('owner')->getKey()]);
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();
        CompanyDataProtectionOfficer::factory()->for($companyB)->withoutPhone()->create(['name' => 'Kamala Fernando']);

        // When they ask for company A's DPO
        $response = $this->viewDataProtectionOfficer($this->owner, $this->company->getKey());

        // Then only company A's DPO is returned
        $response->assertOk();
        $response->assertExactJson(['data' => self::DPO]);
    }

    #[Test]
    #[DataProvider('provideRolesWithThePermission')]
    public function when_a_role_with_the_permission_views_the_dpo_then_it_is_returned(string $roleCode): void
    {
        // Given a member holding companies.update, in a company whose DPO is recorded
        $member = $this->memberWithRole($roleCode);
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When they ask for the DPO
        $response = $this->viewDataProtectionOfficer($member, $this->company->getKey());

        // Then it is returned
        $response->assertOk();
        $response->assertExactJson(['data' => self::DPO]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithThePermission(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
    }
}
