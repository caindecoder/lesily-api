<?php

namespace App\Companies\Enums;

/**
 * Which close ended an ownership-transfer case (slice-11R spec §4): executed
 * (the transfer ran, UC-1-093) or rejected (UC-1-128). NULL while the case is
 * open or verified.
 */
enum OwnershipTransferCaseOutcome: string
{
    case Executed = 'executed';
    case Rejected = 'rejected';
}
