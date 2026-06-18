<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;

/**
 * Rejects an owner signup whose email / BR / EPF is already taken, BEFORE the
 * register transaction opens — so a conflict never writes a partial account. The
 * read-model lookups back the field-specific 409s; the DB UNIQUE constraints
 * remain the race-condition backstop.
 */
class RegistrationUniquenessGuard
{
    public function ensureUnique(RegisterOwnerCommand $command): void
    {
        if (User::query()->where('email', $command->email)->exists()) {
            throw RegistrationConflictException::forEmail();
        }

        if (Company::query()->where('br_number', $command->brNumber)->exists()) {
            throw RegistrationConflictException::forBrNumber();
        }

        if (Company::query()->where('epf_number', $command->epfNumber)->exists()) {
            throw RegistrationConflictException::forEpfNumber();
        }
    }

    public function conflictFor(UniqueConstraintViolationException $violation): ?RegistrationConflictException
    {
        return null;
    }
}
