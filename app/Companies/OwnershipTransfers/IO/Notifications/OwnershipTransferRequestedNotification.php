<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T1 — the support queue is told a transfer entered verification.
 *
 * Retrofitted onto the discriminated base at s151 (ruling H3). It previously
 * emitted a SystemNotification byte-identical to T2's, so a recipient got two
 * indistinguishable rows for one request and could tell neither the stage nor
 * which transfer (OQ-1-144).
 */
class OwnershipTransferRequestedNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Ownership transfer requested';
    }

    protected function body(): string
    {
        return "An ownership transfer for {$this->companyName()} is awaiting identity verification.";
    }
}
