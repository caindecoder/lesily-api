<?php

namespace App\Compliance\Dsar\UseCases\Responses;

use Carbon\CarbonImmutable;

/**
 * The use case's response model (E07): where the export lives on the dsar
 * disk and when it was fulfilled — the delivery layer only streams it.
 */
class DsarExportFile
{
    public function __construct(
        public readonly string $path,
        public readonly CarbonImmutable $fulfilledAt,
    ) {}

    public function fileName(): string
    {
        return 'lesily-data-export-'.$this->fulfilledAt->format('Y-m-d').'.zip';
    }
}
