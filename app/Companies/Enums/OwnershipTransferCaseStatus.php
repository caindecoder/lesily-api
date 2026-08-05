<?php

namespace App\Companies\Enums;

/**
 * The support-assisted ownership-transfer case lifecycle (slice-11R spec §4):
 * open → verified → closed. Which close happened lives in the separate
 * closed_outcome column (OwnershipTransferCaseOutcome).
 */
enum OwnershipTransferCaseStatus: string
{
    case Open = 'open';
    case Verified = 'verified';
    case Closed = 'closed';
}
