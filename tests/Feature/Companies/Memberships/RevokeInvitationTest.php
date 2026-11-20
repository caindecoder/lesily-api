<?php

namespace Tests\Feature\Companies\Memberships;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserInvitationRevoked;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RevokeInvitationTest extends MembershipsTestCase
{
    use FreeTierCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
    }

    #[Test]
    public function when_invitation_unknown_then_revoke_refused(): void
    {
        // Given an id no invitation carries
        $unknownInvitationId = (string) Str::uuid7();

        // When
        $response = $this->revokeInvitation($this->admin, $this->company, $unknownInvitationId);

        // Then
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    #[DataProvider('provideDeniedRevokeRoles')]
    public function when_actor_lacks_permission_then_revoke_refused(string $roleCode): void
    {
        // Given
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $actor = $this->memberWithRole($roleCode);

        // When
        $response = $this->revokeInvitation($actor, $this->company, $invitation->getKey());

        // Then
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
        $this->assertInvitationNotRevoked($this->company, 'invitee@contoso.lk');
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
        $companyB = Company::factory()->create();
        $adminB = $this->aCompanyMember($companyB, 'admin');
        $this->aPendingInvitation($companyB, $adminB, 'invitee@contoso.lk');
        $invitationB = $this->theInvitation($companyB, 'invitee@contoso.lk');

        // When
        $response = $this->revokeInvitation($this->admin, $this->company, $invitationB->getKey());

        // Then
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
        $this->assertInvitationNotRevoked($companyB, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_revocation_reason_too_long_then_revoke_rejected(): void
    {
        // Given
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->reason = str_repeat('a', 256);

        // When
        $response = $this->revokeInvitationWithReason($this->admin, $this->company, $invitation->getKey());

        // Then
        $this->assertValidationRejected($response, 'reason', 'The reason field must not be greater than 255 characters.');
        $this->assertInvitationNotRevoked($this->company, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_invitation_already_revoked_then_revoke_refused(): void
    {
        // Given
        $this->aRevokedInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $stamps = [$invitation->revoked_at, $invitation->revoked_by_user_id, $invitation->revocation_reason];

        // When
        $response = $this->revokeInvitation($this->admin, $this->company, $invitation->getKey());

        // Then
        $this->assertRevokeRefusedAsNotPending($response);
        $this->assertRevocationStampsUnchanged($this->company, 'invitee@contoso.lk', $stamps);
    }

    #[Test]
    public function when_invitation_already_accepted_then_revoke_refused(): void
    {
        // Given
        $token = $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $this->acceptInvitation($token, $this->validNewUserBody())->assertCreated();
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');

        // When
        $response = $this->revokeInvitation($this->admin, $this->company, $invitation->getKey());

        // Then
        $this->assertRevokeRefusedAsNotPending($response);
        $this->assertMembershipActivated($this->company, 'invitee@contoso.lk');
        $this->assertNotAudited(AuditAction::InvitationRevoked);
    }

    #[Test]
    public function when_invitation_expired_then_revoke_refused(): void
    {
        // Given
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->travel(8)->days();

        // When
        $response = $this->revokeInvitation($this->admin, $this->company, $invitation->getKey());

        // Then
        $this->assertRevokeRefusedAsNotPending($response);
        $this->assertInvitationNotRevoked($this->company, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_the_revocation_projection_fails_then_the_invitation_stays_pending(): void
    {
        // Given a pending invitation and an invitations projection that fails mid-revoke
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->induceProjectorFailureFor(UserInvitationRevoked::class);

        // When the admin revokes it
        $response = $this->revokeInvitation($this->admin, $this->company, $invitation->getKey());

        // Then the revoke fails whole: no revocation stamps, no recorded event
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertInvitationNotRevoked($this->company, 'invitee@contoso.lk');
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_the_invitation_stays_pending(): void
    {
        // Given a pending invitation and an audit trail that refuses the revocation's row
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->failTheAuditTrail();

        // When the admin revokes it
        $response = $this->revokeInvitation($this->admin, $this->company, $invitation->getKey());

        // Then the revoke fails whole: no revocation stamps, no recorded event, no row
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertInvitationNotRevoked($this->company, 'invitee@contoso.lk');
        $this->assertNotAudited(AuditAction::InvitationRevoked);
    }

    #[Test]
    #[DataProvider('provideAllowedRevokeRoles')]
    public function when_authorized_role_revokes_pending_invitation_then_invitation_revoked(string $roleCode): void
    {
        // Given
        $actor = $this->memberWithRole($roleCode);
        $this->aPendingInvitation($this->company, $actor, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');

        // When
        $response = $this->revokeInvitation($actor, $this->company, $invitation->getKey());

        // Then
        $response->assertNoContent();
        $this->assertInvitationRevoked($this->company, 'invitee@contoso.lk', $actor);
    }

    #[Test]
    public function when_reason_given_then_revocation_reason_recorded(): void
    {
        // Given
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->reason = 'Sent to the wrong address';

        // When
        $response = $this->revokeInvitationWithReason($this->admin, $this->company, $invitation->getKey());

        // Then
        $response->assertNoContent();
        $this->assertRevocationReasonRecorded($this->company, 'invitee@contoso.lk', $this->reason);
    }

    #[Test]
    public function when_reason_is_at_the_length_limit_then_the_revoke_succeeds(): void
    {
        // Given a reason of exactly the 255-char maximum (the accepted boundary —
        // guards a max:254 off-by-one that would wrongly reject it)
        $this->aPendingInvitation($this->company, $this->admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->reason = str_repeat('a', 255);

        // When
        $response = $this->revokeInvitationWithReason($this->admin, $this->company, $invitation->getKey());

        // Then
        $response->assertNoContent();
        $this->assertRevocationReasonRecorded($this->company, 'invitee@contoso.lk', $this->reason);
    }

    #[Test]
    public function when_authorized_role_revokes_pending_invitation_then_the_audit_trail_records_the_revocation(): void
    {
        // Given an invitation pending as an HR officer and an admin acting from a known device
        $this->invite($this->admin, $this->company, ['email' => 'invitee@contoso.lk', 'roleCode' => 'hr_officer'])->assertCreated();
        $invitation = $this->theInvitation($this->company, 'invitee@contoso.lk');
        $this->fromADevice();

        // When the admin revokes it
        $response = $this->revokeInvitation($this->admin, $this->company, $invitation->getKey());

        // Then the audit trail records the revocation, by the admin
        $response->assertNoContent();
        $this->assertInvitationAudited($response, $invitation, AuditAction::InvitationRevoked);
        $this->assertActedBy(AuditAction::InvitationRevoked, $this->admin, 'admin');
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
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::InviteNotPending);
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
