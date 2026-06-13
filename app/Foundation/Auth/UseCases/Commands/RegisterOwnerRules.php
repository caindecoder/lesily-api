<?php

namespace App\Foundation\Auth\UseCases\Commands;

/**
 * Shared rule-builders for the owner-signup command — the user-identity and
 * emergency-contact fields. Shape-level only (max:255 bounds every varchar-backed
 * field against poison events); the rejection guards land RED-first with the
 * slice-05 sad scenarios.
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
        return ['required', 'string', 'max:255'];
    }
}
