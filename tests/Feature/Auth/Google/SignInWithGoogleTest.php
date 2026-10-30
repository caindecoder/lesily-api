<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use Illuminate\Http\Response;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;

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

    /** @return array<string, array{string}> */
    public static function provideUnverifiedGoogleEmails(): array
    {
        return [
            'an account uses it' => [self::LOGIN_EMAIL],
            'no account uses it' => ['nimal@gmail.com'],
        ];
    }
}
