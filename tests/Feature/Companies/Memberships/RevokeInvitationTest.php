<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;

class RevokeInvitationTest extends MembershipsTestCase
{
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
