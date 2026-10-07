<?php

namespace App\Compliance\Dsar\UseCases\Reports;

class DsarReportSection
{
    public function __construct(
        public readonly string $title,
    ) {}
}
