<?php

namespace App\Companies\Exceptions;

use Exception;

/**
 * The Owner named themselves as the transfer target (UC-1-028 flow 1c) —
 * ownership is already theirs; SELF_TRANSFER_BLOCKED 422.
 */
class SelfTransferBlockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Ownership cannot be transferred to the current Owner.');
    }
}
