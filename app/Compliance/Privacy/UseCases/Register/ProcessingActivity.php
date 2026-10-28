<?php

namespace App\Compliance\Privacy\UseCases\Register;

use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;

class ProcessingActivity
{
    /**
     * @param  list<string>  $dataCategories
     * @param  list<ThirdParty>  $thirdParties
     */
    public function __construct(
        public readonly string $module,
        public readonly string $activity,
        public readonly array $dataCategories,
        public readonly LegalBasis $legalBasis,
        public readonly array $thirdParties,
    ) {}

    public function key(): string
    {
        return "{$this->module}.{$this->activity}";
    }
}
