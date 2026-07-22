<?php

namespace App\Companies\OwnershipTransfers\Enums;

/**
 * SM-1-05 states. The backed values ARE the wire + DB strings — one canonical
 * set across ERD column, API filter, and Resource (s146 F1 re-pin). Terminals:
 * Executed / Rejected / Cancelled / Expired (stream ends, no resurrection).
 */
enum OwnershipTransferStatus: string
{
    case Requested = 'requested';
    case Verified = 'verified';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * A request still in flight — exactly the states the one-open-per-company
     * invariant counts (ERD-1-05 §5) and the expiry scan targets.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Requested, self::Verified => true,
            self::Executed, self::Rejected, self::Cancelled, self::Expired => false,
        };
    }
}
