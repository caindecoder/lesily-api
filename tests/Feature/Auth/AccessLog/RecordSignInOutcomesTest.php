<?php

namespace Tests\Feature\Auth\AccessLog;

use App\Models\User;
use RuntimeException;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\Totp\CacheLoginChallengeStore;
use App\Foundation\Auth\UseCases\Results\IssuedSession;

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
        $this->assertStringNotContainsString($email, (string) json_encode($entry->getAttributes()));
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
    public function when_the_device_limit_is_reached_at_verification_then_a_failed_sign_in_is_logged(): void
    {
        // Given a two-factor user at the device limit with an open challenge
        $user = $this->aTwoFactorUser();
        $this->atTheDeviceLimit($user);
        $this->fakeTotpVerifier(true);
        $challengeId = $this->openChallenge($user);

        // When they submit a valid code
        $response = $this->verify($challengeId, '123456');

        // Then a failed sign-in is logged for the device limit, on the verification route
        $response->assertForbidden();
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::DeviceLimit, $user);
        $this->assertSame('api/v1/auth/2fa/verify', $entry->route);
    }

    #[Test]
    public function when_the_session_cannot_be_issued_then_no_successful_sign_in_is_logged(): void
    {
        // Given a registered user, and a session issuer that fails
        $this->aRegisteredUser();
        $this->bindThrowingSessionIssuer();

        // When they sign in with the right password
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then the sign-in fails and no successful sign-in is logged
        $response->assertInternalServerError();
        $this->assertNoAccessLogged(AccessEvent::LoginSucceeded);
    }

    #[Test]
    public function when_the_session_cannot_be_issued_after_verification_then_no_successful_sign_in_is_logged(): void
    {
        // Given a two-factor user with an open challenge and a code that verifies, and a session issuer that fails
        $user = $this->aTwoFactorUser();
        $this->fakeTotpVerifier(true);
        $challengeId = $this->openChallenge($user);
        $this->bindThrowingSessionIssuer();

        // When they submit the code
        $response = $this->verify($challengeId, '123456');

        // Then the verification fails and no successful sign-in is logged
        $response->assertInternalServerError();
        $this->assertNoAccessLogged(AccessEvent::LoginSucceeded);
    }

    #[Test]
    public function when_the_challenge_cannot_be_opened_then_no_challenge_is_logged(): void
    {
        // Given a two-factor user, and a challenge store that fails
        $this->aTwoFactorUser();
        $this->app->bind(LoginChallengeStore::class, fn (): LoginChallengeStore => new class extends CacheLoginChallengeStore
        {
            public function create(string $userId): string
            {
                throw new RuntimeException('Induced challenge-store failure.');
            }
        });

        // When they sign in with the right password
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then the sign-in fails and no challenge is logged
        $response->assertInternalServerError();
        $this->assertNoAccessLogged(AccessEvent::LoginChallenged);
    }

    #[Test]
    public function when_the_log_write_fails_then_the_sign_in_still_answers_and_the_failure_is_reported(): void
    {
        // Given a registered user, and an access log that refuses every write
        $user = $this->aRegisteredUser();
        $this->failAccessLogWrites();
        $logged = $this->collectLogRecords();

        // When they sign in
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then the sign-in answers as usual and the failure is reported as critical
        $response->assertOk();
        $this->assertSame(1, $user->tokens()->count());
        $this->assertWriteFailureReported($logged, ['event' => 'login_succeeded', 'reason' => null, 'user_id' => $user->getKey()]);
    }

    #[Test]
    public function when_another_request_follows_a_sign_in_then_only_the_sign_in_is_logged(): void
    {
        // Given a user who has signed in
        $user = $this->aRegisteredUser();
        $this->signIn(self::LOGIN_PASSWORD);

        // When another request follows that decides no access outcome
        $response = $this->postJson('/api/v1/auth/login', []);

        // Then the sign-in is still the only row
        $response->assertUnprocessable();
        $this->assertAccessLogged(AccessEvent::LoginSucceeded, null, $user);
    }

    #[Test]
    public function when_the_user_agent_is_over_long_then_it_is_logged_cut_short(): void
    {
        // Given a registered user whose client sends a 300-character user agent
        $this->aRegisteredUser();
        $this->withHeader('User-Agent', str_repeat('a', 300));

        // When they sign in
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then the sign-in is logged with the user agent cut to the column's 255 characters
        $response->assertOk();
        $this->assertSame(str_repeat('a', 255), AccessLogEntry::query()->sole()->user_agent);
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
        $this->assertSeenOnceAsAPlatformRow($entry);
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

    #[Test]
    public function when_the_code_is_verified_then_a_successful_sign_in_is_logged(): void
    {
        // Given a two-factor user with an open challenge and a code that verifies
        $user = $this->aTwoFactorUser();
        $this->fakeTotpVerifier(true);
        $challengeId = $this->openChallenge($user);

        // When they submit it
        $response = $this->verify($challengeId, '123456');

        // Then a successful sign-in is logged, on the verification route
        $response->assertOk();
        $entry = $this->assertAccessLogged(AccessEvent::LoginSucceeded, null, $user);
        $this->assertSame('api/v1/auth/2fa/verify', $entry->route);
    }

    #[Test]
    public function when_a_recovery_code_is_used_then_a_successful_sign_in_is_logged_with_that_reason(): void
    {
        // Given a two-factor user holding a recovery code, with an open challenge
        $user = $this->aTwoFactorUser(['RECOVER-ONE']);
        $this->fakeTotpVerifier(false);
        $challengeId = $this->openChallenge($user);

        // When they submit the recovery code
        $response = $this->verify($challengeId, 'RECOVER-ONE');

        // Then a successful sign-in is logged as a recovery-code sign-in, and the code is kept nowhere
        $response->assertOk();
        $entry = $this->assertAccessLogged(AccessEvent::LoginSucceeded, AccessEventReason::RecoveryCode, $user);
        $this->assertStringNotContainsString('RECOVER-ONE', (string) json_encode($entry->getAttributes()));
    }

    protected function signIn(string $password, string $email = self::LOGIN_EMAIL): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    protected function bindThrowingSessionIssuer(): void
    {
        $this->app->bind(SessionIssuer::class, fn (): SessionIssuer => new class extends SessionIssuer
        {
            public function issue(User $user, string $deviceName): IssuedSession
            {
                throw new RuntimeException('Induced session-issue failure.');
            }
        });
    }

    protected function atTheDeviceLimit(User $user): void
    {
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');
    }
}
