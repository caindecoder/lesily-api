<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * T4 attempted when the live `companies.owner_id` no longer matches the
 * `current_owner_user_id` the transfer recorded at T1 — a prior transfer moved
 * ownership elsewhere while this one sat verified (UC-1-093 error flow 3b).
 *
 * The SELECT→write staleness class ruling O1 closed for the expiry scan, on the
 * transfer's other end: the request records a snapshot of who the owner was,
 * and nothing revalidates it until here.
 *
 * Its own class rather than the shared not-executable guard, matching every
 * sibling refusal: the rendered envelope is identical, so the MESSAGE is the
 * only discriminator a test can bind.
 */
class OwnershipTransferOwnerChangedException extends Exception
{
    protected $message = 'The company owner has changed since this transfer was requested.';
}
