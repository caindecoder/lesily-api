<?php

namespace App\Companies\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerRules;
use App\Companies\Memberships\Enums\EmergencyContactRelationship;

/**
 * A company opened for its owner (UC-1-031, UC-1-029, UC-1-069) — the input of
 * CreateCompanyInteractor and of the Company aggregate's genesis. POST /companies
 * validates it at the HTTP boundary; signup builds it via ::from() from its own
 * validated payload. owner is server-set and carries no rule; acceptedTos is
 * a rule with no property — accepted at the boundary, never read after it.
 */
class CreateCompanyCommand extends Data
{
    use CompanyRegistrationRules;
    use RegisterOwnerRules;

    public string $name;

    public string $brNumber;

    public string $epfNumber;

    public string $country;

    #[WithCast(ModelCast::class, User::class)]
    public User $owner;

    public string $emergencyContactName;

    public string $emergencyContactEmail;

    public string $emergencyContactPhone;

    public EmergencyContactRelationship $emergencyContactRelationship;

    /**
     * @return array<string, array<int, string|Enum|ValidationRule>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => self::nameRules(),
            'brNumber' => self::brNumberRules(),
            'epfNumber' => self::epfNumberRules(),
            'country' => self::countryRules(),
            'emergencyContactName' => self::ownerNameRules(),
            'emergencyContactEmail' => self::ownerEmailRules(),
            'emergencyContactPhone' => self::phoneRules(),
            'emergencyContactRelationship' => self::relationshipRules(),
            'acceptedTos' => self::acceptedTosRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return ['name' => 'company name'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [...self::ownerMessages(), ...self::companyRegistrationMessages()];
    }
}
