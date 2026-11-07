<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachFiledWithRegulator extends ShouldBeStored
{
    /**
     * @param  array<string, string>  $part  the company's part as rendered for the regulator — with the
     *                                       company name and address, the inputs the body hash was taken from
     */
    public function __construct(
        public string $breachId,
        public string $companyId,
        public string $companyName,
        public CarbonInterface $filedAt,
        public string $filedByUserId,
        public string $regulatorAddress,
        public array $part,
        public string $bodyHash,
    ) {}
}
