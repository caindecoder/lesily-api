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
use App\Companies\Memberships\Events\UserInvitationRevoked;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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
    public function when_invitation_already_revoked_then_revoke_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aRevokedInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $stamps = [$invitation->revoked_at, $invitation->revoked_by_user_id, $invitation->revocation_reason];

        // When
        $response = $this->revokeInvitation($admin, $company, $invitation->getKey());

        // Then
        $this->assertRevokeRefusedAsNotPending($response);
        $this->assertRevocationStampsUnchanged($company, 'invitee@contoso.lk', $stamps);
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

    #[Test]
    public function when_reason_given_then_revocation_reason_recorded(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->reason = 'Sent to the wrong address';

        // When
        $response = $this->revokeInvitationWithReason($admin, $company, $invitation->getKey());

        // Then
        $response->assertNoContent();
        $this->assertRevocationReasonRecorded($company, 'invitee@contoso.lk', $this->reason);
    }

    #[Test]
    public function when_reason_is_at_the_length_limit_then_the_revoke_succeeds(): void
    {
        // Given a reason of exactly the 255-char maximum (the accepted boundary —
        // guards a max:254 off-by-one that would wrongly reject it)
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->reason = str_repeat('a', 255);

        // When
        $response = $this->revokeInvitationWithReason($admin, $company, $invitation->getKey());

        // Then
        $response->assertNoContent();
        $this->assertRevocationReasonRecorded($company, 'invitee@contoso.lk', $this->reason);
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

    /**
     * The second revoke changed nothing: the revocation stamps are byte-equal to
     * the first-revoke capture, and only ONE UserInvitationRevoked event was ever
     * recorded on the stream (the guard rejected the repeat — no second event).
     *
     * @param  array{0: mixed, 1: ?string, 2: ?string}  $before
     */
    protected function assertRevocationStampsUnchanged(Company $company, string $email, array $before): void
    {
        $invitation = $this->theInvitation($company, $email);

        $this->assertSame($before[0]?->getTimestamp(), $invitation->revoked_at?->getTimestamp());
        $this->assertSame($before[1], $invitation->revoked_by_user_id);
        $this->assertSame($before[2], $invitation->revocation_reason);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationRevoked::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->count());
    }

    /**
     * The optional reason landed on BOTH sides of the projection: the
     * revocation_reason column AND the stored UserInvitationRevoked payload
     * carry $reason verbatim (column ↔ event agreement).
     */
    protected function assertRevocationReasonRecorded(Company $company, string $email, string $reason): void
    {
        $invitation = $this->theInvitation($company, $email);

        $this->assertSame($reason, $invitation->revocation_reason);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationRevoked::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->where('event_properties->reason', $reason)
            ->count());
    }
}
