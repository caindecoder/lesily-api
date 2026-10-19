<?php

namespace Tests\Feature\Auth\AccessLog;

use ArrayObject;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Tests\Concerns\InducesDatabaseFailures;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AccessLog\Exceptions\AccessLogWriteFailedException;

class RecordSignInOutcomesTest extends AuthTestCase
{
    use InducesDatabaseFailures;

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
    public function when_the_log_write_fails_then_the_sign_in_still_answers_and_the_failure_is_reported(): void
    {
        // Given a registered user, and an access log that refuses every write
        $user = $this->aRegisteredUser();
        $this->failAccessLogWrites();
        $logged = $this->collectLogRecords();

        // When they sign in
        $response = $this->signIn(self::LOGIN_PASSWORD);

        // Then the sign-in answers as usual, nothing is logged, and the failure is reported as critical
        $response->assertOk();
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseCount('access_log', 0);
        $this->assertWriteFailureReported($logged);
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
        $this->assertNotContains('RECOVER-ONE', $entry->getAttributes());
    }

    private function signIn(string $password, string $email = self::LOGIN_EMAIL): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function failAccessLogWrites(): void
    {
        AccessLogEntry::creating(fn () => throw $this->syntheticQueryException([
            'sqlstate' => 'HY000',
            'code' => 2006,
            'message' => 'MySQL server has gone away',
        ]));
    }

    /** @return ArrayObject<int, MessageLogged> */
    private function collectLogRecords(): ArrayObject
    {
        $logged = new ArrayObject;
        Log::listen(fn (MessageLogged $record) => $logged->append($record));

        return $logged;
    }

    /** @param  ArrayObject<int, MessageLogged>  $logged */
    private function assertWriteFailureReported(ArrayObject $logged): void
    {
        $critical = array_values(array_filter($logged->getArrayCopy(), fn (MessageLogged $record): bool => $record->level === 'critical'));

        $this->assertCount(1, $critical);
        $this->assertInstanceOf(AccessLogWriteFailedException::class, $critical[0]->context['exception']);
        $this->assertInstanceOf(QueryException::class, $critical[0]->context['exception']->getPrevious());
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
