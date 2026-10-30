<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\UserGoogleOAuthUnlinked;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class UnlinkGoogleAccountTest extends AuthTestCase
{
    protected User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->alice = User::factory()
            ->withEmail(self::LOGIN_EMAIL)
            ->withPassword(self::LOGIN_PASSWORD)
            ->linkedToGoogle(self::GOOGLE_SUBJECT)
            ->create();
    }

    #[Test]
    public function when_not_signed_in_then_the_unlink_is_refused_as_unauthenticated(): void
    {
        // Given no session

        // When an unlink is attempted
        $response = $this->deleteJson('/api/v1/me/google', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard refuses it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_no_idempotency_key_is_sent_then_the_unlink_is_refused(): void
    {
        // Given Alice signed in
        $token = $this->aSessionFor($this->alice)->plainTextToken;

        // When the unlink carries no Idempotency-Key header
        $response = $this->withToken($token)->deleteJson('/api/v1/me/google', ['password' => self::LOGIN_PASSWORD]);

        // Then the mutation is refused before any work runs
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
        $this->assertStillLinked(1);
    }

    /** @param  array<string, mixed>  $payload */
    #[Test]
    #[DataProvider('provideInvalidUnlinkPayloads')]
    public function when_the_password_is_missing_or_malformed_then_the_unlink_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given Alice signed in
        $token = $this->aSessionFor($this->alice)->plainTextToken;

        // When she unlinks without a usable password
        $response = $this->unlinkGoogle($token, $payload);

        // Then the request is rejected as a validation error naming the password, and Google stays linked
        $this->assertValidationRejected($response, 'password', $expectedMessage);
        $this->assertStillLinked(1);
    }

    #[Test]
    public function when_google_is_not_linked_then_the_unlink_is_refused(): void
    {
        // Given Bob, never linked to Google, signed in on two devices
        $bob = $this->aRegisteredUser('bob@contoso.lk');
        $token = $this->aSessionFor($bob)->plainTextToken;
        $this->aSessionFor($bob, 'mobile');

        // When he unlinks Google with his correct password
        $response = $this->unlinkGoogle($token);

        // Then there is nothing to unlink, and nothing changes
        $this->assertNotLinkedRefusal($response, $bob);
    }

    #[Test]
    public function when_google_is_not_linked_then_the_password_is_never_checked(): void
    {
        // Given Bob, never linked to Google, signed in on two devices
        $bob = $this->aRegisteredUser('bob@contoso.lk');
        $token = $this->aSessionFor($bob)->plainTextToken;
        $this->aSessionFor($bob, 'mobile');

        // When he unlinks Google with a wrong password
        $response = $this->unlinkGoogle($token, ['password' => 'not-my-password']);

        // Then the not-linked refusal answers first and no failed re-check is logged
        $this->assertNotLinkedRefusal($response, $bob);
        $this->assertNoAccessLogged(AccessEvent::ReauthFailed);
    }

    #[Test]
    public function when_the_password_is_wrong_then_google_stays_linked(): void
    {
        // Given Alice signed in on two devices
        $token = $this->aSessionFor($this->alice)->plainTextToken;
        $this->aSessionFor($this->alice, 'mobile');

        // When she unlinks Google with a wrong password
        $response = $this->unlinkGoogle($token, ['password' => 'not-my-password']);

        // Then it is refused and Google stays linked
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::IncorrectPassword);
        $response->assertJsonPath('message', 'Incorrect password. Please try again.');
        $this->assertStillLinked(2);
    }

    #[Test]
    public function when_too_many_unlink_attempts_are_made_then_the_request_is_throttled(): void
    {
        // Given Alice spending the shared per-IP auth-tier allowance on wrong passwords
        $token = $this->aSessionFor($this->alice)->plainTextToken;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->unlinkGoogle($token, ['password' => 'not-my-password']);
        }

        // When she tries once more
        $response = $this->unlinkGoogle($token, ['password' => 'not-my-password']);

        // Then the over-limit attempt is throttled
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_the_projection_fails_then_the_unlink_is_rolled_back(): void
    {
        // Given Alice signed in on two devices, and a failing users projection
        $token = $this->aSessionFor($this->alice)->plainTextToken;
        $this->aSessionFor($this->alice, 'mobile');
        $this->induceProjectorFailureFor(UserGoogleOAuthUnlinked::class);

        // When she unlinks Google with her password
        $response = $this->unlinkGoogle($token);

        // Then the failure leaves no stored unlink and Google still linked
        $response->assertInternalServerError();
        $this->assertStillLinked(2);
    }

    #[Test]
    public function when_google_is_unlinked_then_the_users_other_sessions_are_revoked(): void
    {
        // Given Alice signed in on two devices
        $current = $this->aSessionFor($this->alice)->plainTextToken;
        $other = $this->aSessionFor($this->alice, 'mobile')->plainTextToken;

        // When she unlinks Google with her password on the current device
        $response = $this->unlinkGoogle($current);

        // Then the other device no longer authenticates and the current session survives
        $this->assertOtherSessionsRevoked($response, $current, $other);
    }

    #[Test]
    public function when_google_is_unlinked_with_the_password_then_the_link_is_removed_and_remembered(): void
    {
        // Given Alice signed in and linked to Google
        $this->freezeTime();
        $token = $this->aSessionFor($this->alice)->plainTextToken;

        // When she unlinks Google with her password
        $response = $this->unlinkGoogle($token);

        // Then the link is gone, the unlink is remembered, and the stored event is the record
        $this->assertUnlinked($response);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function provideInvalidUnlinkPayloads(): array
    {
        return [
            'missing' => [[], 'The password field is required.'],
            'not a string' => [['password' => 123], 'The password field must be a string.'],
        ];
    }

    protected function assertUnlinked(TestResponse $response): void
    {
        $response->assertNoContent();

        $stored = $this->alice->fresh();
        $this->assertNull($stored?->google_id, 'The unlink must clear the Google link.');
        $this->assertSame(now()->toAtomString(), $stored?->google_unlinked_at?->toAtomString(), 'The unlink must be remembered (G12).');

        $unlinked = LesilyStoredEvent::query()->where('event_class', UserGoogleOAuthUnlinked::class)->sole();
        $this->assertEquals(
            ['userId' => $this->alice->getKey(), 'unlinkedAt' => now()->toAtomString()],
            Arr::only($unlinked->event_properties, ['userId', 'unlinkedAt']),
        );
    }

    protected function assertNotLinkedRefusal(TestResponse $response, User $user): void
    {
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::GoogleNotLinked);
        $response->assertJsonPath('message', 'Google is not linked to this account.');
        $this->assertSame(2, $user->tokens()->count(), 'A not-linked refusal must end no session.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $user->getKey())->count());
        $this->assertNull($user->fresh()?->google_unlinked_at, 'A not-linked refusal must not mark an unlink.');
    }

    protected function assertStillLinked(int $sessions): void
    {
        $this->assertSame(self::GOOGLE_SUBJECT, $this->alice->fresh()?->google_id, 'A refused unlink must keep the link.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $this->alice->getKey())->count());
        $this->assertSame($sessions, $this->alice->tokens()->count(), 'A refused unlink must end no session.');
    }
}
