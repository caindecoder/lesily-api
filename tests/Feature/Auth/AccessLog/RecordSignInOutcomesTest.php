<?php

namespace Tests\Feature\Auth\AccessLog;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
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
        $this->signIn('not-the-password')->assertUnauthorized();

        // Then a failed sign-in is logged against them, with the refusal it answered
        $entry = $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::WrongPassword, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
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

    private function signIn(string $password, string $email = self::LOGIN_EMAIL): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function assertSeenOnce(AccessLogEntry $entry): void
    {
        $this->assertSame(1, $entry->occurrences);
        $this->assertSame(now()->toDateTimeString(), $entry->first_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $entry->last_seen_at->toDateTimeString());
        $this->assertNull($entry->company_id);
    }
}
