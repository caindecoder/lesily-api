<?php

namespace App\Foundation\Auth\Exceptions;

use DomainException;

class UserAlreadyRegisteredException extends DomainException
{
    public static function withId(string $userId): self
    {
        return new self("User {$userId} has already signed up.");
    }
}
