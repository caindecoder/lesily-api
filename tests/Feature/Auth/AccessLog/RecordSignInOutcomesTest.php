<?php

namespace Tests\Feature\Auth\AccessLog;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;

class RecordSignInOutcomesTest extends AuthTestCase
{
    #[Test]
    public function when_the_password_is_wrong_then_a_failed_sign_in_is_logged(): void
    {
        // Given a registered user
        $user = $this->aRegisteredUser();

        // When they sign in with the wrong password
        $response = $this->signIn('not-the-password');

        // Then a failed sign-in is logged against them, with the refusal it answered
        $response->assertUnauthorized();
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::WrongPassword, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    public function when_the_account_is_unknown_then_a_failed_sign_in_is_logged_without_the_email(): void
    {
        // Given no account for the email a guest types
        $email = 'nobody@contoso.lk';

        // When the guest signs in with it
        $response = $this->signIn('any-password', $email);

        // Then a failed sign-in is logged against no user, and the typed email is kept nowhere
        $response->assertUnauthorized();
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::UnknownAccount, null);
        $this->assertNotContains($email, $entry->getAttributes());
    }

    #[Test]
    public function when_the_device_limit_is_reached_at_sign_in_then_a_failed_sign_in_is_logged(): void
    {
        // Given a user on a device-capped plan already holding three sessions
        $user = $this->aRegisteredUser();
        $this->atTheDeviceLimit($user);

        // When they sign in from a fourth device
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then a failed sign-in is logged for the device limit
        $response->assertForbidden();
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::DeviceLimit, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_FORBIDDEN, ApiErrorCode::DeviceLimitReached);
    }

    #[Test]
    public function when_the_second_factor_code_is_wrong_then_a_failed_sign_in_is_logged(): void
    {
        // Given a two-factor user with an open challenge and a code that does not verify
        $user = $this->aTwoFactorUser();
        $this->fakeTotpVerifier(false);
        $challengeId = $this->openChallenge($user);

        // When they submit it
        $response = $this->verify($challengeId, '000000');

        // Then a failed sign-in is logged for the wrong code
        $response->assertUnauthorized();
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::WrongTwoFactorCode, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_UNAUTHORIZED, ApiErrorCode::TwoFactorVerificationFailed);
    }

    #[Test]
    public function when_the_challenge_is_gone_then_a_failed_sign_in_is_logged_without_a_user(): void
    {
        // Given a challenge id that no longer resolves
        $challengeId = 'a-challenge-that-has-expired';

        // When a code is submitted against it
        $response = $this->verify($challengeId, '000000');

        // Then a failed sign-in is logged against no user
        $response->assertNotFound();
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::ChallengeExpired, null);
        $this->assertAnsweredAs($entry, Response::HTTP_NOT_FOUND, ApiErrorCode::TwoFactorChallengeNotFound);
    }

    #[Test]
    public function when_a_user_without_two_factor_signs_in_then_a_successful_sign_in_is_logged(): void
    {
        // Given a registered user without two-factor authentication
        $this->freezeTime();
        $user = $this->aRegisteredUser();

        // When they sign in
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then one successful sign-in is logged with the request it answered
        $response->assertOk();
        $entry = $this->assertAccessLogged(AccessEvent::LoginSucceeded, null, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_OK, null);
        $this->assertLoggedRequest($response, $entry, 'POST api/v1/auth/login');
        $this->assertSeenOnce($entry);
    }

    #[Test]
    public function when_a_two_factor_user_gives_the_right_password_then_a_challenge_is_logged(): void
    {
        // Given a user with two-factor authentication enabled
        $user = $this->aTwoFactorUser();

        // When they sign in with the right password
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then a challenge is logged, not a completed sign-in
        $response->assertAccepted();
        $entry = $this->assertAccessLogged(AccessEvent::LoginChallenged, null, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_ACCEPTED, null);
    }

    private function signIn(string $password, string $email = self::LOGIN_EMAIL): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function atTheDeviceLimit(User $user): void
    {
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');
    }

    private function assertSeenOnce(AccessLogEntry $entry): void
    {
        $this->assertSame(1, $entry->occurrences);
        $this->assertSame(now()->toDateTimeString(), $entry->first_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $entry->last_seen_at->toDateTimeString());
        $this->assertNull($entry->company_id);
    }
}
