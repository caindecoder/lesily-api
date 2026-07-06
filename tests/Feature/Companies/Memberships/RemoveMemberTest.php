<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class RemoveMemberTest extends MembershipsTestCase
{
    #[Test]
    #[DataProvider('provideDeniedRemoveRoles')]
    public function when_actor_lacks_remove_permission_then_removal_is_denied(string $roleCode): void
    {
        // Given a member to remove and an actor whose role lacks users.remove
        [$company] = $this->aCompanyWithMember();
        [, $membershipId] = $this->aMembership($company, 'employee');
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        // When the actor attempts to remove the member
        $response = $this->removeMember($actor, $company, $membershipId);

        // Then the request is refused and the member is untouched
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertMemberStillActive($membershipId);
    }

    /**
     * Roles WITHOUT users.remove — only Owner/Admin hold it (matrix D4/D10;
     * hr_officer may invite/revoke-invitation but NOT remove members).
     *
     * @return array<string, array{0: string}>
     */
    public static function provideDeniedRemoveRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }
}
