<?php

namespace Tests\Feature\Compliance\Dsar;

use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;

class RenderDsarReportPdfTest extends DsarTestCase
{
    #[Test]
    public function when_a_report_is_rendered_then_a_pdf_document_is_returned(): void
    {
        // Given a report
        $report = new DsarReport;

        // When the registered renderer renders it
        $pdf = $this->theRegisteredReportRenderer()->render($report);

        // Then a PDF document comes back
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
