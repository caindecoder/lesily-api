<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerRules;
use App\Companies\Memberships\UseCases\Casts\SriLankanE164Cast;
use App\Companies\Memberships\Enums\EmergencyContactRelationship;

/**
 * The owner's request to replace their emergency contact for one company (UC-1-029),
 * validated at the PUT boundary; the company and the owner are server-sourced and
 * carry no rule.
 */
class UpdateEmergencyContactCommand extends Data
{
    use RegisterOwnerRules;

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[WithCast(ModelCast::class, User::class)]
    public User $setBy;

    public string $name;

    public string $email;

    #[WithCast(SriLankanE164Cast::class)]
    public string $phone;

    public EmergencyContactRelationship $relationship;

    public function contact(): SetEmergencyContactCommand
    {
        return SetEmergencyContactCommand::from([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'relationship' => $this->relationship,
        ]);
    }

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
