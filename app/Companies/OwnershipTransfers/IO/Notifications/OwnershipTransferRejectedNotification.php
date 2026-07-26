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

    protected function body(): string
    {
        return "Your ownership transfer request was not approved. Reason: {$this->reason}";
    }
}
