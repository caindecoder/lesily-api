<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T2 — the platform-admin queue is told a verified transfer is waiting on
 * execution. Deliberately NOT a requester notification: verification is a
 * platform-side step and the owner learns of it by reading UC-1-125.
 *
 * Retrofitted onto the discriminated base at s151 (ruling H3) — see the T1
 * sibling for why the generic notification could not express this.
 */
class OwnershipTransferVerifiedNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Ownership transfer ready to execute';
    }

    protected function body(): string
    {
        return "Identity is verified for the {$this->companyName()} ownership transfer; it is ready to execute.";
    }
}
