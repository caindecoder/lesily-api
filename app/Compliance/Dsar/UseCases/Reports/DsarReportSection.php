<?php

namespace App\Compliance\Dsar\UseCases\Reports;

class DsarReportSection
{
    /**
     * @param  array<int, DsarReportTable>  $tables
     */
    public function __construct(
        public readonly string $title,
        public readonly array $tables,
    ) {}
}
