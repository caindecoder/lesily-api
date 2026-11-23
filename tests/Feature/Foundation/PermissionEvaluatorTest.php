<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Tests\Concerns\FreeTierCompany;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\RolesPermissions\Entities\Role;
use Tests\Feature\Companies\Memberships\CreatesMemberships;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

/**
 * @testclass-carveout: infra-primitive — the R7 soft-delete gate is
 * HTTP-unreachable (auth.company denies a removed member upstream), so this
 * seam is the only exercise of the evaluator's pivot filter.
 */
class PermissionEvaluatorTest extends FoundationTestCase
{
    use CreatesMemberships, FreeTierCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
    }

    #[Test]
    public function when_a_members_membership_is_soft_deleted_then_its_permissions_are_not_granted(): void
    {
        // Given a member of Contoso whose role grants users.view, then removed (soft-deleted).
        // This is the R7 gate's own test — HTTP can't reach it: auth.company denies
        // a removed member upstream, so a probe never reaches the permission gate.
        $member = User::factory()->create();
        $role = Role::factory()->withPermissions(['users.view'])->create();
        $this->attachMember($this->company, $member, $role);
        $this->softDeleteMembership($this->company, $member);

        // When the gate evaluates their permission
        $granted = app(PermissionEvaluator::class)->canUserDo($member, $this->company, 'users.view');

        // Then the soft-deleted membership resolves no role — nothing is granted
        $this->assertFalse($granted);
    }
}
