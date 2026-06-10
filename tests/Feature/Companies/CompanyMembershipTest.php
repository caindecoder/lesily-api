<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function when_a_company_with_a_live_membership_is_hard_deleted_then_the_database_refuses(): void
    {
        // When the company is hard-deleted / Then the membership FK refuses
        $this->expectException(QueryException::class);

        $this->company->delete();
    }

    // Sad — companies.owner_id is RESTRICT for the same reason: hard-deleting an
    // owner must never silently cascade their company away. The fixture company
    // deliberately has NO memberships — with members, the company_user FK chain
    // would throw anyway and mask a cascade regression on owner_id.
    #[Test]
    public function when_a_companys_owner_is_hard_deleted_then_the_database_refuses(): void
    {
        // Given a company whose owner has no other links
        $ownedCompany = Company::factory()->create();
        $owner = User::findOrFail($ownedCompany->owner_id);

        // When the owner is hard-deleted / Then the owner FK refuses
        $this->expectException(QueryException::class);

        $owner->delete();
    }

    // Happy — attach() routes through the CompanyUser pivot, which mints the
    // membership's char(36) UUID primary key (ERD-1-01, R-042).
    #[Test]
    public function when_a_member_is_attached_then_the_membership_id_is_a_uuid(): void
    {
        // When the membership row written by setUp's attach is read back
        $membershipId = DB::table('company_user')->value('id');

        // Then its primary key is an application-generated UUID
        $this->assertIsString($membershipId);
        $this->assertTrue(Str::isUuid($membershipId), 'company_user.id must be a char(36) UUID (ERD-1-01, R-042)');
    }
}
