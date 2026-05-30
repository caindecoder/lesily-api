<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * The role gate's denial (second gate). Carries the permission the member lacked
 * so the error envelope can name it (error_code ROLE_INSUFFICIENT). Symmetric
 * with FeatureUnavailableException.
 */
class RoleInsufficientException extends Exception
{
    public function __construct(public string $permission)
    {
        parent::__construct('You do not have permission to perform this action.');
    }
}
