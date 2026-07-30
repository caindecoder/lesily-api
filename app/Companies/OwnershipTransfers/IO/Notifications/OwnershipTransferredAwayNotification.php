<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T4 — the OUTGOING owner is told the transfer they requested has been
 * executed and they no longer own the company.
 *
 * Separate from the incoming owner's notice rather than one notification to
 * both: the two parties learn different facts, and UC-1-093's stakeholder list
 * gives them different interests — the outgoing owner cares that they retain
 * Admin access, the incoming one that the role has landed.
 *
 * This is their only signal of the moment. They raised the request, but a
 * platform admin chose when to execute it, and days may have passed.
 */
class OwnershipTransferredAwayNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Ownership transferred';
    }

    protected function body(): string
    {
        return "You are no longer the owner of {$this->companyName()}. You remain an admin there.";
    }
}
