<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;

/**
 * The resend request (UC-1-021): which invitation, in which company. The
 * aggregate's own payload is ResendInvitationCommand, built by the interactor.
 */
class ResendPendingInvitationCommand extends Data
{
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[OpaqueString('an invitation id - its first read is the interactor\'s company-scoped lookup, so another company\'s id is a 404')]
    public string $invitationId;

    /**
     * Every key is server-sourced (the company context, the route id) and
     * carries no rule (amendment AQ); the method stays because the AQ guard
     * requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
