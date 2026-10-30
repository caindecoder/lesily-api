<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Foundation\Auth\Totp\TotpVerifier;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\PendingSession;
use App\Foundation\Auth\UseCases\SignInFinisher;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\DeviceLimitGuard;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\GoogleSignInUnlinkedException;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;
use App\Foundation\Auth\Exceptions\TwoFactorVerificationFailedException;

/**
 * Completes a 2FA login (UC-1-005): resolves the opaque challenge, verifies the second
 * factor (TOTP or a single-use recovery code), then mints the session via SessionIssuer and
 * returns a SessionIssued (the mint moved out of the controller, session 090). Token-only —
 * records no UserLoggedIn (mirrors LogInInteractor). The recovery path is the only event-sourced branch
 * (User2FARecoveryCodeUsed); the secret + code hashes live in the users columns
 * (Design-Y), never the event store.
 */
class VerifyTwoFactorInteractor
{
    public function __construct(
        private readonly LoginChallengeStore $challenges,
        private readonly TotpVerifier $totp,
        private readonly SignInFinisher $finisher,
        private readonly DeviceLimitGuard $deviceLimitGuard,
        private readonly AccessLogRecorder $accessLog,
    ) {}

    public function execute(VerifyTwoFactorCommand $command): SessionIssued
    {
        $challenge = $this->challenges->find($command->challengeId);

        if ($challenge === null) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::ChallengeExpired, null);

            throw new LoginChallengeNotFoundException;
        }

        $user = User::query()->findOrFail($challenge->userId);

        // A pending first link meets an unlink made during the challenge (G12, GU3): refused before any factor is spent.
        if ($challenge->pendingGoogleSubjectId !== null && $user->google_unlinked_at !== null) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::GoogleUnlinked, $user);

            throw new GoogleSignInUnlinkedException;
        }

        // Enforce the device cap before consuming the second factor (UC-1-002 alt-5a) — a
        // capped account is rejected without burning the TOTP challenge or a one-time
        // recovery code; the challenge survives, re-verifiable once a device is freed.
        $this->deviceLimitGuard->enforce($user);

        if ($this->totp->verify((string) $user->two_factor_secret, $command->code)) {
            $this->challenges->forget($command->challengeId);

            return $this->finish(new PendingSession($user, $command->deviceName), $challenge->pendingGoogleSubjectId);
        }

        if ($this->consumeMatchingRecoveryCode($user, $command->code)) {
            $this->challenges->forget($command->challengeId);

            return $this->finish(new PendingSession($user, $command->deviceName, AccessEventReason::RecoveryCode), $challenge->pendingGoogleSubjectId);
        }

        $this->challenges->recordFailure($command->challengeId);
        $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::WrongTwoFactorCode, $user);

        throw new TwoFactorVerificationFailedException;
    }

    /** A first-time Google link pending in the challenge commits with the session (G13). */
    protected function finish(PendingSession $pending, ?string $googleSubjectToLink): SessionIssued
    {
        return DB::transaction(function () use ($pending, $googleSubjectToLink): SessionIssued {
            if ($googleSubjectToLink !== null) {
                UserAggregate::retrieve($pending->user->getKey())->linkGoogleOAuth($googleSubjectToLink)->persist();
            }

            return $this->finisher->finish($pending);
        });
    }

    /**
     * Match and consume a recovery code under a per-user lock so two concurrent verifies
     * presenting the SAME code cannot both spend it (the single-use invariant — the same
     * read-modify-write race class as recordFailure/OQ-1-119). The loser blocks on the
     * lock, re-reads inside it, finds the code already gone, and returns false.
     */
    protected function consumeMatchingRecoveryCode(User $user, string $code): bool
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
    protected function consumeRecoveryCode(User $user, int $index): void
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
