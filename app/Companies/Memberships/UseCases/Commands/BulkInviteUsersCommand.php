<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rules\In;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The bulk invite request (UC-1-020): one to twenty rows, each held to the
 * single invite's rules.
 */
class BulkInviteUsersCommand extends Data
{
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
            'invitations.*' => ['array'],
            ...$rowRules,
        ];
    }
}
