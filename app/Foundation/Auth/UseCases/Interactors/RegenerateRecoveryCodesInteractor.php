<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\Auth\UseCases\PasswordVerifier;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\Auth\Totp\RecoveryCodeGenerator;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\AuditTrail\UseCases\Audit\UserLevelAudit;
use App\Foundation\Auth\Exceptions\TwoFactorNotEnabledException;
use App\Foundation\Auth\UseCases\Commands\RegenerateRecoveryCodesCommand;

/**
 * Rotates a user's 2FA recovery codes (UC-1-137) after a password re-auth: generates a
 * fresh set, hashes them, and overwrites the stored codes — keeping the TOTP secret, so
 * no QR re-scan. Mirrors ConfirmTwoFactorInteractor's code-gen with DisableTwoFactorInteractor's
 * guards (Design-Y — the codes live in the users column; the User2FARecoveryCodesRegenerated
 * event is a non-secret marker). Returns the plaintext codes for one-time display.
 */
class RegenerateRecoveryCodesInteractor
{
    public function __construct(
        private readonly RecoveryCodeGenerator $recoveryCodes,
        private readonly PasswordVerifier $passwordVerifier,
        private readonly UserLevelAudit $audit,
    ) {}

    /**
     * @return list<string>
     */
    public function execute(User $user, RegenerateRecoveryCodesCommand $command, RequestOrigin $origin): array
    {
        if ($user->two_factor_confirmed_at === null) {
            throw new TwoFactorNotEnabledException;
        }

        $this->passwordVerifier->verify($user, $command->password);

        $plaintextCodes = $this->recoveryCodes->generate();

        // Serialize against a concurrent recovery-code verify under the SAME per-user lock
        // VerifyTwoFactorInteractor takes: without it, a verify-consume save built from a
        // pre-rotation read could commit after this overwrite and clobber the fresh set with
        // the stale set-minus-one. Verify re-reads inside the lock, so once this commits a
        // racing verify sees the rotated codes. Atomic: the direct overwrite and the marker
        // persist commit together; the TOTP secret + two_factor_confirmed_at are untouched
        // (Design-Y; the marker carries no secret material).
        Cache::lock("2fa-recovery-lock:{$user->getKey()}", seconds: 5)->block(seconds: 5, callback: function () use ($user, $plaintextCodes, $origin): void {
            DB::transaction(function () use ($user, $plaintextCodes, $origin): void {
                $user->two_factor_recovery_codes = array_map(Hash::make(...), $plaintextCodes);
                $user->save();

                UserAggregate::retrieve($user->getKey())->regenerateRecoveryCodes()->persist();

                $this->audit->record($user, AuditAction::RecoveryCodesRegenerated, $origin);
            });
        });

        return $plaintextCodes;
    }
}
