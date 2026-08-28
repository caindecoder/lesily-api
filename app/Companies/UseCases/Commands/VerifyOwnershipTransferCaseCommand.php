<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Enums\VerificationArtefactType;
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
    public string $caseId;

    public string $newOwnerUserId;

    public string $notes;

    /**
     * @var array<int, array<string, mixed>>
     */
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
            'artefacts.*.capturedAt' => ['required', 'date_format:Y-m-d\TH:i:sp', 'before_or_equal:now'],
        ];
    }

    /**
     * The artefact entries projected to EXACTLY the spec §2 metadata shape
     * {type, reference, capturedAt} — unknown keys are structurally dropped,
     * so nothing beyond the closed metadata set can reach the event payload
     * or the case row.
     *
     * @return array<int, array{type: string, reference: string, capturedAt: string}>
     */
    public function artefactMetadata(): array
    {
        return array_map(fn (array $artefact): array => [
            'type' => $artefact['type'],
            'reference' => $artefact['reference'],
            'capturedAt' => $artefact['capturedAt'],
        ], $this->artefacts);
    }
}
