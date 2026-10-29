<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

class GoogleEmailMismatchException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notTheUsersEmail(): self
    {
        return new self('Use the Google account for your Lesily email address.');
    }

    public static function notVerifiedByGoogle(): self
    {
        return new self('Google has not verified this email address. Verify it with Google first.');
    }
}
