<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class InviteUserTest extends MembershipsTestCase
{
    // --- Sad ---

    #[Test]
    public function when_the_email_is_already_an_active_member_then_the_invite_is_rejected(): void
    {
        // Given an admin of a company and a target who is already a member
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $target = User::factory()->withEmail('member@contoso.lk')->create();
        $this->attachMember($company, $target, $this->seededRole('employee'));

        // When the admin invites that member's email again
        $response = $this->invite($admin, $company, [
            'email' => 'member@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is refused as an existing member and nothing is created
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'ALREADY_MEMBER');
        $this->assertNoInvitationCreated();
    }

    #[Test]
    public function when_the_requested_role_is_owner_then_the_invite_is_rejected(): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin invites a new email with the owner role
        $response = $this->invite($admin, $company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'owner',
        ]);

        // Then the owner role is refused as not invitable and nothing is created
        $this->assertInviteValidationRejected($response, 'roleCode');
        $this->assertNoInvitationCreated();
    }
}
