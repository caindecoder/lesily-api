<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class CancelDsarRequestCommand extends Data
{
    #[WithCast(ModelCast::class, DsarRequestRecord::class)]
    public DsarRequestRecord $dsarRequest;

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[WithCast(ModelCast::class, User::class)]
    public User $cancelledBy;

    /**
     * Every key is server-sourced (the route id, the company context, the
     * actor) and carries no rule (amendment AQ); the method stays because the
     * AQ guard requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
