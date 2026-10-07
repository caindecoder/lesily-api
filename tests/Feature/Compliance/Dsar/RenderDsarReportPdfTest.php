<?php

namespace Tests\Feature\Compliance\Dsar;

use Throwable;
use RuntimeException;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\View;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\UseCases\Reports\DsarReportCover;
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
        $attempt = fn () => $this->theRegisteredReportRenderer()->render($this->aReport());

        // Then rendering fails with the report error, the cause kept
        $this->assertThrows($attempt, fn (Throwable $failure): bool => $failure instanceof DsarReportRenderingFailedException && $failure->getPrevious() === $cause);
    }

    #[Test]
    public function when_chromium_returns_an_incomplete_document_then_rendering_fails_with_the_report_error(): void
    {
        // Given Chromium returns an empty render instead of failing
        $emptyRender = new class extends PdfBuilder
        {
            public function base64(): string
            {
                return '';
            }
        };
        Pdf::shouldReceive('html')->andReturn($emptyRender);

        // When the registered renderer renders a report
        $attempt = fn () => $this->theRegisteredReportRenderer()->render($this->aReport());

        // Then rendering fails with the report error rather than returning a broken PDF
        $this->assertThrows($attempt, DsarReportRenderingFailedException::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWebSchemes(): array
    {
        return [
            'plain web' => ['http'],
            'secure web' => ['https'],
        ];
    }

    #[Test, DataProvider('provideWebSchemes')]
    public function when_the_report_references_a_web_resource_then_it_is_never_fetched(string $scheme): void
    {
        // Given a report template that loads an image from a listening web address
        $listener = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No socket to listen on.');
        $this->aReportTemplateLoading($scheme.'://'.stream_socket_get_name($listener, false).'/pixel.png');

        // When the registered renderer renders a report
        $this->theRegisteredReportRenderer()->render($this->aReport());

        // Then nothing ever connected to that address
        $this->assertNoConnectionWaitingOn($listener);
    }

    #[Test]
    public function when_a_report_is_rendered_then_a_pdf_document_is_returned(): void
    {
        // Given a report
        $report = $this->aReport();

        // When the registered renderer renders it
        $pdf = $this->theRegisteredReportRenderer()->render($report);

        // Then a PDF document comes back
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    protected function aReport(): DsarReport
    {
        return new DsarReport(new DsarReportCover('Nimal Perera', 'nimal@example.com', 'Lanka Tech Solutions', 'a-request-reference', '1 Oct 2026, 09:00 UTC', '1 Oct 2026, 09:00 UTC'), []);
    }

    /**
     * The report view is replaced, for this test only, by one that loads an
     * image from $url.
     */
    protected function aReportTemplateLoading(string $url): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dsar-views-'.Str::random(8);
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory.DIRECTORY_SEPARATOR.'dsar');
        $files->put($directory.DIRECTORY_SEPARATOR.'dsar'.DIRECTORY_SEPARATOR.'report.blade.php', '<html><body><img src="'.$url.'"></body></html>');
        View::prependLocation($directory);
        $this->beforeApplicationDestroyed(fn () => $files->deleteDirectory($directory));
    }

    /**
     * @param  resource  $listener
     */
    protected function assertNoConnectionWaitingOn($listener): void
    {
        $waiting = [$listener];
        $unwatched = null;

        $this->assertSame(0, stream_select($waiting, $unwatched, $unwatched, 0));
    }
}
