<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest as TransferReadModel;

/**
 * SM-1-05 T3/T5 — the requester is told their transfer was ended, and why.
 *
 * The reason travels VERBATIM (UC-1-128: "the rejection reason recorded
 * verbatim", so the owner can judge whether to escalate, re-file with corrected
 * information, or abandon the transfer).
 *
 * It is a constructor field carrying the EVENT's value. The original rationale
 * here — that this avoids rendering a reason the projector has not committed —
 * did not survive checking (s153): `RequesterNotificationReactor` reads the
 * projection row before constructing this, and the base's `companyName()`
 * renders through that same row, so the projector demonstrably has committed.
 * The honest reason to keep the constructor field is provenance, not timing:
 * the event payload is the immutable record of what was decided, and reading
 * the prose from the row would let a later correcting write to
 * `rejection_reason` silently change what a re-sent notification says the
 * admin's reason was.
 *
 * Divergence from LeaveDates (Rule #15, declared not accidental): LD passes the
 * whole entity and never a loose string. Accepted here because the two sources
 * are not interchangeable — see above.
 */
class OwnershipTransferRejectedNotification extends OwnershipTransferNotification
{
    public function __construct(
        TransferReadModel $transfer,
        public string $reason,
    ) {
        parent::__construct($transfer);
    }

    protected function title(): string
    {
        return 'Ownership transfer rejected';
    }

    /**
     * Names the COMPANY as well as the reason (s152 review).
     *
     * Both sibling notifications interpolate companyName(), and this is the only
     * one of the three delivered to a tenant rather than to the platform-admin
     * allowlist. The in-app leg carries `ownership_transfer_request_id` so a
     * client can deep-link, but the EMAIL leg carries only a subject and a body —
     * so without the company name an owner of more than one company receives
     * "your ownership transfer request was not approved" and has no way to tell
     * which company it concerns. That is the OQ-1-144 defect reproduced on the
     * email channel, and this hierarchy exists to prevent exactly it.
     */
    protected function body(): string
    {
        return "Your ownership transfer request for {$this->companyName()} was not approved. Reason: {$this->reason}";
    }
}
