<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use App\Foundation\UseCases\Attributes\OpaqueString;

class AmendBreachCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    public static function fromRequest(Request $request, string $breachId): static
    {
        $request->merge(['breachId' => $breachId]);

        return static::validateAndCreate($request->all());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [];
    }
}
