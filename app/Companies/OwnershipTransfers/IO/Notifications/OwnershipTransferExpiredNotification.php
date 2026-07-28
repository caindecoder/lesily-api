<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T8/T9 — the requester is told their transfer lapsed before anyone
 * completed it, and that a fresh request is what restarts the process.
 *
 * Sent for BOTH windows. The stage matters to the audit trail, not to this
 * recipient: from where the requester stands the outcome is identical, and a
 * body that named the stage would be the first copy in this module to leak the
 * internal discriminator into recipient-facing text (the same reasoning that
 * kept `source` out of the withdrawn notice).
 *
 * Carries no extra constructor state, unlike its rejected sibling: a rejection
 * has a reason a human wrote, whereas an expiry has only a window that elapsed,
 * and the transfer already knows which one.
 */
class OwnershipTransferExpiredNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Ownership transfer expired';
    }

    /**
     * Names the COMPANY, for the reason its siblings do: the email leg carries a
     * subject and a body only, so without the tenant name a recipient who owns
     * several companies cannot tell which transfer lapsed. The resubmission
     * prompt is the UC's own postcondition — expiry is clean-up, not a penalty,
     * so the copy has to say the door is still open.
     */
    protected function body(): string
    {
        return "The ownership transfer request for {$this->companyName()} expired before it was completed. "
            .'You can raise a fresh request if the transfer is still needed.';
    }
}
