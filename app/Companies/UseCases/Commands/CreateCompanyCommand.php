<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Genesis payload for the Company aggregate (CD-1-02). Shape-level rules only —
 * BR/EPF global uniqueness is a cross-record guard enforced at the registration
 * HTTP seam (slice increment 05), not at command construction.
 */
class CreateCompanyCommand extends Data
{
    use CompanyRegistrationRules;

    public string $name;

    public string $brNumber;

    public string $epfNumber;

    public string $country;

    public string $ownerUserId;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:255 bounds every varchar-backed field at the command boundary — an
        // over-long value would otherwise record a CompanyCreated event whose
        // projection can never succeed (poison event on replay).
        return [
            'name' => self::nameRules(),
            'brNumber' => self::brNumberRules(),
            'epfNumber' => self::epfNumberRules(),
            'country' => self::countryRules(),
            'ownerUserId' => self::ownerUserIdRules(),
        ];
    }
}
