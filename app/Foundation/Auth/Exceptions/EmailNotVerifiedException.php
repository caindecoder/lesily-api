<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/** A Google sign-in that would match by email while either side has not verified it (G6, GS5a). */
class EmailNotVerifiedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Verify your email, or sign in with your password.');
    }
}
