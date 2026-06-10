<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\QueryException;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CompanyMembershipTest extends CompaniesTestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        // Given a company with one attached member
        $this->company = Company::factory()->create();
        $this->member = User::factory()->create();
        $this->company->users()->attach($this->member, [
            'role_id' => Role::factory()->code('member')->create()->getKey(),
        ]);
    }

    // Sad — the membership FK is RESTRICT: the lifecycle soft-deletes, so a hard
    // delete that would silently sweep live memberships away must be refused by the
    // database. Pins the restrictOnDelete clause, which sits outside every other
    // guard (no application seam issues hard deletes; database/ is outside Infection).
    #[Test]
    public function when_a_user_with_a_live_membership_is_hard_deleted_then_the_database_refuses(): void
    {
        // When the user is hard-deleted / Then the membership FK refuses
        $this->expectException(QueryException::class);

        $this->member->delete();
    }
}
