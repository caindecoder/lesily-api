<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * T4 attempted from a state that cannot reach Executed — not yet verified, or
 * already ended, whether executed, rejected, cancelled or expired (SM-1-05 §4
 * gives T4 exactly one source: Verified).
 *
 * Its own class rather than a shared state-guard exception, matching the four
 * built siblings: every state-guard refusal renders the identical envelope, so
 * the MESSAGE is the only discriminator a test can bind, and a shared class
 * would let a transition throw the wrong refusal and stay green.
 */
class OwnershipTransferNotExecutableException extends Exception
{
    protected $message = 'This ownership transfer cannot be executed.';
}
