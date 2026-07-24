<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\OwnershipTransfers\Enums\VerificationArtefactType;
use App\Companies\OwnershipTransfers\UseCases\Rules\ArtefactCarriesOnlyMetadata;

/**
 * The T2 verification record (UC-1-113, SM-1-05). Both fields are mandatory at
 * write time by design: the UC's minimal guarantee is that no PARTIAL
 * verification record can ever reach stored_events, so an admin cannot produce
 * an unauditable approval.
 *
 * Assembled and validated at the delivery boundary via ::validateAndCreate (the
 * standing pattern, s146) — never ::from, which skips rules() entirely.
 */
class VerifyOwnershipTransferIdentityCommand extends Data
{
    public string $verificationNotes;

    /** @var list<array{type: string, reference: string, capturedAt: string}> */
    public array $verificationArtefacts;

    /** Auth-resolved at the boundary, not client-supplied. */
    public string $verifiedBy;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // The nested dot-star shape follows LD's UpdateEmailReportSchedulesCommand
        // (app/IAM/UseCases/Requests, `daily.scopes.*.companyId`). max:1000 bounds
        // the notes at the boundary like RequestOwnershipTransferCommand bounds
        // its reason — the column is text, the bound is the contract's.
        return [
            'verificationNotes' => ['required', 'string', 'max:1000'],
            // Bounded like every neighbour: artefacts land in stored_events,
            // which is append-only, so an unbounded array is an unbounded and
            // UNREMEDIABLE write. 20 is far above the handful a real
            // verification cites and far below anything abusive.
            'verificationArtefacts' => ['required', 'array', 'min:1', 'max:20'],
            // Metadata ONLY — the artefact object is closed. Artefacts persist
            // verbatim into stored_events, so an unpoliced extra key is a route
            // for document bytes to reach the immutable event store, which the
            // DPO contract forbids (OQ-1-045 / D4: references and capture times,
            // never the documents). Laravel has no built-in "no unknown keys"
            // rule for a nested object, so the closure IS the enforcement.
            'verificationArtefacts.*' => ['array', new ArtefactCarriesOnlyMetadata],
            'verificationArtefacts.*.type' => ['required', Rule::in(VerificationArtefactType::values())],
            'verificationArtefacts.*.reference' => ['required', 'string', 'max:255'],
            'verificationArtefacts.*.capturedAt' => ['required', 'date'],
            'verifiedBy' => ['required', 'uuid'],
        ];
    }
}
