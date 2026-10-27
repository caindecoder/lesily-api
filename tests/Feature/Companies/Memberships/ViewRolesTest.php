<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ViewRolesTest extends MembershipsTestCase
{
    #[Test]
    public function when_outsider_lists_roles_then_company_access_denied(): void
    {
        // Given an owner of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('owner');
        [$otherCompany] = $this->aCompanyWithMember('owner');

        // When they list the second company's roles
        $response = $this->viewRoles($outsider, $otherCompany);

        // Then the company context is denied
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutRolesView')]
    public function when_role_without_roles_view_lists_roles_then_refused(string $roleCode): void
    {
        // Given a member whose role does not carry roles.view
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they list the company's roles
        $response = $this->viewRoles($actor, $company);

        // Then the roles are refused for the missing permission
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutRolesView(): array
    {
        return [
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    protected function viewRoles(User $actor, Company $company): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/roles', ['X-Company-Context' => $company->getKey()]);
    }
}
