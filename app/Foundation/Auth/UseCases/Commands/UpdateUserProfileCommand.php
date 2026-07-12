<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class UpdateUserProfileCommand extends Data
{
    public string $name;

    public string $locale;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'locale' => ['required', 'string', 'in:en,si,ta'],
        ];
    }
}
