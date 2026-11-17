<?php

namespace App\Companies\UseCases\Commands;

use App\Models\User;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * A company opened for its owner (UC-1-031, UC-1-029, UC-1-069) — the input of
 * CreateCompanyInteractor and of the Company aggregate's genesis. POST /companies
 * validates it at the HTTP boundary; signup builds it via ::from() from its own
 * validated payload. owner is server-set and carries no rule; acceptedTos is
 * a rule with no property — accepted at the boundary, never read after it.
 */
class CreateCompanyCommand extends RegisterCompanyCommand
{
    public string $name;

    #[WithCast(ModelCast::class, User::class)]
    public User $owner;

    /**
     * @return array<string, array<int, string|Enum|ValidationRule>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return ['name' => 'company name'];
    }
}
