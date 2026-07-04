<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;

class RevokeInvitationTest extends MembershipsTestCase
{
    protected string $reason = '';

    #[Test]
    public function when_invitation_unknown_then_revoke_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();

        // When
        $response = $this->revokeInvitation($admin, $company, (string) Str::uuid7());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    #[DataProvider('provideDeniedRevokeRoles')]
    public function when_actor_lacks_permission_then_revoke_refused(string $roleCode): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        // When
        $response = $this->revokeInvitation($actor, $company, $invitation->getKey());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertInvitationNotRevoked($company, 'invitee@contoso.lk');
    }

    /**
     * Roles WITHOUT users.revoke-invitation — revoke is denied for them
     * (matrix D4/R1: owner/admin/hr_officer hold it; auditor is read-only).
     *
     * @return array<string, array{0: string}>
     */
    public static function provideDeniedRevokeRoles(): array
    {
        return [
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    public function when_invitation_belongs_to_another_company_then_revoke_hidden(): void
    {
        // Given
        [$companyA, $adminA] = $this->aCompanyWithMember();
        [$companyB, $adminB] = $this->aCompanyWithMember();
        $this->aPendingInvitation($companyB, $adminB, 'invitee@contoso.lk');
        $invitationB = $this->theInvitation($companyB, 'invitee@contoso.lk');

        // When
        $response = $this->revokeInvitation($adminA, $companyA, $invitationB->getKey());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertInvitationNotRevoked($companyB, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_revocation_reason_too_long_then_revoke_rejected(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->reason = str_repeat('a', 256);

        // When
        $response = $this->revokeInvitationWithReason($admin, $company, $invitation->getKey());

        // Then
        $this->assertValidationRejected($response, 'reason');
        $this->assertInvitationNotRevoked($company, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_invitation_already_accepted_then_revoke_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $this->acceptInvitation($token, $this->validNewUserBody())->assertStatus(201);
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');

        // When
        $response = $this->revokeInvitation($admin, $company, $invitation->getKey());

        // Then
        $this->assertRevokeRefusedAsNotPending($response);
        $this->assertMembershipActivated($company, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_invitation_expired_then_revoke_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->travel(8)->days();

        // When
        $response = $this->revokeInvitation($admin, $company, $invitation->getKey());

        // Then
        $this->assertRevokeRefusedAsNotPending($response);
        $this->assertInvitationNotRevoked($company, 'invitee@contoso.lk');
    }

    #[Test]
    #[DataProvider('provideAllowedRevokeRoles')]
    public function when_authorized_role_revokes_pending_invitation_then_invitation_revoked(string $roleCode): void
    {
        // Given
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $this->aPendingInvitation($company, $actor, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');

        // When
        $response = $this->revokeInvitation($actor, $company, $invitation->getKey());

        // Then
        $response->assertNoContent();
        $this->assertInvitationRevoked($company, 'invitee@contoso.lk', $actor);
    }

    /**
     * Roles WITH users.revoke-invitation (matrix D4/R1) — each may revoke.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideAllowedRevokeRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
        ];
    }

    /**
     * Revoke the invitation $invitationId as $actor acting in $company's context.
     */
    protected function revokeInvitation(User $actor, Company $company, string $invitationId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->deleteJson(
            "/api/v1/invitations/{$invitationId}",
            [],
            ['X-Company-Context' => $company->getKey()]
        );
    }

    /**
     * Revoke with the shared $this->reason riding as the optional request body.
     */
    protected function revokeInvitationWithReason(User $actor, Company $company, string $invitationId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->deleteJson(
            "/api/v1/invitations/{$invitationId}",
            ['reason' => $this->reason],
            ['X-Company-Context' => $company->getKey()]
        );
    }

    protected function theInvitation(Company $company, string $email): Invitation
    {
        return Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();
    }

    /**
     * The revoke was refused as not-pending with the collapsed admin-facing
     * message the UC pins (R-B s111).
     */
    protected function assertRevokeRefusedAsNotPending(TestResponse $response): void
    {
        $this->assertRefusedWith($response, ApiErrorCode::InviteNotPending);
        $response->assertJsonPath('message', 'This invitation is no longer pending.');
    }
}
