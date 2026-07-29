<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T4 — the INCOMING owner is told the role has landed.
 *
 * Unconditional, which is what separates it from the emergency-contact prompt:
 * that one fires only when the contact is missing, so it cannot double as the
 * announcement. An owner who already had a contact would otherwise learn of the
 * transfer from nothing at all.
 */
class OwnershipReceivedNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'You are now the owner';
    }

    protected function body(): string
    {
        return "Ownership of {$this->companyName()} has been transferred to you.";
    }
}
