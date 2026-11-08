<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Illuminate\Validation\ValidationException;
use App\Compliance\Breach\Enums\BreachDataType;
use App\Compliance\Breach\Enums\BreachSeverity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use App\Foundation\UseCases\Attributes\OpaqueString;

class AmendBreachCommand extends Data
{
    /**
     * @var list<string>
     */
    protected const array AMENDABLE_KEYS = ['reason', 'description', 'likelyConsequences', 'measures', 'dateDiscovered', 'severity', 'companies'];

    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    public string $reason;

    public ?string $description = null;

    public ?string $likelyConsequences = null;

    public ?string $measures = null;

    #[WithCast(DateTimeInterfaceCast::class, format: RecordBreachCommand::DATE_DISCOVERED_FORMAT, setTimeZone: 'UTC')]
    public ?CarbonImmutable $dateDiscovered = null;

    public ?BreachSeverity $severity = null;

    /**
     * @var list<AffectedCompanyData>|null
     */
    #[DataCollectionOf(AffectedCompanyData::class)]
    public ?array $companies = null;

    public static function fromRequest(Request $request, string $breachId): static
    {
        $unknownKeys = array_diff(array_keys($request->all()), self::AMENDABLE_KEYS);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknownKeys, 'This field cannot be updated.'));
        }

        $request->merge(['breachId' => $breachId]);

        return static::validateAndCreate($request->all());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'description' => ['sometimes', 'required', 'string', 'max:1000'],
            'likelyConsequences' => ['sometimes', 'required', 'string', 'max:1000'],
            'measures' => ['sometimes', 'required', 'string', 'max:1000'],
            'dateDiscovered' => ['sometimes', 'required', 'date_format:'.RecordBreachCommand::DATE_DISCOVERED_FORMAT, 'before_or_equal:now'],
            'severity' => ['sometimes', 'required', Rule::enum(BreachSeverity::class)],
            'companies' => ['sometimes', 'required', 'array', 'list'],
            'companies.*' => ['array'],
            'companies.*.companyId' => ['required', 'uuid', 'distinct:ignore_case', Rule::exists('companies', 'id')],
            'companies.*.dataTypes' => ['required', 'array', 'list'],
            'companies.*.dataTypes.*' => [Rule::enum(BreachDataType::class)],
            'companies.*.estimatedCount' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'companies.required' => 'At least one affected company must be selected.',
        ];
    }
}
