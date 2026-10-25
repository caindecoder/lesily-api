<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * A company opened for its owner (UC-1-031, UC-1-029, UC-1-069) — the input of
 * CreateCompanyInteractor and of the Company aggregate's genesis. Signup builds it
 * via ::from() from its own validated payload; ownerUserId is server-set.
 */
class CreateCompanyCommand extends Data
{
    public string $name;

    public string $brNumber;

    public string $epfNumber;

    public string $country;

    public string $ownerUserId;

    public string $emergencyContactName;

    public string $emergencyContactEmail;

    public string $emergencyContactPhone;

    public string $emergencyContactRelationship;

    public bool $acceptedTos;
}
