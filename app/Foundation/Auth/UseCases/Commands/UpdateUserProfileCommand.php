<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class UpdateUserProfileCommand extends Data
{
    public string $locale;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'locale' => ['required', 'string', 'in:en,si,ta'],
        ];
    }
}
