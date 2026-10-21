<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\UseCases\Commands\CompanyRegistrationRules;

/**
 * The fused owner-signup payload (UC-1-001 + UC-1-031 + UC-1-029 + UC-1-069).
 * Uniqueness of email, BR and EPF is a 409 from RegistrationUniquenessGuard, not a rule here.
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
     * @return array<string, array<int, string|Enum|ValidationRule>>
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
            'emergencyContactRelationship' => self::relationshipRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [...self::ownerMessages(), ...self::companyRegistrationMessages()];
    }
}
