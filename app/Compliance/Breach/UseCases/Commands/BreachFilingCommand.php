<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;

/**
 * A step on a breach's regulator filing: the incident and the company, both
 * server-sourced (the route id, the company context).
 */
abstract class BreachFilingCommand extends Data
{
    #[OpaqueString('the incident is read once, locked, inside the transaction')]
    public string $breachId;

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    /**
     * Every key is server-sourced and carries no rule (amendment AQ).
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
