<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Ownership-transfer payload for the Company aggregate (slice-11R spec §2/§4).
 * Built via ::validateAndCreate only — never ::from (amendment U). The routine
 * controller fixes source=self_service and server-sources transferredBy from
 * the actor; the admin execute path (Task 7) dispatches the SAME command with
 * source=admin and a caseId.
 */
class TransferCompanyOwnershipCommand extends Data
{
    public const string SOURCE_SELF_SERVICE = 'self_service';

    public const string SOURCE_ADMIN = 'admin';

    public string $newOwnerUserId;

    public string $transferredBy;

    public string $source;

    public ?string $caseId = null;

    /**
     * transferredBy, source and caseId are server-sourced (the actor, the
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
