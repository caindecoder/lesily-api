<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The owner's request to replace their emergency contact for one company (UC-1-029):
 * the contact itself, plus the company and the owner, which are server-sourced and
 * carry no rule.
 */
class UpdateEmergencyContactCommand extends SetEmergencyContactCommand
{
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[WithCast(ModelCast::class, User::class)]
    public User $setBy;

    /**
     * @return array<string, array<int, string|Enum|ValidationRule>>
     */
    public static function rules(ValidationContext $context): array
    {
        return parent::rules($context);
    }
}
