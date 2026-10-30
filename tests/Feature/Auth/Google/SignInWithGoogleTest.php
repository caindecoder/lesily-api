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
}
