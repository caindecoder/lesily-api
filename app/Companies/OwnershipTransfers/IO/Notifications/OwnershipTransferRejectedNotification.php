<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest as TransferReadModel;

/**
 * SM-1-05 T3/T5 — the requester is told their transfer was ended, and why.
 *
 * The reason travels VERBATIM (UC-1-128: "the rejection reason recorded
 * verbatim", so the owner can judge whether to escalate, re-file with corrected
 * information, or abandon the transfer). It is a constructor field rather than
 * something read back off the transfer row so the notification is complete on
 * construction and cannot render a reason the projector has not committed yet.
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
