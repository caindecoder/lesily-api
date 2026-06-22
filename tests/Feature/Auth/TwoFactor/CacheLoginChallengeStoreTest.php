<?php

namespace Tests\Feature\Auth\TwoFactor;

use Tests\TestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Totp\LoginChallengeStore;

class CacheLoginChallengeStoreTest extends TestCase
{
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
    public function when_a_challenge_is_created_then_it_resolves_to_its_user(): void
    {
        // Given a challenge opened for a user
        $userId = (string) Str::uuid();
        $challengeId = $this->store()->create($userId);

        // When the challenge is resolved, then forgotten
        $challenge = $this->store()->find($challengeId);
        $this->store()->forget($challengeId);

        // Then it resolved to that user with a full attempt allowance, and forgetting cleared it
        $this->assertSame($userId, $challenge?->userId, 'The challenge must resolve to its user.');
        $this->assertSame(LoginChallengeStore::MAX_ATTEMPTS, $challenge?->attemptsRemaining);
        $this->assertNull($this->store()->find($challengeId), 'A forgotten challenge must no longer resolve.');
    }

    protected function store(): LoginChallengeStore
    {
        return app(LoginChallengeStore::class);
    }
}
