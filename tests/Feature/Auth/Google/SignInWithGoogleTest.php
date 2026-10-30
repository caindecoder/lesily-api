<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use Illuminate\Http\Response;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class SignInWithGoogleTest extends AuthTestCase
{
    protected User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->alice = User::factory()->withEmail(self::LOGIN_EMAIL)->linkedToGoogle(self::GOOGLE_SUBJECT)->create();
    }

    #[Test]
    public function when_the_google_token_is_invalid_then_sign_in_is_refused_and_nothing_changes(): void
    {
        // Given Alice linked to Google on a Starter company, and a Google token that fails verification
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(null);

        // When a Google sign-in arrives with that token
        $response = $this->signInWithGoogle();

        // Then it is refused, nothing is signed in, and the failure is logged
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::GoogleTokenInvalid);
        $this->assertNothingSignedIn($this->alice);
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::InvalidGoogleToken, null);
        $this->assertAnsweredAs($entry, Response::HTTP_UNAUTHORIZED, ApiErrorCode::GoogleTokenInvalid);
    }

    /** @param  array<string, mixed>  $payload */
    #[Test]
    #[DataProvider('provideMalformedIdTokens')]
    public function when_the_id_token_is_malformed_then_the_request_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given no well-formed Google ID token in the request

        // When a Google sign-in arrives
        $response = $this->postJson('/api/v1/auth/google', $payload);

        // Then it is rejected with the field's validation message
        $this->assertValidationRejected($response, 'idToken', $expectedMessage);
    }

    #[Test]
    public function when_sign_in_is_attempted_too_often_then_it_is_throttled(): void
    {
        // Given the auth-tier allowance for this address already spent
        $this->exhaustAuthLimit(10, '/api/v1/auth/google');

        // When one more Google sign-in arrives
        $response = $this->signInWithGoogle();

        // Then it is throttled
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_no_account_uses_the_verified_google_email_then_signup_is_offered_prefilled_and_nothing_is_created(): void
    {
        // Given a verified Google account whose email no Lesily account uses
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('a-new-subject', 'nimal@gmail.com', true, 'Nimal Silva'));
        $users = User::query()->count();

        // When a Google sign-in arrives for it
        $response = $this->signInWithGoogle();

        // Then signup is offered pre-filled from Google, nothing is created, and the failure is logged
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::GoogleAccountNotRegistered);
        $googleEmail = $response->json('google_email');
        $googleName = $response->json('google_name');
        $this->assertIsString($googleEmail);
        $this->assertIsString($googleName);
        $this->assertSame(['nimal@gmail.com', 'Nimal Silva'], [$googleEmail, $googleName]);
        $this->assertSame($users, User::query()->count());
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::UnknownAccount, null);
        $this->assertAnsweredAs($entry, Response::HTTP_NOT_FOUND, ApiErrorCode::GoogleAccountNotRegistered);
    }

    #[Test]
    #[DataProvider('provideUnverifiedGoogleEmails')]
    public function when_google_has_not_verified_the_email_then_no_account_is_matched_by_it(string $email): void
    {
        // Given a Google account not linked to Lesily whose email Google has not verified
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('a-new-subject', $email, false, 'Someone'));
        $users = User::query()->count();

        // When a Google sign-in arrives for it
        $response = $this->signInWithGoogle();

        // Then it is refused the same way whether or not an account uses the email, and nothing changes
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::EmailNotVerified);
        $response->assertJsonPath('message', 'Verify your email, or sign in with your password.');
        $response->assertJsonMissingPath('google_email');
        $this->assertNothingSignedIn($this->alice);
        $this->assertSame($users, User::query()->count());
        $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::EmailNotVerified, null);
    }

    #[Test]
    public function when_the_account_is_linked_to_a_different_google_account_then_sign_in_is_refused(): void
    {
        // Given Alice linked to one Google account, on a Starter company, and a token from another Google account with her email
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('another-google-subject', self::LOGIN_EMAIL, true, 'Alice Perera'));

        // When a Google sign-in arrives with that token
        $response = $this->signInWithGoogle();

        // Then it is refused, Alice keeps her own link, and the mismatch is logged
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::GoogleAlreadyLinked);
        $response->assertJsonPath('message', 'This account is linked to a different Google account. Sign in with your password.');
        $this->assertNothingSignedIn($this->alice);
        $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::GoogleAccountMismatch, $this->alice);
    }

    #[Test]
    #[DataProvider('provideUsersWithoutGoogleSignIn')]
    public function when_no_company_can_use_google_sign_in_then_sign_in_is_refused_with_the_upgrade_plan(string $memberships): void
    {
        // Given Alice linked to Google with no live company whose plan grants Google sign-in
        $this->withoutGoogleSignIn($this->alice, $memberships);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice signs in with Google
        $response = $this->signInWithGoogle();

        // Then the upgrade refusal names Starter, nothing is signed in, and the refusal is logged
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Starter');
        $this->assertNothingSignedIn($this->alice);
        $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::PlanNotEligible, $this->alice);
    }

    #[Test]
    public function when_the_lesily_email_is_unverified_then_the_first_google_sign_in_is_refused_and_nothing_is_linked(): void
    {
        // Given Bob, not linked to Google, with an unverified Lesily email on a Starter company
        $bob = User::factory()->withEmail('bob@contoso.lk')->unverified()->create();
        $this->memberOfCompanyOnPlan($bob, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('bobs-google-subject', 'bob@contoso.lk', true, 'Bob'));

        // When Bob signs in with a Google account for that email
        $response = $this->signInWithGoogle();

        // Then it is refused, nothing is linked, and the refusal is logged
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::EmailNotVerified);
        $this->assertNothingSignedIn($bob);
        $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::EmailNotVerified, $bob);
    }

    #[Test]
    public function when_the_user_is_at_the_device_limit_then_no_session_is_issued(): void
    {
        // Given Alice linked to Google on a Pro company, already signed in on three devices
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Pro);
        $this->aSessionFor($this->alice);
        $this->aSessionFor($this->alice);
        $this->aSessionFor($this->alice);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice signs in with Google on a fourth device
        $response = $this->signInWithGoogle();

        // Then the device cap refuses it and the refusal is logged
        $this->assertDeviceLimitRejected($response, $this->alice, 3);
        $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::DeviceLimit, $this->alice);
    }

    #[Test]
    public function when_a_two_factor_challenge_is_abandoned_then_nothing_is_linked(): void
    {
        // Given Dave, enrolled in 2FA and not linked, challenged by a first Google sign-in
        $dave = $this->aTwoFactorUnlinkedStarterUser();
        $challengeId = $this->signInWithGoogle()->json('data.challenge_id');
        $this->assertIsString($challengeId);

        // When the challenge expires before the second factor arrives
        $this->travel(LoginChallengeStore::TTL_MINUTES + 1)->minutes();
        $this->fakeTotpVerifier(true);
        $response = $this->verify($challengeId, '123456');

        // Then the verify is refused and Google is never linked
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::TwoFactorChallengeNotFound);
        $this->assertNull($dave->fresh()?->google_id);
    }

    #[Test]
    public function when_a_google_linked_user_with_an_unverified_email_signs_in_then_a_session_is_issued(): void
    {
        // Given Bob linked to Google with an unverified Lesily email, on a Starter company
        $bob = User::factory()->withEmail('bob@contoso.lk')->unverified()->linkedToGoogle('bobs-google-subject')->create();
        $this->memberOfCompanyOnPlan($bob, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('bobs-google-subject', 'bob@contoso.lk', true, 'Bob'));

        // When Bob signs in with that Google account
        $response = $this->signInWithGoogle();

        // Then a session is issued: the email check guards a first link, not a linked account
        $this->assertSignedInWithoutLink($response, $bob);
    }

    #[Test]
    public function when_the_session_cannot_be_issued_then_the_first_link_is_not_recorded(): void
    {
        // Given Carol, verified and not linked, on a Starter company, and a session mint that fails
        $carol = $this->aVerifiedUnlinkedStarterUser();
        $this->bindThrowingSessionIssuer();

        // When Carol signs in with Google for the first time
        $response = $this->signInWithGoogle();

        // Then the sign-in fails and the link is not recorded either
        $response->assertInternalServerError();
        $this->assertNull($carol->fresh()?->google_id);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', UserGoogleOAuthLinked::class)->count());
    }

    #[Test]
    public function when_a_wrong_second_factor_is_entered_then_the_pending_link_survives_to_the_right_one(): void
    {
        // Given Dave challenged by a first Google sign-in, who then enters a wrong code
        $this->freezeTime();
        $dave = $this->aTwoFactorUnlinkedStarterUser();
        $challengeId = $this->signInWithGoogle()->json('data.challenge_id');
        $this->assertIsString($challengeId);
        $this->fakeTotpVerifier(false);
        $this->verify($challengeId, '000000')->assertUnauthorized();

        // When Dave enters the right code
        $this->fakeTotpVerifier(true);
        $response = $this->verify($challengeId, '123456');

        // Then he is signed in and Google is linked with that session
        $response->assertOk();
        $this->assertGoogleLinkedWith($dave, 'daves-google-subject');
    }

    #[Test]
    public function when_an_already_linked_two_factor_user_passes_the_challenge_then_no_second_link_is_recorded(): void
    {
        // Given Erin, linked to Google and enrolled in 2FA, challenged by a Google sign-in
        $erin = User::factory()->withEmail('erin@contoso.lk')->withTwoFactor()->linkedToGoogle('erins-google-subject')->create();
        $this->memberOfCompanyOnPlan($erin, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('erins-google-subject', 'erin@contoso.lk', true, 'Erin'));
        $challengeId = $this->signInWithGoogle()->json('data.challenge_id');
        $this->assertIsString($challengeId);

        // When Erin passes the second factor
        $this->fakeTotpVerifier(true);
        $response = $this->verify($challengeId, '123456');

        // Then she is signed in and no link is recorded
        $response->assertOk();
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', UserGoogleOAuthLinked::class)->count());
    }

    #[Test]
    public function when_a_google_linked_user_signs_in_then_a_session_is_issued(): void
    {
        // Given Alice linked to Google on a Starter company, holding a valid token for that account
        $this->freezeTime();
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice signs in with Google
        $response = $this->signInWithGoogle();

        // Then a session is issued, her last sign-in is stamped and the success is logged
        $this->assertSignedInWithoutLink($response, $this->alice);
        $this->assertLastLoggedAtStamped($this->alice);
        $this->assertAccessLogged(AccessEvent::LoginSucceeded, null, $this->alice);
    }

    #[Test]
    public function when_one_company_among_free_ones_can_use_google_sign_in_then_sign_in_succeeds(): void
    {
        // Given Alice linked to Google, a member of a Free company and of a Starter company
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Free);
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice signs in with Google
        $response = $this->signInWithGoogle();

        // Then a session is issued: one Starter company is enough
        $this->assertSignedInWithoutLink($response, $this->alice);
    }

    #[Test]
    public function when_a_verified_user_signs_in_with_google_for_the_first_time_then_google_is_linked_with_the_session(): void
    {
        // Given Carol, verified and not linked, on a Starter company, holding her own verified Google account
        $this->freezeTime();
        Notification::fake();
        $carol = $this->aVerifiedUnlinkedStarterUser();

        // When Carol signs in with Google for the first time
        $response = $this->signInWithGoogle();

        // Then a session is issued, Google is linked with it, Carol is told, and the success is logged
        $response->assertOk();
        $this->assertTokenNamed($carol, 'Pixel 8');
        $this->assertGoogleLinkedWith($carol, 'carols-google-subject');
        Notification::assertSentTo($carol, SystemNotification::class, fn (SystemNotification $notification): bool => $notification->type === NotificationType::GoogleAccountLinked);
        $this->assertAccessLogged(AccessEvent::LoginSucceeded, null, $carol);
    }

    #[Test]
    public function when_a_two_factor_user_signs_in_with_google_then_a_challenge_is_issued_and_nothing_is_linked_yet(): void
    {
        // Given Dave, enrolled in 2FA and not linked, on a Starter company
        $dave = $this->aTwoFactorUnlinkedStarterUser();

        // When Dave signs in with Google for the first time
        $response = $this->signInWithGoogle();

        // Then the login challenge is returned, nothing is linked yet, and the challenge is logged
        $response->assertAccepted();
        $challengeId = $response->json('data.challenge_id');
        $this->assertIsString($challengeId);
        $this->assertNothingSignedIn($dave);
        $this->assertAccessLogged(AccessEvent::LoginChallenged, null, $dave);
    }

    #[Test]
    public function when_the_second_factor_passes_then_google_is_linked_with_the_session(): void
    {
        // Given Dave challenged by a first Google sign-in
        $this->freezeTime();
        $dave = $this->aTwoFactorUnlinkedStarterUser();
        $challengeId = $this->signInWithGoogle()->json('data.challenge_id');
        $this->assertIsString($challengeId);

        // When Dave passes the second factor
        $this->fakeTotpVerifier(true);
        $response = $this->verify($challengeId, '123456');

        // Then he is signed in and Google is linked with that session
        $response->assertOk();
        $this->assertSame(1, $dave->tokens()->count());
        $this->assertGoogleLinkedWith($dave, 'daves-google-subject');
    }

    /** @return array<string, array{string}> */
    public static function provideUnverifiedGoogleEmails(): array
    {
        return [
            'an account uses it' => [self::LOGIN_EMAIL],
            'no account uses it' => ['nimal@gmail.com'],
        ];
    }

    protected function aTwoFactorUnlinkedStarterUser(): User
    {
        $dave = User::factory()->withEmail('dave@contoso.lk')->withTwoFactor()->create();
        $this->memberOfCompanyOnPlan($dave, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('daves-google-subject', 'dave@contoso.lk', true, 'Dave'));

        return $dave;
    }

    protected function aVerifiedUnlinkedStarterUser(): User
    {
        $carol = User::factory()->withEmail('carol@contoso.lk')->create();
        $this->memberOfCompanyOnPlan($carol, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(new GoogleIdentity('carols-google-subject', 'carol@contoso.lk', true, 'Carol'));

        return $carol;
    }
}
