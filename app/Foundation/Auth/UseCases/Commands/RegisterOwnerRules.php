<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Companies\Memberships\Enums\EmergencyContactRelationship;

/**
 * Shared rule-builders and messages for the owner-signup command — the
 * user-identity and emergency-contact fields. The messages are the use cases' own
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
     * @return array<int, string>
     */
    protected static function acceptedTosRules(): array
    {
        return ['required', 'accepted'];
    }

    /**
     * @return array<int, string>
     */
    protected static function phoneRules(): array
    {
        return ['required', 'string', 'max:255', 'phone:LK'];
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
            'acceptedTos.required' => 'You must agree to the Terms of Service to continue.',
            'acceptedTos.accepted' => 'You must agree to the Terms of Service to continue.',
            'emergencyContactEmail.email' => 'The emergency contact email must be a valid email address.',
            'emergencyContactPhone.phone' => 'The emergency contact phone must be a valid phone number.',
        ];
    }
}
