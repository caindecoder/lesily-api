<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Compliance\Breach\Enums\BreachDataType;
use Spatie\LaravelData\Attributes\MapInputName;

/**
 * One affected company with its own figures (OQ-1-009). Its rules live on
 * RecordBreachCommand::rules() under companies.*.
 */
class AffectedCompanyData extends Data
{
    #[MapInputName('companyId')]
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    /**
     * @var array<int, BreachDataType>
     */
    public array $dataTypes;

    public ?int $estimatedCount = null;
}
