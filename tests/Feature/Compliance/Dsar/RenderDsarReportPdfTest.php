<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;

class RenderDsarReportPdfTest extends DsarTestCase
{
    #[Test]
    public function when_chromium_fails_then_rendering_fails_with_the_report_error(): void
    {
        // Given Chromium dies while printing
        $cause = new RuntimeException('Chromium process died');
        Pdf::shouldReceive('html')->once()->andThrow($cause);

        // When the registered renderer renders a report
        $attempt = fn () => $this->theRegisteredReportRenderer()->render(new DsarReport);

        // Then rendering fails with the report error, the cause kept
        $this->assertThrows($attempt, fn (DsarReportRenderingFailedException $failure): bool => $failure->getPrevious() === $cause);
    }

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
