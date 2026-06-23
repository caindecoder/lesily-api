<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 2FA was disabled on the account (UC-1-007). A non-secret state/audit marker
 * (Design-Y): the disable interactor nulls the TOTP secret + hashed recovery codes on
 * the users row directly and the projector nulls two_factor_confirmed_at — none of that
 * material is carried here. Identity-scoped (no company metadata), mirrors User2FAEnabled.
 */
class User2FADisabled extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public CarbonInterface $disabledAt,
    ) {}
}
