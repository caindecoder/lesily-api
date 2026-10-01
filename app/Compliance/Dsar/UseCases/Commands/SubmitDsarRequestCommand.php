<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class SubmitDsarRequestCommand extends Data
{
    public ?string $subject_user_id = null;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'subject_user_id' => ['nullable', 'uuid'],
        ];
    }
}
