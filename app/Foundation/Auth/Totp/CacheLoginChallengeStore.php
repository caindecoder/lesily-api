<?php

namespace App\Foundation\Auth\Totp;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed LoginChallengeStore (Redis in prod, array in tests — keys prefixed
 * `2fa-challenge:`). A low-level plug-in to the login flow (E13 DIP). The challenge id
 * is an opaque UUIDv7; the user id + remaining-attempts ride the cache value, never the
 * client.
 */
class CacheLoginChallengeStore implements LoginChallengeStore
{
    public function create(string $userId): string
    {
        $challengeId = (string) Str::uuid7();

        Cache::forever($this->key($challengeId), [
            'userId' => $userId,
            'attemptsRemaining' => self::MAX_ATTEMPTS,
        ]);

        return $challengeId;
    }

    public function find(string $challengeId): ?TwoFactorChallenge
    {
        $data = Cache::get($this->key($challengeId));

        if (! is_array($data) || ! isset($data['userId'], $data['attemptsRemaining'])) {
            return null;
        }

        return new TwoFactorChallenge(
            userId: (string) $data['userId'],
            attemptsRemaining: (int) $data['attemptsRemaining'],
        );
    }

    public function recordFailure(string $challengeId): void
    {
        throw new \RuntimeException('Not implemented');
    }

    public function forget(string $challengeId): void
    {
        Cache::forget($this->key($challengeId));
    }

    private function key(string $challengeId): string
    {
        return "2fa-challenge:{$challengeId}";
    }
}
