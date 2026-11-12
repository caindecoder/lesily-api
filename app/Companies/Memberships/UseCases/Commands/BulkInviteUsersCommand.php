<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Illuminate\Validation\Rules\In;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The bulk invite request (UC-1-020): one to twenty rows, each held to the
 * single invite's rules, in the company context the controller supplies.
 */
class BulkInviteUsersCommand extends Data
{
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    /**
     * @var list<array{email: string, roleCode: string}>
     */
    public array $invitations;

    /**
     * @return array<string, array<int, string|In>>
     */
    public static function rules(ValidationContext $context): array
    {
        $rowRules = collect(InviteUserCommand::rules($context))
            ->mapWithKeys(fn (array $rules, string $field): array => ["invitations.*.{$field}" => $rules])
            ->all();

        return [
            'invitations' => ['required', 'array', 'list', 'max:20'],
            ...$rowRules,
        ];
    }
}
