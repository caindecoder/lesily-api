<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\RolesPermissions\Entities\Permission;

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

    #[Test]
    public function when_members_are_removed_or_in_another_company_then_they_are_not_counted(): void
    {
        // Given a company with an owner, an HR officer and a removed employee
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $this->aMembership($company, 'hr_officer');
        $this->aRemovedMembership($company, User::factory()->create());
        // And another company with an employee and an HR officer
        [$otherCompany] = $this->aCompanyWithMember('employee');
        $this->aMembership($otherCompany, 'hr_officer');

        // When the owner lists the company's roles
        $response = $this->viewRoles($owner, $company);

        // Then only the company's live members are counted
        $this->assertRoleSummaries($response, [
            'owner' => [15, 1],
            'admin' => [12, 0],
            'hr_officer' => [5, 1],
            'payroll_officer' => [1, 0],
            'auditor' => [5, 0],
            'employee' => [1, 0],
        ]);
    }

    #[Test]
    public function when_roles_are_listed_then_permissions_load_without_a_query_per_role(): void
    {
        // Given an owner
        [$company, $owner] = $this->aCompanyWithMember('owner');

        // When the owner lists the company's roles with the statements recorded
        [$response, $permissionQueries] = $this->viewRolesRecordingPermissionQueries($owner, $company);

        // Then the six roles cost fewer permission statements than there are roles
        $this->assertPermissionsLoadedOnce($response, $permissionQueries);
    }

    #[Test]
    public function when_owner_lists_roles_then_the_six_assignable_roles_arrive_in_order_with_permissions_and_counts(): void
    {
        // Given an owner, an admin and two employees in one company
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $this->aMembership($company, 'admin');
        $this->aMembership($company, 'employee');
        $this->aMembership($company, 'employee');

        // When the owner lists the company's roles
        $response = $this->viewRoles($owner, $company);

        // Then each role arrives in display order with its permission and member counts
        $this->assertRoleSummaries($response, [
            'owner' => [15, 1],
            'admin' => [12, 1],
            'hr_officer' => [5, 0],
            'payroll_officer' => [1, 0],
            'auditor' => [5, 0],
            'employee' => [1, 2],
        ]);
        // And the HR officer role is described with its permissions by module
        $this->assertRoleDescribed($response, $this->seededRole('hr_officer'), [
            'companies.view',
            'roles.view',
            'users.invite',
            'users.revoke-invitation',
            'users.view',
        ]);
    }

    #[Test]
    #[DataProvider('provideRolesWithRolesView')]
    public function when_viewing_role_lists_roles_then_the_list_is_returned(string $roleCode): void
    {
        // Given a member whose role carries roles.view
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they list the company's roles
        $response = $this->viewRoles($actor, $company);

        // Then the six assignable roles are listed in their display order
        $this->assertRoleCodesInOrder($response, ['owner', 'admin', 'hr_officer', 'payroll_officer', 'auditor', 'employee']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithRolesView(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'auditor' => ['auditor'],
        ];
    }

    protected function viewRoles(User $actor, Company $company): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/roles', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * The list call with every statement recorded, and how many of them read role_permission.
     *
     * @return array{TestResponse, int}
     */
    protected function viewRolesRecordingPermissionQueries(User $actor, Company $company): array
    {
        $response = null;
        $recorded = $this->recordedQueriesDuring(function () use (&$response, $actor, $company): void {
            $response = $this->viewRoles($actor, $company);
        });
        $this->assertInstanceOf(TestResponse::class, $response);

        return [$response, collect($recorded['queries'])->filter(fn (array $query): bool => str_contains($query['sql'], 'role_permission'))->count()];
    }

    protected function assertPermissionsLoadedOnce(TestResponse $response, int $permissionQueries): void
    {
        $response->assertOk();
        $this->assertCount(6, $response->json('data'));
        $this->assertLessThan(6, $permissionQueries);
    }

    /**
     * @param  array<string, array{int, int}>  $expected  role code => [permissions_count, members_count], in display order
     */
    protected function assertRoleSummaries(TestResponse $response, array $expected): void
    {
        $response->assertOk();
        /** @var list<array<string, mixed>> $roles */
        $roles = $response->json('data');
        $this->assertSame($expected, collect($roles)
            ->mapWithKeys(fn (array $role): array => [$role['code'] => [$role['permissions_count'], $role['members_count']]])
            ->all());
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    protected function assertRoleDescribed(TestResponse $response, Role $role, array $permissionCodes): void
    {
        /** @var list<array<string, mixed>> $roles */
        $roles = $response->json('data');
        $described = collect($roles)->firstWhere('code', $role->code);
        $this->assertIsArray($described);
        $this->assertSame([
            'id' => $role->getKey(),
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'scope' => $role->scope,
            'is_assignable' => true,
            'is_system' => true,
            'sort_order' => $role->sort_order,
        ], Arr::except($described, ['permissions', 'permissions_count', 'members_count']));
        $this->assertSame($permissionCodes, array_column($described['permissions'], 'code'));
        $permission = Permission::query()->where('code', $permissionCodes[0])->sole();
        $this->assertSame([
            'id' => $permission->getKey(),
            'code' => $permission->code,
            'name' => $permission->name,
            'description' => $permission->description,
            'module' => $permission->module,
        ], $described['permissions'][0]);
    }

    /**
     * @param  list<string>  $codes
     */
    protected function assertRoleCodesInOrder(TestResponse $response, array $codes): void
    {
        $response->assertOk();
        $this->assertSame($codes, $response->json('data.*.code'));
    }
}
