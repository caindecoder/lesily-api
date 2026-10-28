<?php

namespace Tests\Feature\Compliance\Privacy;

use Tests\TestCase;
use App\Models\User;
use App\Companies\Entities\Company;
use App\Foundation\RolesPermissions\Entities\Role;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

abstract class PrivacyTestCase extends TestCase
{
    use WithRolePermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        config(['app.debug' => false]);
    }

    /**
     * A company with $actor attached under the seeded $roleCode, holding the live policy versions.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode = 'owner'): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        return [$company, $actor];
    }

    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }
}
