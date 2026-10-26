<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerRules;

/**
 * An owner's emergency contact for one company (UC-1-029) — validated at the
 * PUT boundary; CreateCompanyInteractor builds it via ::from() from the fields
 * CreateCompanyCommand already validated.
 */
class SetEmergencyContactCommand extends Data
{
    use RegisterOwnerRules;

    public string $name;

    public string $email;

    public string $phone;

    public string $relationship;

    /**
     * @return array<string, array<int, string|Enum|ValidationRule>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => self::ownerNameRules(),
            'email' => self::ownerEmailRules(),
            'phone' => self::phoneRules(),
            'relationship' => self::relationshipRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'emergency contact name',
            'email' => 'emergency contact email',
            'phone' => 'emergency contact phone',
            'relationship' => 'emergency contact relationship',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['email.email' => 'The emergency contact email must be a valid email address.'];
    }
}
