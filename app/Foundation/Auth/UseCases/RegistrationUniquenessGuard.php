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
    // Laravel's default unique-index names ({table}_{column}_unique); the DB
    // backstop reports these when a concurrent signup races past ensureUnique().
    private const EMAIL_INDEX = 'users_email_unique';

    private const BR_NUMBER_INDEX = 'companies_br_number_unique';

    private const EPF_NUMBER_INDEX = 'companies_epf_number_unique';

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
        return match ($violation->index) {
            self::EMAIL_INDEX => RegistrationConflictException::forEmail(),
            self::BR_NUMBER_INDEX => RegistrationConflictException::forBrNumber(),
            self::EPF_NUMBER_INDEX => RegistrationConflictException::forEpfNumber(),
            default => null,
        };
    }
}
