<?php

namespace App\Compliance\Breach\Values;

class RegulatorFiling
{
    /**
     * @param  array<string, string>  $part
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $regulatorAddress,
        public readonly array $part,
        public readonly string $bodyHash,
    ) {}
}
