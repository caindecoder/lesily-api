<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Foundation\Auth\Totp\TotpVerifier;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;
use App\Foundation\Auth\Exceptions\TwoFactorVerificationFailedException;

/**
 * Completes a 2FA login (UC-1-005): resolves the opaque challenge, verifies the second
 * factor (TOTP or a single-use recovery code), and returns the authenticated user for
 * the controller to mint a token. Token-only — records no UserLoggedIn (mirrors
 * LogInInteractor). The recovery path is the only event-sourced branch
 * (User2FARecoveryCodeUsed); the secret + code hashes live in the users columns
 * (Design-Y), never the event store.
 */
class VerifyTwoFactorInteractor
{
    public function __construct(
        private readonly LoginChallengeStore $challenges,
        private readonly TotpVerifier $totp,
    ) {}

    public function execute(VerifyTwoFactorCommand $command): User
    {
        $challenge = $this->challenges->find($command->challengeId);

        if ($challenge === null) {
            throw new LoginChallengeNotFoundException;
        }

        $user = User::query()->findOrFail($challenge->userId);

        if ($this->totp->verify((string) $user->two_factor_secret, $command->code)) {
            $this->challenges->forget($command->challengeId);

            return $user;
        }

        if ($this->consumeMatchingRecoveryCode($user, $command->code)) {
            $this->challenges->forget($command->challengeId);

            return $user;
        }

        $this->challenges->recordFailure($command->challengeId);

        throw new TwoFactorVerificationFailedException;
    }

    /**
     * Match and consume a recovery code under a per-user lock so two concurrent verifies
     * presenting the SAME code cannot both spend it (the single-use invariant — the same
     * read-modify-write race class as recordFailure/OQ-1-119). The loser blocks on the
     * lock, re-reads inside it, finds the code already gone, and returns false.
     */
    private function consumeMatchingRecoveryCode(User $user, string $code): bool
    {
        $matched = false;

        Cache::lock("2fa-recovery-lock:{$user->getKey()}", seconds: 5)->block(seconds: 5, callback: function () use ($user, $code, &$matched): void {
            $user->refresh();

            foreach ($user->two_factor_recovery_codes ?? [] as $index => $hash) {
                if (Hash::check($code, $hash)) {
                    $this->consumeRecoveryCode($user, $index);
                    $matched = true;

                    return;
                }
            }
        });

        return $matched;
    }

    /**
     * Remove the matched recovery-code hash from the users row (single-use) and record
     * the non-secret marker, atomically (Design-Y — the credential write lives outside
     * the event store; the two commit together so a marker can never outrun its column).
     */
    private function consumeRecoveryCode(User $user, int $index): void
    {
        $remaining = $user->two_factor_recovery_codes ?? [];
        unset($remaining[$index]);
        $remaining = array_values($remaining);

        DB::transaction(function () use ($user, $remaining): void {
            $user->two_factor_recovery_codes = $remaining;
            $user->save();

            UserAggregate::retrieve($user->getKey())->useRecoveryCode(count($remaining))->persist();
        });
    }
}
