<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Foundation\UseCases\Attributes\OpaqueString;

class MarkBreachFalseAlarmCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    public string $reason;

    public string $findings;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'findings' => ['required', 'string', 'max:1000'],
        ];
    }
}
