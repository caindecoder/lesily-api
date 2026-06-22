<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 2FA was enabled on the account (UC-1-006). A non-secret state/audit marker
 * (Design-Y): the TOTP secret + hashed recovery codes are written to the users row
 * directly by the interactor and are NEVER carried here. Identity-scoped (no company
 * metadata) — 2FA is a property of the person, not a tenant.
 */
class User2FAEnabled extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public CarbonInterface $confirmedAt,
    ) {}
}
