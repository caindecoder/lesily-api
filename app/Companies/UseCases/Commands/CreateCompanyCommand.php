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
        // `required` is inferred from the non-nullable string properties; only the
        // country and owner-id formats need explicit rules.
        return [
            'country' => self::countryRules(),
            'ownerUserId' => self::ownerUserIdRules(),
        ];
    }
}
