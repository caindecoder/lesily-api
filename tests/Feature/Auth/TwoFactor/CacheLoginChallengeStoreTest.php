<?php

namespace Tests\Feature\Auth\TwoFactor;

use Tests\TestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Totp\LoginChallengeStore;

class CacheLoginChallengeStoreTest extends TestCase
{
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
