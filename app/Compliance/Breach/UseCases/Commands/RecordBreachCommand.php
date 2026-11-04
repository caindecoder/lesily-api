<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use App\Compliance\Breach\Enums\BreachDataType;
use App\Compliance\Breach\Enums\BreachSeverity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class RecordBreachCommand extends Data
{
    public const string DATE_DISCOVERED_FORMAT = 'Y-m-d\TH:i:sp';

    public string $description;

    public string $likelyConsequences;

    public string $measures;

    #[WithCast(DateTimeInterfaceCast::class, format: self::DATE_DISCOVERED_FORMAT)]
    public CarbonImmutable $dateDiscovered;

    public BreachSeverity $severity;

    /**
     * @var array<int, AffectedCompanyData>
     */
    #[DataCollectionOf(AffectedCompanyData::class)]
    public array $companies;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'description' => ['required', 'string', 'max:1000'],
            'likelyConsequences' => ['required', 'string', 'max:1000'],
            'measures' => ['required', 'string', 'max:1000'],
            'dateDiscovered' => ['required', 'date_format:'.self::DATE_DISCOVERED_FORMAT, 'before_or_equal:now'],
            'severity' => ['required', Rule::enum(BreachSeverity::class)],
            'companies' => ['required', 'array', 'list', 'min:1'],
            'companies.*' => ['array'],
            'companies.*.companyId' => ['required', 'uuid', 'distinct', Rule::exists('companies', 'id')],
            'companies.*.dataTypes' => ['required', 'array', 'list', 'min:1'],
            'companies.*.dataTypes.*' => [Rule::enum(BreachDataType::class)],
            'companies.*.estimatedCount' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'companies.required' => 'At least one affected company must be selected.',
            'companies.min' => 'At least one affected company must be selected.',
        ];
    }
}
