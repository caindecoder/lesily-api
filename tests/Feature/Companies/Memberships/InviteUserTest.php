<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

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

    /**
     * @param  array<string, string>  $body
     */
    #[Test]
    #[DataProvider('provideInvalidInviteInputs')]
    public function when_the_invite_input_is_invalid_then_the_invite_is_rejected(array $body, string $invalidField): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin submits an invite with invalid input
        $response = $this->invite($admin, $company, $body);

        // Then the input is refused as invalid and nothing is created
        $this->assertInviteValidationRejected($response, $invalidField);
        $this->assertNoInvitationCreated();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideInvalidInviteInputs(): array
    {
        return [
            'malformed email' => [['email' => 'not-an-email', 'roleCode' => 'employee'], 'email'],
            'empty email' => [['email' => '', 'roleCode' => 'employee'], 'email'],
            'missing role code' => [['email' => 'newuser@contoso.lk'], 'roleCode'],
            'unknown role code' => [['email' => 'newuser@contoso.lk', 'roleCode' => 'super_admin'], 'roleCode'],
        ];
    }

    #[Test]
    #[DataProvider('provideNonInvitingRoles')]
    public function when_the_actor_role_lacks_the_invite_permission_then_the_invite_is_denied(string $roleCode): void
    {
        // Given a member whose role does not carry users.invite
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they attempt to invite a new email
        $response = $this->invite($actor, $company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite is denied for the missing permission and nothing is created
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertNoInvitationCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonInvitingRoles(): array
    {
        return [
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
        ];
    }
}
