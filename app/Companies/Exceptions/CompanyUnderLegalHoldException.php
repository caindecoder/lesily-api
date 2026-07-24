<?php

namespace App\Companies\Exceptions;

use Exception;

/**
 * An operation was refused because the company is under a regulatory legal hold
 * (§S10b). A hold exists to PRESERVE the record, so it freezes changes of
 * ownership and blocks deletion/anonymisation — it never blocks the data
 * subject's own read (the s143 D4 DSAR-access carve-out).
 *
 * Lives in the Companies namespace rather than any consuming sub-domain: the
 * flag is the Company aggregate's state, and its consumers already span
 * OwnershipTransfers (T2 verify) with DSAR and UC-1-039/040 to follow. Nesting
 * it under OwnershipTransfers would point the dependency the wrong way (E66 §7
 * — the same inversion the s148 AdminRequestContext rider had to undo).
 *
 * Renders as the shared 409 STATE_GUARD_VIOLATION family.
 */
class CompanyUnderLegalHoldException extends Exception
{
    protected $message = 'This company is under a legal hold; ownership changes are frozen until it is lifted.';
}
