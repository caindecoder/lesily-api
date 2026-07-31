<?php

namespace App\Companies\OwnershipTransfers\UseCases\Values;

/**
 * What a scan run actually did: how many rows durably expired, and how many
 * failed mid-pipeline. Two numbers rather than one, because with per-row
 * isolation (F-b) a night where every row failed and a night where nothing was
 * due would otherwise both print "0 transfer(s) expired." over exit 0 —
 * byte-identical healthy-looking output for a broken night (Task R1 review,
 * C1/I1; Chamil's D1 ruling).
 */
class ExpiryScanOutcome
{
    public function __construct(
        public readonly int $expired,
        public readonly int $failed,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
