<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Enums\VerificationArtefactType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Verify-identity payload for an ownership-transfer case (UC-1-113; slice-11R
 * spec §2/§6). Built at the HTTP boundary via ::validateAndCreate (amendment
 * AQ). caseId is server-sourced from the route; no exists rule on it — the
 * interactor's find owns 404. newOwnerUserId is shape-validated here; the
 * live-membership check runs inside the interactor's transaction against the
 * CASE's company. Notes are mandatory-at-write (§10b keeper) and live only on
 * the case row.
 */
class VerifyOwnershipTransferCaseCommand extends Data
{
    #[OpaqueString('the case is read once, locked, inside the transaction')]
    public string $caseId;

    #[OpaqueString('checked as a live member under the lock - TARGET_NOT_MEMBER')]
    public string $newOwnerUserId;

    public string $notes;

    /**
     * @var array<int, VerificationArtefact>
     */
    #[DataCollectionOf(VerificationArtefact::class)]
    public array $artefacts;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'caseId' => ['uuid'],
            'newOwnerUserId' => ['required', 'uuid'],
            'notes' => ['required', 'string', 'max:1000'],
            'artefacts' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'artefacts.*' => ['array'],
            'artefacts.*.type' => ['required', Rule::enum(VerificationArtefactType::class)],
            'artefacts.*.reference' => ['required', 'string', 'max:255'],
            'artefacts.*.capturedAt' => ['required', 'date_format:'.VerificationArtefact::CAPTURED_AT_FORMAT, 'before_or_equal:now'],
        ];
    }

    /**
     * The artefact entries in the spec §2 metadata shape {type, reference, capturedAt}.
     *
     * @return array<int, array{type: string, reference: string, capturedAt: string}>
     */
    public function artefactMetadata(): array
    {
        return array_map(fn (VerificationArtefact $artefact): array => $artefact->metadata(), $this->artefacts);
    }
}
