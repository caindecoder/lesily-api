<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;

class ResendInvitationTest extends MembershipsTestCase
{
    #[Test]
    public function when_invitation_already_accepted_then_resend_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $this->acceptInvitation($token, $this->validNewUserBody())->assertStatus(201);
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');

        // When
        $response = $this->resendInvitation($admin, $company, $invitation->getKey());

        // Then
        $this->assertInvitationNotResent($response, ApiErrorCode::InviteNotPending, $invitation->token_hash);
    }

    /**
     * Resend the invitation $invitationId as $actor acting in $company's context.
     */
    protected function resendInvitation(User $actor, Company $company, string $invitationId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson(
            "/api/v1/invitations/{$invitationId}/resend",
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
     * The resend was refused with $code and changed nothing: the stored token
     * hash is untouched (no rotation) and no InvitationMail was queued beyond
     * the single arrange-time invite mail.
     */
    protected function assertInvitationNotResent(TestResponse $response, ApiErrorCode $code, string $oldTokenHash): void
    {
        $this->assertRefusedWith($response, $code);
        $this->assertDatabaseHas('invitations', ['token_hash' => $oldTokenHash]);
        Mail::assertQueued(InvitationMail::class, 1);
    }
}
