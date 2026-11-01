<?php

namespace App\Companies\UseCases\Commands;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\WithCast;
use App\Companies\Enums\VerificationArtefactType;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;

/**
 * One piece of identity evidence on a verify-identity request (UC-1-113;
 * slice-11R spec §2) in its closed metadata shape {type, reference, capturedAt};
 * an unknown key never reaches the event or the case row. Its rules live on
 * VerifyOwnershipTransferCaseCommand::rules() under artefacts.*.
 */
class VerificationArtefact extends Data
{
    public const string CAPTURED_AT_FORMAT = 'Y-m-d\TH:i:sp';

    public VerificationArtefactType $type;

    public string $reference;

    #[WithCast(DateTimeInterfaceCast::class, format: self::CAPTURED_AT_FORMAT)]
    public CarbonImmutable $capturedAt;

    /**
     * @return array{type: string, reference: string, capturedAt: string}
     */
    public function metadata(): array
    {
        return [
            'type' => $this->type->value,
            'reference' => $this->reference,
            'capturedAt' => $this->capturedAt->format(self::CAPTURED_AT_FORMAT),
        ];
    }
}
