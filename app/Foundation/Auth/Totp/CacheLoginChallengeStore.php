<?php

namespace App\Foundation\Auth\Totp;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed LoginChallengeStore (Redis in prod, array in tests — keys prefixed
 * `2fa-challenge:`). A low-level plug-in to the login flow (E13 DIP). The challenge id
 * is an opaque UUIDv7; the user id + remaining-attempts ride the cache value, never the
 * client. The absolute expiry is stored so a recorded failure preserves the original
 * lifetime (a fixed 5-minute window from creation, not a sliding one).
 */
class CacheLoginChallengeStore implements LoginChallengeStore
{
    public function create(string $userId): string
    {
        $challengeId = (string) Str::uuid7();
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put($this->key($challengeId), [
            'userId' => $userId,
            'attemptsRemaining' => self::MAX_ATTEMPTS,
            'expiresAt' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return $challengeId;
    }

    public function find(string $challengeId): ?TwoFactorChallenge
    {
        $data = $this->read($challengeId);

        if ($data === null) {
            return null;
        }

        return new TwoFactorChallenge(
            userId: $data['userId'],
            attemptsRemaining: $data['attemptsRemaining'],
        );
    }

    public function recordFailure(string $challengeId): void
    {
        // Serialize the decrement under an atomic lock (OQ-1-119): a bare
        // read-modify-write lets two concurrent /2fa/verify requests both read N and
        // both write N-1, letting a parallelized attacker slip past the attempt cap.
        // The lock is held only for the microseconds of the decrement; block() waits
        // briefly for it so every concurrent failure is counted, not dropped.
        Cache::lock($this->lockKey($challengeId), seconds: 5)->block(seconds: 5, callback: function () use ($challengeId): void {
            $data = $this->read($challengeId);

            if ($data === null) {
                return;
            }

            $remaining = $data['attemptsRemaining'] - 1;

            if ($remaining <= 0) {
                $this->forget($challengeId);

                return;
            }

            Cache::put($this->key($challengeId), [
                'userId' => $data['userId'],
                'attemptsRemaining' => $remaining,
                'expiresAt' => $data['expiresAt'],
            ], CarbonImmutable::createFromTimestamp($data['expiresAt']));
        });
    }

    public function forget(string $challengeId): void
    {
        Cache::forget($this->key($challengeId));
    }

    /**
     * @return array{userId: string, attemptsRemaining: int, expiresAt: int}|null
     */
    private function read(string $challengeId): ?array
    {
        $data = Cache::get($this->key($challengeId));

        if (! is_array($data) || ! isset($data['userId'], $data['attemptsRemaining'], $data['expiresAt'])) {
            return null;
        }

        return [
            'userId' => (string) $data['userId'],
            'attemptsRemaining' => (int) $data['attemptsRemaining'],
            'expiresAt' => (int) $data['expiresAt'],
        ];
    }

    private function key(string $challengeId): string
    {
        return "2fa-challenge:{$challengeId}";
    }

    private function lockKey(string $challengeId): string
    {
        return "2fa-challenge-lock:{$challengeId}";
    }
}
