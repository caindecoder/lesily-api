<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Set the authenticated user's default company (UC-1-139, slice-companies B3).
 * The company must be one of the user's live memberships: a non-member, an
 * unknown and a removed-membership target all get the same one message, so a
 * refusal never tells whether a company id exists.
 */
class SetDefaultCompanyCommand extends Data
{
    #[MapInputName('companyId')]
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'companyId' => [
                'required',
                'uuid',
                Rule::exists('company_user', 'company_id')
                    ->where('user_id', auth()->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['companyId.exists' => 'The selected company is invalid.'];
    }
}
