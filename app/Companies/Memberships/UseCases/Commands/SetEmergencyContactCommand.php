<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * An owner's emergency contact for one company (UC-1-029) — an internal command
 * built via ::from() by CreateCompanyInteractor; the fields are validated where
 * they enter, on CreateCompanyCommand.
 */
class SetEmergencyContactCommand extends Data
{
    public string $name;

    public string $email;

    public string $phone;

    public string $relationship;
}
