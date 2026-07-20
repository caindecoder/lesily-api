<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

/**
 * @testclass-carveout: infra-primitive — the R7 soft-delete gate is
 * HTTP-unreachable (auth.company denies a removed member upstream), so this
 * seam is the only exercise of the evaluator's pivot filter.
 */
class PermissionEvaluatorTest extends FoundationTestCase
{
    use RefreshDatabase;
    use WithRolePermissionFixtures;

    #[Test]
    public function when_a_members_membership_is_soft_deleted_then_its_permissions_are_not_granted(): void
    {
        // Given a member whose role grants a permission, then removed (soft-deleted).
        // This is the R7 gate's own test — HTTP can't reach it: auth.company denies
        // a removed member upstream, so a probe never reaches the permission gate.
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $role = Role::factory()->code('admin')->withPermissions(['users.view'])->create();
        $this->attachMember($company, $user, $role);
        $this->softDeleteMembership($company, $user);

        // When the gate evaluates their permission
        $granted = app(PermissionEvaluator::class)->canUserDo($user, $company, 'users.view');

        // Then the soft-deleted membership resolves no role — nothing is granted
        $this->assertFalse($granted);
    }
}
