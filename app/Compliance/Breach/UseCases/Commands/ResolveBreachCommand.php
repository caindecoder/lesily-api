<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Foundation\UseCases\Attributes\OpaqueString;

class ResolveBreachCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    public string $rootCause;

    public string $remediationSteps;

    public string $summary;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'rootCause' => ['required', 'string', 'max:1000'],
            'remediationSteps' => ['required', 'string', 'max:1000'],
            'summary' => ['required', 'string', 'max:1000'],
        ];
    }
}
