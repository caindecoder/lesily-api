<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Genesis payload for the Company aggregate (CD-1-02) — an internal command
 * built via ::from() by RegisterOwnerInteractor. The registration fields are
 * validated once, at the HTTP seam (RegisterOwnerCommand carries the
 * CompanyRegistrationRules); BR/EPF global uniqueness is a cross-record guard
 * there too (slice increment 05), and ownerUserId is server-minted.
 */
class CreateCompanyCommand extends Data
{
    public string $name;

    public string $brNumber;

    public string $epfNumber;

    public string $country;

    public string $ownerUserId;
}
