<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\UseCases\Commands\CompanyRegistrationRules;

/**
 * The fused owner-signup payload (UC-1-001 + UC-1-031 + UC-1-029 + UC-1-069 per
 * slice-company-onboarding §2 / system-architecture §3.2.1). Shape-level rules
 * only — the cross-record guards (unique email, unique BR/EPF, password strength,
 * confirmation) are the HTTP-seam sad paths of slice increment 05.
 */
class RegisterOwnerCommand extends Data
{
    use CompanyRegistrationRules;
    use RegisterOwnerRules;

    public string $name;

    public string $email;

    public string $password;

    public bool $acceptedTos;

    public string $companyName;

    public string $brNumber;

    public string $epfNumber;

    public string $country;

    public string $emergencyContactName;

    public string $emergencyContactEmail;

    public string $emergencyContactPhone;

    public string $emergencyContactRelationship;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => self::ownerNameRules(),
            'email' => self::ownerEmailRules(),
            'password' => self::passwordRules(),
            'acceptedTos' => self::acceptedTosRules(),
            'companyName' => self::nameRules(),
            'brNumber' => self::brNumberRules(),
            'epfNumber' => self::epfNumberRules(),
            'country' => self::countryRules(),
            'emergencyContactName' => self::ownerNameRules(),
            'emergencyContactEmail' => self::ownerEmailRules(),
            'emergencyContactPhone' => self::phoneRules(),
            'emergencyContactRelationship' => self::ownerNameRules(),
        ];
    }
}
