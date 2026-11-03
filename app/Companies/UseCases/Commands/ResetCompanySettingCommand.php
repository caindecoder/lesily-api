<?php

namespace App\Companies\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use App\Companies\Enums\ResettableSetting;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;

class ResetCompanySettingCommand extends Data
{
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    public ResettableSetting $setting;

    #[WithCast(ModelCast::class, User::class)]
    public User $resetBy;

    /**
     * Every key is server-sourced (the route's company and setting, the actor)
     * and carries no rule (amendment AQ); the method stays because the AQ guard
     * requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
