<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ResetPasswordCommand extends Data
{
    public string $token;

    public string $email;

    public string $password;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }
}
