<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Foundation\Auth\Enums\Locale;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class UpdateUserProfileCommand extends Data
{
    public string $name;

    public Locale $locale;

    /**
     * @return array<string, array<int, string|Enum>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ];
    }
}
