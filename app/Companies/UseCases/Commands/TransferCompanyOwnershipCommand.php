<?php

namespace App\Companies\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Enums\TransferSource;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Ownership-transfer payload for the Company aggregate (slice-11R spec §2/§4).
 * Built via ::validateAndCreate at the routine controller, which fixes
 * source=self_service and server-sources transferredBy from the actor, and via
 * ::from() by the admin execute path (Task 7), which dispatches the SAME command
 * with source=admin and its case (amendment AQ — a dual-use command).
 */
class TransferCompanyOwnershipCommand extends Data
{
    #[OpaqueString('checked as a live member under the lock - TARGET_NOT_MEMBER')]
    public string $newOwnerUserId;

    #[WithCast(ModelCast::class, User::class)]
    public User $transferredBy;

    public TransferSource $source;

    #[WithCast(ModelCast::class, OwnershipTransferCase::class)]
    public ?OwnershipTransferCase $case = null;

    /**
     * transferredBy, source and case are server-sourced (the actor, the
     * calling path, the admin case) and carry no rule (amendment AQ).
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'newOwnerUserId' => ['required', 'uuid'],
        ];
    }
}
