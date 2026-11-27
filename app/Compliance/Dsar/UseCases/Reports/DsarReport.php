<?php

namespace App\Compliance\Dsar\UseCases\Reports;

class DsarReport
{
    /**
     * @param  array<int, DsarReportSection>  $sections
     */
    public function __construct(
        public readonly DsarReportCover $dsarReportCover,
        public readonly array $sections,
    ) {}
}
