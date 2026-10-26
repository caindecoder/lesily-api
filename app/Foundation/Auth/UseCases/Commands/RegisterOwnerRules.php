<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Companies\Memberships\Enums\EmergencyContactRelationship;
use App\Companies\Memberships\UseCases\Rules\SriLankanPhoneNumber;

/**
 * Shared rule-builders and messages for the user-identity and emergency-contact
 * fields — used by the owner-signup command and, for the contact and ToS fields, by
 * CreateCompanyCommand. The messages are the use cases' own
 * words (UC-1-001, UC-1-029, UC-1-069).
 */
trait RegisterOwnerRules
{
    /**
     * @return array<int, string>
     */
    protected static function ownerNameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, string>
     */
    protected static function ownerEmailRules(): array
    {
        return ['required', 'string', 'email', 'max:255'];
    }

    /**
     * @return array<int, string>
     */
    protected static function passwordRules(): array
    {
        return ['required', 'string', 'min:8', 'max:255'];
    }

    /**
     * `accepted` also fails a missing or empty value, so `required` adds nothing.
     *
     * @return array<int, string>
     */
    protected static function acceptedTosRules(): array
    {
        return ['accepted'];
    }

    /**
     * @return array<int, string|ValidationRule>
     */
    protected static function phoneRules(): array
    {
        return ['required', 'string', 'max:255', new SriLankanPhoneNumber];
    }

    /**
     * @return array<int, string|Enum>
     */
    protected static function relationshipRules(): array
    {
        return ['required', 'string', Rule::enum(EmergencyContactRelationship::class)];
    }

    /**
     * @return array<string, string>
     */
    protected static function ownerMessages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters.',
            'acceptedTos.accepted' => 'You must agree to the Terms of Service to continue.',
            'emergencyContactEmail.email' => 'The emergency contact email must be a valid email address.',
        ];
    }
}
