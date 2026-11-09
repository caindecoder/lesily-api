<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Compliance\Breach\Enums\BreachDataType;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\UseCases\Attributes\OpaqueString;

class AddBreachCompanyCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    #[MapInputName('companyId')]
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    /**
     * @var list<BreachDataType>
     */
    public array $dataTypes;

    public ?int $estimatedCount = null;

    public string $reason;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'companyId' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'dataTypes' => ['required', 'array', 'list'],
            'dataTypes.*' => [Rule::enum(BreachDataType::class)],
            'estimatedCount' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
