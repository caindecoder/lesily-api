<?php

namespace App\Compliance\Dsar\UseCases\Reports;

class DsarReport
{
    public function __construct(
        public readonly DsarReportCover $cover,
    ) {}
}
