<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\UserPasswordChanged;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ChangePasswordTest extends AuthTestCase
{
    use InteractsWithQueuedReactors;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default)
        // masks the prod render path. The 401/422/429 rejections below must render their
        // mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
    }

    // --- Sad ---

    #[Test]
    public function when_no_session_is_provided_then_the_request_is_unauthenticated(): void
    {
        // Given no authenticated token

        // When a password change is attempted
        $response = $this->putJson('/api/v1/me/password', [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_the_current_password_is_incorrect_then_the_password_is_unchanged(): void
    {
        // Given an authenticated user signed in on two devices — the current one plus one other
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');

        // When they submit the wrong current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => 'not-my-password',
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then it is refused, the password is left intact, and NO session is revoked (Minimal Guarantee)
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::IncorrectPassword);
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertPasswordUnchanged($user, 2);
    }

    #[Test]
    public function when_the_new_password_matches_the_current_password_then_it_is_rejected(): void
    {
        // Given an authenticated user
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user);

        // When they submit a new password identical to their current one
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::LOGIN_PASSWORD,
        ]);

        // Then it is rejected as a validation error naming the new password, and nothing changes
        $this->assertValidationRejected($response, 'newPassword', 'The new password field and current password must be different.');
        $this->assertPasswordUnchanged($user, 1);
    }

    #[Test]
    #[DataProvider('provideInvalidChangePasswordPayloads')]
    public function when_the_new_password_is_invalid_then_it_is_rejected(array $payload, string $invalidField, string $expectedMessage): void
    {
        // Given an authenticated user
        $current = $this->aSessionFor($this->aRegisteredUser());

        // When they submit a change payload that breaks a field rule
        $response = $this->changePassword($current->plainTextToken, $payload);

        // Then the request is rejected as a validation error naming the offending field
        $this->assertValidationRejected($response, $invalidField, $expectedMessage);
    }

    #[Test]
    public function when_the_confirmation_reactor_fails_after_the_change_commits_then_the_change_and_the_revocation_survive(): void
    {
        // Given an authenticated user on two devices and a confirmation reactor that fails at delivery time
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $other = $this->aSessionFor($user, 'mobile');
        Queue::fake();
        $this->bindThrowingPasswordChangedReactor();

        // When they change their password and the deferred confirmation is drained
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);
        $response->assertNoContent();

        try {
            $this->runQueuedHandlersFor(UserPasswordChanged::class);
            $this->fail('Draining the queued reactor should surface the induced failure.');
        } catch (AssertionFailedError $guard) {
            throw $guard;
        } catch (RuntimeException $failure) {
            // Then the committed change and the revocation of the other device survived the delivery failure
            $this->assertOtherSessionsRevoked($response, $current->plainTextToken, $other->plainTextToken);
            $this->assertChangeSurvivedReactorFailure($user, $failure);
        }
    }

    #[Test]
    public function when_the_password_projection_fails_then_the_password_and_sessions_are_untouched(): void
    {
        // Given an authenticated user on two devices and a users projection that fails mid-change
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->induceProjectorFailureFor(UserPasswordChanged::class);

        // When they change their password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the change fails whole: the old password, both sessions, no recorded event
        $this->assertChangeRolledBack($response, $user);
    }

    // --- Edge ---

    #[Test]
    public function when_the_password_is_changed_then_the_other_sessions_are_revoked(): void
    {
        // Given an authenticated user signed in on two devices — the current one plus one other
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $other = $this->aSessionFor($user, 'mobile');

        // When they change their password from the current device with the correct current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the change succeeds, the other device no longer authenticates, and the current session survives
        $this->assertOtherSessionsRevoked($response, $current->plainTextToken, $other->plainTextToken);
        $this->assertSame(1, $user->tokens()->count(), 'Only the current session must remain after a change.');
    }

    #[Test]
    public function when_too_many_attempts_are_made_then_the_request_is_throttled(): void
    {
        // Given an authenticated user hammering the change endpoint (the password-guessing surface)
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;

        // When the shared per-IP auth-tier allowance is exhausted on wrong-password attempts
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->changePassword($token, ['currentPassword' => 'wrong-password', 'newPassword' => self::NEW_PASSWORD]);
        }
        $response = $this->changePassword($token, ['currentPassword' => 'wrong-password', 'newPassword' => self::NEW_PASSWORD]);

        // Then the over-limit attempt is throttled — the brute-force surface is bounded
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_no_idempotency_key_is_supplied_then_the_change_is_refused(): void
    {
        // Given an authenticated user submitting an otherwise-valid change
        $current = $this->aSessionFor($this->aRegisteredUser());

        // When the request carries no Idempotency-Key header
        $response = $this->withToken($current->plainTextToken)->putJson('/api/v1/me/password', [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the mutation is refused before any work — the route requires the key (idempotency-key:required)
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
    }

    #[Test]
    public function when_a_change_is_retried_with_the_same_idempotency_key_then_the_response_is_replayed(): void
    {
        // Given a user who has already changed their password under a fixed idempotency key (one other session)
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $key = (string) Str::uuid();
        $payload = ['currentPassword' => self::LOGIN_PASSWORD, 'newPassword' => self::NEW_PASSWORD];
        $this->changePassword($current->plainTextToken, $payload, $key)->assertNoContent();

        // When the identical request is retried with the SAME key (a client retry of the 204)
        $response = $this->changePassword($current->plainTextToken, $payload, $key);

        // Then the cached 204 is replayed, NOT re-executed — a re-run would now reject the stale
        // current password (401) and revoke a second time; replay leaves both unchanged
        $response->assertNoContent();
        $response->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, $user->tokens()->count(), 'A replayed retry must not revoke a second time.');
    }

    // --- Happy ---

    #[Test]
    public function when_the_current_password_is_correct_then_the_password_is_replaced(): void
    {
        // Given an authenticated user
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user);

        // When they change their password with the correct current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the change succeeds, the new password fully replaces the old one, and the write was event-sourced
        $response->assertNoContent();
        $this->assertPasswordReplaced($user, self::LOGIN_PASSWORD, self::NEW_PASSWORD);
        $this->assertPasswordChangeWasEventSourced(self::NEW_PASSWORD);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function provideInvalidChangePasswordPayloads(): array
    {
        return [
            'missing new password' => [['currentPassword' => self::LOGIN_PASSWORD], 'newPassword', 'The new password field is required.'],
            'new password under eight characters' => [['currentPassword' => self::LOGIN_PASSWORD, 'newPassword' => 'Sh0rt!'], 'newPassword', 'The new password field must be at least 8 characters.'],
            'new password over the length cap' => [['currentPassword' => self::LOGIN_PASSWORD, 'newPassword' => str_repeat('a', 256)], 'newPassword', 'The new password field must not be greater than 255 characters.'],
            'missing current password' => [['newPassword' => self::NEW_PASSWORD], 'currentPassword', 'The current password field is required.'],
        ];
    }

    /**
     * The change was refused: the original password still authenticates, the attempted new
     * password was NOT stored, and not a single session was revoked (the Minimal Guarantee —
     * goes RED if any write or revoke leaks past a refused change).
     */
    protected function assertPasswordUnchanged(User $user, int $expectedSessions): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue(Hash::check(self::LOGIN_PASSWORD, $stored->password), 'The original password must still authenticate.');
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $stored->password), 'A rejected new password must not be stored.');
        $this->assertSame($expectedSessions, $user->tokens()->count(), 'A rejected change must revoke no sessions.');
    }

    /**
     * The change failed after its event write and was rolled back whole: a
     * 500, the original password and both sessions intact, and no
     * UserPasswordChanged left on the stream.
     */
    protected function assertChangeRolledBack(TestResponse $response, User $user): void
    {
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertPasswordUnchanged($user, 2);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', UserPasswordChanged::class)->count());
    }

    /**
     * The change succeeded (204), revoked the user's OTHER sessions — the other device no longer
     * authenticates (forgetGuards forces a fresh DB re-check) — and kept the current session alive.
     * Goes RED if the revoke is removed (the other token would still authenticate).
     */
    protected function assertOtherSessionsRevoked(TestResponse $response, string $currentToken, string $otherToken): void
    {
        $response->assertNoContent();
        $this->assertUnauthenticated($this->probeWith($otherToken));
        $this->probeWith($currentToken)->assertOk();
    }

    /**
     * The change survived a delivery-time confirmation failure: the drained job surfaced the induced
     * failure (not some other error) and the NEW password authenticates — the reactor failed AFTER
     * the committed write, never in its place.
     */
    protected function assertChangeSurvivedReactorFailure(User $user, RuntimeException $failure): void
    {
        $this->assertSame('Induced delivery-time reactor failure.', $failure->getMessage());
        $this->assertPasswordReplaced($user, self::LOGIN_PASSWORD, self::NEW_PASSWORD);
    }

    /**
     * The password write was event-sourced, not a direct read-model save: exactly one
     * UserPasswordChanged was stored and it carries the NEW password's hash. Goes RED if the
     * interactor writes users.password directly — the behavioural tests cannot catch that (a direct
     * write yields identical read-model state), yet it would be silently reverted on a projection replay.
     */
    protected function assertPasswordChangeWasEventSourced(string $newPassword): void
    {
        $stored = LesilyStoredEvent::query()->where('event_class', UserPasswordChanged::class)->sole();
        $this->assertTrue(
            Hash::check($newPassword, $stored->event_properties['passwordHash']),
            'The stored UserPasswordChanged must carry the new password hash (event-sourced write, not a direct save).',
        );
    }
}
