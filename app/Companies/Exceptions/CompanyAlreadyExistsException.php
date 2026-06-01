<?php

namespace App\Companies\Exceptions;

use DomainException;

class CompanyAlreadyExistsException extends DomainException
{
    public static function withId(string $companyId): self
    {
        return new self("Company {$companyId} has already been created.");
    }
}
