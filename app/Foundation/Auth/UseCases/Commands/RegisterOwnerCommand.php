<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Illuminate\Validation\Rules\Enum;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Companies\UseCases\Commands\RegisterCompanyCommand;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The fused owner-signup payload (UC-1-001 + UC-1-031 + UC-1-029 + UC-1-069).
 * Uniqueness of email, BR and EPF is a 409 from RegistrationUniquenessGuard, not a rule here.
 */
class RegisterOwnerCommand extends RegisterCompanyCommand
{
    public string $name;

    public string $email;

    public string $password;

    public bool $acceptedTos;

    public string $companyName;

    /**
     * @return array<string, array<int, string|Enum|ValidationRule>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'companyName' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            ...parent::messages(),
            'password.min' => 'Password must be at least 8 characters.',
        ];
    }
}
