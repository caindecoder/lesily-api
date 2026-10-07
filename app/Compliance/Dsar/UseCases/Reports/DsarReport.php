<?php

namespace App\Compliance\Dsar\UseCases\Reports;

class DsarReport
{
    /**
     * @param  array<int, DsarReportSection>  $sections
     */
    public function __construct(
        public readonly DsarReportCover $cover,
        public readonly array $sections,
    ) {}
}
