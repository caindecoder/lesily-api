<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Companies\Memberships\Events\UserInvitationResent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ResendInvitationTest extends MembershipsTestCase
{
    #[Test]
    public function when_invitation_unknown_then_resend_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();

        // When
        $response = $this->resendInvitation($admin, $company, (string) Str::uuid7());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    #[DataProvider('provideDeniedResendRoles')]
    public function when_actor_lacks_permission_then_resend_refused(string $roleCode): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        // When
        $response = $this->resendInvitation($actor, $company, $invitation->getKey());

        // Then
        $this->assertInvitationNotResent($response, ApiErrorCode::RoleInsufficient, $invitation->token_hash);
    }

    /**
     * Roles WITHOUT users.invite — resend is denied for them (matrix D4/R1:
     * owner/admin/hr_officer hold it; auditor is read-only).
     *
     * @return array<string, array{0: string}>
     */
    public static function provideDeniedResendRoles(): array
    {
        return [
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    public function when_invitation_belongs_to_another_company_then_resend_hidden(): void
    {
        // Given
        [$companyA, $adminA] = $this->aCompanyWithMember();
        [$companyB, $adminB] = $this->aCompanyWithMember();
        $this->aPendingInvitation($companyB, $adminB, 'invitee@contoso.lk');
        $invitationB = $this->theInvitation($companyB, 'invitee@contoso.lk');

        // When
        $response = $this->resendInvitation($adminA, $companyA, $invitationB->getKey());

        // Then
        $this->assertInvitationNotResent($response, ApiErrorCode::ResourceNotFound, $invitationB->token_hash);
    }

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

    #[Test]
    public function when_invitation_expired_then_resend_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->travel(8)->days();

        // When
        $response = $this->resendInvitation($admin, $company, $invitation->getKey());

        // Then
        $this->assertInvitationNotResent($response, ApiErrorCode::InviteNotPending, $invitation->token_hash);
    }

    #[Test]
    public function when_invite_limit_exhausted_then_resend_throttled(): void
    {
        // Given — the arrange invite consumes 1 of the shared 20/hr budget (R5)
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->exhaustInviteLimit($admin, $company, 19);

        // When
        $response = $this->resendInvitation($admin, $company, $invitation->getKey());

        // Then
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_invitation_resent_then_previous_link_no_longer_accepts(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $oldToken = $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitation = $this->theInvitation($company, 'invitee@contoso.lk');
        $this->resendInvitation($admin, $company, $invitation->getKey())->assertOk();

        // When
        $response = $this->acceptInvitation($oldToken, $this->validNewUserBody());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::InviteInvalid);
        $this->assertInvitationNotConsumed($company, 'invitee@contoso.lk');
    }

    #[Test]
    #[DataProvider('provideAllowedResendRoles')]
    public function when_pending_invitation_resent_then_token_rotated_and_mail_requeued(string $roleCode): void
    {
        // Given
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $this->aPendingInvitation($company, $actor, 'invitee@contoso.lk');
        $before = $this->theInvitation($company, 'invitee@contoso.lk');

        // When
        $response = $this->resendInvitation($actor, $company, $before->getKey());

        // Then
        $response->assertOk()->assertJsonPath('data.id', $before->getKey());
        $this->assertTokenRotated($before);
        $this->assertResendMailQueued('invitee@contoso.lk');
        $this->assertInvitationResentEventRecorded($actor->getKey());
    }

    /**
     * Roles WITH users.invite (matrix D4/R1) — each may resend.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideAllowedResendRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
        ];
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

    /**
     * The stored token was ROTATED in place: still a single invitations row,
     * carrying a fresh sha256 differing from $before's, with the 7-day expiry
     * reset from now (±10s).
     */
    protected function assertTokenRotated(Invitation $before): void
    {
        $after = Invitation::query()->whereKey($before->getKey())->sole();

        $this->assertNotSame($before->token_hash, $after->token_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $after->token_hash);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $after->expires_at->getTimestamp(), 10);
        $this->assertDatabaseCount('invitations', 1);
    }

    /**
     * Exactly one NEW InvitationMail rode the resend (two queued total with the
     * arrange invite), addressed to $email, its plaintext distinct from the
     * first mail's and sha256-matching the ROTATED stored hash (D3) — the new
     * link works; the old one cannot.
     */
    protected function assertResendMailQueued(string $email): void
    {
        Mail::assertQueued(InvitationMail::class, 2);

        $plaintexts = [];
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($email, &$plaintexts): bool {
            $this->assertTrue($mail->hasTo($email));
            $plaintexts[] = $mail->plaintextToken;

            return true;
        });

        $rotatedHash = Invitation::query()->where('email', $email)->sole()->token_hash;
        $this->assertContains($rotatedHash, array_map(fn (string $token): string => hash('sha256', $token), $plaintexts));
        $this->assertCount(2, array_unique($plaintexts));
    }

    /**
     * The rotation was event-sourced (amendment R — a direct read-model write
     * must fail this): one UserInvitationResent stored event whose payload
     * matches the rotated row and $resentBy verbatim.
     */
    protected function assertInvitationResentEventRecorded(string $resentBy): void
    {
        $invitation = Invitation::query()->sole();

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationResent::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->where('event_properties->tokenHash', $invitation->token_hash)
            ->where('event_properties->resentBy', $resentBy)
            ->count());
    }
}
