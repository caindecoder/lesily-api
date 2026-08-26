<?php

namespace Tests\Feature\Auth\TwoFactor;

use Tests\TestCase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Totp\LoginChallengeStore;

/**
 * @testclass-carveout: infra-primitive — cache-seam TTL/attempt/payload-shape
 * mechanics of the 2FA challenge port, provable only at the store seam; no HTTP
 * walk can pin expiry/decrement timing or a half-shaped cache entry deterministically.
 */
class LoginChallengeStoreTest extends TestCase
{
    #[Test]
    public function when_a_cached_challenge_payload_is_incomplete_then_it_does_not_resolve(): void
    {
        // Given a cache entry under a challenge key that carries only part of the payload
        $challengeId = (string) Str::uuid();
        Cache::put("2fa-challenge:{$challengeId}", ['userId' => (string) Str::uuid()]);

        // When the challenge is resolved
        $challenge = $this->store()->find($challengeId);

        // Then it does not resolve
        $this->assertNull($challenge, 'A half-shaped challenge payload must not resolve.');
    }

    #[Test]
    public function when_a_challenge_has_expired_then_it_no_longer_resolves(): void
    {
        // Given a challenge created now
        $challengeId = $this->store()->create((string) Str::uuid());

        // When its lifetime has elapsed
        $this->travel(LoginChallengeStore::TTL_MINUTES + 1)->minutes();

        // Then it no longer resolves
        $this->assertNull($this->store()->find($challengeId), 'An expired challenge must not resolve.');
    }

    #[Test]
    public function when_a_failure_is_recorded_for_an_unknown_challenge_then_it_is_a_no_op(): void
    {
        // Given an id with no live challenge
        $unknownId = (string) Str::uuid();

        // When a failure is recorded against it
        $this->store()->recordFailure($unknownId);

        // Then nothing errors and nothing resolves
        $this->assertNull($this->store()->find($unknownId), 'Recording a failure on an unknown challenge must be a safe no-op.');
    }

    #[Test]
    public function when_a_challenge_runs_out_of_attempts_then_it_is_deleted(): void
    {
        // Given a fresh challenge with the full attempt allowance
        $challengeId = $this->store()->create((string) Str::uuid());

        // When every attempt is spent
        for ($attempt = 0; $attempt < LoginChallengeStore::MAX_ATTEMPTS; $attempt++) {
            $this->store()->recordFailure($challengeId);
        }

        // Then the challenge is gone
        $this->assertNull($this->store()->find($challengeId), 'A challenge with no attempts left must be deleted.');
    }

    #[Test]
    public function when_a_challenge_is_failed_mid_window_then_it_still_expires_on_its_original_lifetime(): void
    {
        // Given a challenge created now
        $challengeId = $this->store()->create((string) Str::uuid());

        // When a failure is recorded partway through its lifetime, then the rest of it elapses
        $this->travel(LoginChallengeStore::TTL_MINUTES - 1)->minutes();
        $this->store()->recordFailure($challengeId);
        $this->travel(2)->minutes();

        // Then it has still expired — a failure must not slide the original window forward
        $this->assertNull($this->store()->find($challengeId), 'A failure must not extend the challenge lifetime.');
    }

    #[Test]
    public function when_a_failure_is_recorded_then_the_remaining_attempts_decrement(): void
    {
        // Given a fresh challenge with the full attempt allowance
        $challengeId = $this->store()->create((string) Str::uuid());

        // When one verify attempt fails
        $this->store()->recordFailure($challengeId);

        // Then the challenge still resolves with one fewer attempt
        $this->assertSame(
            LoginChallengeStore::MAX_ATTEMPTS - 1,
            $this->store()->find($challengeId)?->attemptsRemaining,
            'A recorded failure must decrement the remaining attempts.',
        );
    }

    #[Test]
    public function when_a_single_attempt_remains_then_the_challenge_still_resolves(): void
    {
        // Given a challenge that has spent all but its last attempt
        $challengeId = $this->store()->create((string) Str::uuid());
        for ($attempt = 0; $attempt < LoginChallengeStore::MAX_ATTEMPTS - 1; $attempt++) {
            $this->store()->recordFailure($challengeId);
        }

        // When it is resolved, then it survives with exactly one attempt left (not deleted early)
        $this->assertSame(1, $this->store()->find($challengeId)?->attemptsRemaining);
    }

    #[Test]
    public function when_a_challenge_is_created_then_it_resolves_to_its_user(): void
    {
        // Given a challenge opened for a user
        $userId = (string) Str::uuid();
        $challengeId = $this->store()->create($userId);

        // When the challenge is resolved
        $challenge = $this->store()->find($challengeId);

        // Then it resolves to that user with a full attempt allowance
        $this->assertNotNull($challenge, 'A created challenge must resolve.');
        $this->assertSame($userId, $challenge->userId);
        $this->assertSame(LoginChallengeStore::MAX_ATTEMPTS, $challenge->attemptsRemaining);
    }

    #[Test]
    public function when_a_challenge_is_forgotten_then_it_no_longer_resolves(): void
    {
        // Given a live challenge
        $challengeId = $this->store()->create((string) Str::uuid());

        // When it is forgotten
        $this->store()->forget($challengeId);

        // Then it no longer resolves
        $this->assertNull($this->store()->find($challengeId), 'A forgotten challenge must not resolve.');
    }

    protected function store(): LoginChallengeStore
    {
        return app(LoginChallengeStore::class);
    }
}
