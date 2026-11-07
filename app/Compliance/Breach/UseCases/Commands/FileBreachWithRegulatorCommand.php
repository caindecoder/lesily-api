<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;

class FileBreachWithRegulatorCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    /**
     * Both keys are route ids and carry no rule (amendment AQ); the method stays
     * because the AQ guard requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
