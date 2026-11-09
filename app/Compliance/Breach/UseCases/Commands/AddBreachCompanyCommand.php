<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Foundation\UseCases\Attributes\OpaqueString;

class AddBreachCompanyCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [];
    }
}
