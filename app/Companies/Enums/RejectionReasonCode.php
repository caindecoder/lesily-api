<?php

namespace App\Companies\Enums;

/**
 * The four reasons a platform admin may cite when rejecting an
 * ownership-transfer case (UC-1-128; slice-11R spec §3 keepers).
 */
enum RejectionReasonCode: string
{
    case IdentityFailed = 'identity_failed';
    case TargetIneligible = 'target_ineligible';
    case PolicyViolation = 'policy_violation';
    case Other = 'other';
}
