<?php

namespace App\Companies\OwnershipTransfers\Enums;

/**
 * Why a platform admin ended a transfer (UC-1-128, SM-1-05 T3/T5). The
 * machine-readable companion to the verbatim `rejection_reason` prose: the prose
 * is what the requester reads, this is what a later audit can group and count on.
 *
 * Persisted from s151 ruling H1. Before it the Command validated a code that no
 * event payload and no column carried, so the UC's "rejection_reason_code
 * persisted" postcondition had nothing behind it and the validation was dead.
 */
enum RejectionReasonCode: string
{
    case IdentityFailed = 'identity_failed';
    case TargetIneligible = 'target_ineligible';
    case PolicyViolation = 'policy_violation';
    case Other = 'other';

    /**
     * The wire values, for the Command's `Rule::in` — one source of truth so the
     * accepted set cannot drift from this enum (the VerificationArtefactType
     * precedent).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
