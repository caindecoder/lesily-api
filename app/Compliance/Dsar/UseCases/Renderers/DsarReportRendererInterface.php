<?php

namespace App\Compliance\Dsar\UseCases\Renderers;

use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;

interface DsarReportRendererInterface
{
    /**
     * The report as PDF bytes.
     *
     * @throws DsarReportRenderingFailedException when no complete PDF can be produced
     */
    public function render(DsarReport $report): string;
}
