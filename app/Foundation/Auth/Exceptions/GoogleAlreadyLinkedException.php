<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

class GoogleAlreadyLinkedException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyLinked(): self
    {
        return new self('Your Lesily login is already linked to a Google account.');
    }

    public static function toAnotherGoogleAccount(): self
    {
        return new self('This account is linked to a different Google account. Sign in with your password.');
    }
}
