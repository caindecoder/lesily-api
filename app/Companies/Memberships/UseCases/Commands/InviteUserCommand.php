<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Entities\Company;
use Illuminate\Validation\Rules\In;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The invite request: the invitee's email and the role code to assign
 * (UC-1-018), in the company context the controller supplies (no rule — it is
 * never client input). Shape-only validation — the already-member and role
 * guards live in InviteUserInteractor.
 */
class InviteUserCommand extends Data
{
    /**
     * The seeded roles an invite may assign — owner is never invite-assignable
     * (ownership transfer only, UC-1-028) and platform_super_admin is not a
     * company role (D4; sm-user T1 guard).
     *
     * @var list<string>
     */
    private const array INVITABLE_ROLE_CODES = ['admin', 'hr_officer', 'payroll_officer', 'employee', 'auditor'];

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    public string $email;

    #[OpaqueString('a role code - the seeded catalog, open to custom roles later')]
    public string $roleCode;

    /**
     * @return array<string, array<int, string|In>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'roleCode' => ['required', 'string', Rule::in(self::INVITABLE_ROLE_CODES)],
        ];
    }
}
