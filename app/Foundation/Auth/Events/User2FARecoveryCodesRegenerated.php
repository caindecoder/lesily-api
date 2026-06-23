<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A user's 2FA recovery codes were rotated (UC-1-137). A non-secret state/audit marker
 * (Design-Y): the new hashed codes are written to the users row directly by the regenerate
 * interactor and the TOTP secret is untouched — none of that material is carried here.
 * Identity-scoped (no company metadata), mirrors User2FARecoveryCodeUsed.
 */
class User2FARecoveryCodesRegenerated extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public CarbonInterface $regeneratedAt,
    ) {}
}
