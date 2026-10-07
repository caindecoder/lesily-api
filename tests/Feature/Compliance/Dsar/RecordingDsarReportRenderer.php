<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Compliance\Dsar\IO\Pdf\ChromiumDsarReportRenderer;

/**
 * The real report renderer with Chromium left out: the report view renders
 * as in production, and the HTML Chromium would print is kept for the test.
 */
class RecordingDsarReportRenderer extends ChromiumDsarReportRenderer
{
    public string $html = '';

    protected function print(string $html): string
    {
        $this->html = $html;

        return DsarTestCase::STAND_IN_REPORT;
    }
}
