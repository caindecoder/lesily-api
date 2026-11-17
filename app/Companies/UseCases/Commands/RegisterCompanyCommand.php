<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Companies\UseCases\Rules\BrNumber;
use App\Companies\UseCases\Rules\EpfNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\Memberships\Enums\EmergencyContactRelationship;
use App\Companies\Memberships\UseCases\Rules\SriLankanPhoneNumber;

/**
 * The company and emergency-contact fields that owner sign-up and adding a
 * company both register (UC-1-031, UC-1-029, UC-1-069). BR / EPF uniqueness is
 * a 409 from RegistrationUniquenessGuard, not a rule here.
 */
abstract class RegisterCompanyCommand extends Data
{
    public string $brNumber;

    public string $epfNumber;

    public string $country;

    public string $emergencyContactName;

    public string $emergencyContactEmail;

    public string $emergencyContactPhone;

    public EmergencyContactRelationship $emergencyContactRelationship;

    /**
     * @return array<string, array<int, string|ValidationRule|Enum>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'brNumber' => ['required', 'string', 'max:255', new BrNumber],
            'epfNumber' => ['required', 'string', 'max:255', new EpfNumber],
            'country' => ['required', 'string', 'size:2'],
            'emergencyContactName' => ['required', 'string', 'max:255'],
            'emergencyContactEmail' => ['required', 'string', 'email', 'max:255'],
            'emergencyContactPhone' => ['required', 'string', 'max:255', new SriLankanPhoneNumber],
            'emergencyContactRelationship' => ['required', 'string', Rule::enum(EmergencyContactRelationship::class)],
            'acceptedTos' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'acceptedTos.accepted' => 'You must agree to the Terms of Service to continue.',
            'emergencyContactEmail.email' => 'The emergency contact email must be a valid email address.',
            'brNumber.required' => 'The BR (Business Registration) number is required.',
            'epfNumber.required' => 'The EPF employer number is required.',
        ];
    }
}
