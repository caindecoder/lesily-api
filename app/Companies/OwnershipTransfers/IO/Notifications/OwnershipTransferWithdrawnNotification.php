<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T6/T7 — the support team is told a transfer they were tracking has
 * been withdrawn by its own requester.
 *
 * Sent for BOTH windows (ruling J4). The same reactor announced the request's
 * arrival at T1, so filtering the departure to the post-verification window
 * would leave support hearing about arrivals and never about pre-verification
 * disappearances. UC-1-114's "no admin-side cleanup required" is about the queue
 * ROW, which leaves the open set on its own when `status` flips — not about
 * whether anyone is told.
 *
 * Carries no `source` of its own: the transfer it holds already knows which
 * window it ended in, and a body that named the stage would be the first copy in
 * this module to do so. If support ever needs the distinction in the text, it is
 * one `cancellation_source` read away, RED-first.
 */
class OwnershipTransferWithdrawnNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Ownership transfer withdrawn';
    }

    /**
     * Names the COMPANY, for the reason its rejected sibling does: the email leg
     * carries only a subject and a body, so without the tenant name a support
     * admin watching many companies cannot tell which one this concerns. That is
     * the OQ-1-144 defect reproduced on the email channel.
     */
    protected function body(): string
    {
        return "The ownership transfer request for {$this->companyName()} was withdrawn by the member who raised it.";
    }
}
