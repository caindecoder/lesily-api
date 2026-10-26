<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\View;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\UseCases\Reports\DsarReportCover;
use App\Compliance\Dsar\IO\Pdf\ChromiumDsarReportRenderer;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;

class RenderDsarReportPdfTest extends DsarTestCase
{
    protected const SERVER_WAIT_SECONDS = 10;

    protected string $serverLog;

    #[Test]
    public function when_chromium_fails_then_rendering_fails_with_the_report_error(): void
    {
        // Given Chromium dies while printing
        $cause = new RuntimeException('Chromium process died');
        Pdf::shouldReceive('html')->once()->andThrow($cause);

        try {
            // When the registered renderer renders a report
            $this->theRegisteredReportRenderer()->render($this->aReport());
            $this->fail('A Chromium failure should fail the rendering.');
        } catch (DsarReportRenderingFailedException $failure) {
            // Then rendering fails with the report error, the cause kept
            $this->assertSame($cause, $failure->getPrevious());
        }
    }

    #[Test]
    public function when_rendering_fails_then_no_page_file_outlives_it(): void
    {
        // Given a renderer working in an empty directory of its own, and Chromium dying while printing
        $directory = $this->anEmptyWorkDirectory();
        Pdf::shouldReceive('html')->once()->andThrow(new RuntimeException('Chromium process died'));

        try {
            // When it renders a report
            (new ChromiumDsarReportRenderer(new Filesystem, $directory))->render($this->aReport());
            $this->fail('A Chromium failure should fail the rendering.');
        } catch (DsarReportRenderingFailedException) {
            // Then nothing of the report's page is left there
            $this->assertSame([], (new Filesystem)->files($directory));
        }
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

        // Then rendering fails with the report error rather than returning a broken PDF
        $this->expectException(DsarReportRenderingFailedException::class);

        // When the registered renderer renders a report
        $this->theRegisteredReportRenderer()->render($this->aReport());
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
        // Given a report template that loads an image from a web server answering every request
        $address = $this->aWebServerAnsweringEveryRequest();
        $this->aReportTemplateLoading($scheme.'://'.$address.'/pixel.png');

        // When the registered renderer renders a report
        $this->theRegisteredReportRenderer()->render($this->aReport());

        // Then nothing but the readiness request ever reached that server
        $this->assertNothingElseConnected();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTextReadingLikeALocalAddress(): array
    {
        return [
            'a file address' => ['Nimal file:///etc/passwd Perera'],
            'a source-view address' => ['view-source:Nimal Perera'],
            'a loopback address' => ['Nimal //127.0.0.1/ Perera'],
        ];
    }

    #[Test, DataProvider('provideTextReadingLikeALocalAddress')]
    public function when_personal_data_reads_like_a_local_address_then_the_report_still_renders(string $subjectName): void
    {
        // Given a report whose subject's name reads like a local address
        $report = $this->aReportNaming($subjectName);

        // When the registered renderer renders it
        $pdf = $this->theRegisteredReportRenderer()->render($report);

        // Then a PDF document comes back
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    #[Test]
    public function when_a_report_is_rendered_then_no_page_file_outlives_it(): void
    {
        // Given a renderer working in an empty directory of its own
        $directory = $this->anEmptyWorkDirectory();
        $renderer = new ChromiumDsarReportRenderer(new Filesystem, $directory);

        // When it renders a report
        $renderer->render($this->aReport());

        // Then nothing of the report's page is left there
        $this->assertSame([], (new Filesystem)->files($directory));
    }

    #[Test]
    public function when_a_report_is_rendered_then_a_pdf_typeset_in_the_brand_font_is_returned(): void
    {
        // Given a report
        $report = $this->aReport();

        // When the registered renderer renders it
        $pdf = $this->theRegisteredReportRenderer()->render($report);

        // Then a PDF document comes back, laid out as the report in Inter rather than printed as page source
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/FontFamily (Inter', $pdf);
    }

    protected function anEmptyWorkDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dsar-render-'.Str::random(8);
        mkdir($directory);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));

        return $directory;
    }

    protected function aReport(): DsarReport
    {
        return $this->aReportNaming('Nimal Perera');
    }

    protected function aReportNaming(string $subjectName): DsarReport
    {
        return new DsarReport(new DsarReportCover($subjectName, 'nimal@example.com', 'Lanka Tech Solutions', 'a-request-reference', '1 Oct 2026, 09:00 UTC', '1 Oct 2026, 09:00 UTC'), []);
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
     * PHP's built-in server on a free loopback port over an empty directory: every
     * request is answered at once, so a fetch never leaves Chromium waiting. Its log
     * (one "Accepted" line per connection) goes straight to a file, never through the
     * process's output, and every wait is bounded. One readiness request shows the log
     * records each connection. The server lives as long as the test, and is stopped
     * before its directory is deleted. Returns the server's address.
     */
    protected function aWebServerAnsweringEveryRequest(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dsar-web-'.Str::random(8);
        mkdir($directory.DIRECTORY_SEPARATOR.'docroot', recursive: true);
        $this->serverLog = $directory.DIRECTORY_SEPARATOR.'server.log';
        $address = $this->aFreeLoopbackAddress();
        $server = Process::fromShellCommandline('"${:PHP}" -S "${:ADDRESS}" -t "${:DOCROOT}" 2> "${:LOG}"')
            ->disableOutput()
            ->setTimeout(null);
        $server->start(null, ['PHP' => PHP_BINARY, 'ADDRESS' => $address, 'DOCROOT' => $directory.DIRECTORY_SEPARATOR.'docroot', 'LOG' => $this->serverLog]);
        $this->beforeApplicationDestroyed(function () use ($server, $directory): void {
            $server->stop(0);
            (new Filesystem)->deleteDirectory($directory);
        });
        $this->requestOnceWhenListening($address);
        $this->waitForTheLogToRecordTheReadinessRequest();

        return $address;
    }

    protected function aFreeLoopbackAddress(): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free loopback port.');
        $address = stream_socket_get_name($probe, false) ?: throw new RuntimeException('The probe socket has no address.');
        fclose($probe);

        return $address;
    }

    protected function requestOnceWhenListening(string $address): void
    {
        $deadline = microtime(true) + self::SERVER_WAIT_SECONDS;

        while (! $client = @stream_socket_client('tcp://'.$address, timeout: 0.2)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The server never started listening on '.$address.'.');
            }
            usleep(50_000);
        }

        stream_set_timeout($client, self::SERVER_WAIT_SECONDS);
        fwrite($client, "GET / HTTP/1.0\r\n\r\n");
        stream_get_contents($client);
        fclose($client);
    }

    protected function waitForTheLogToRecordTheReadinessRequest(): void
    {
        $deadline = microtime(true) + self::SERVER_WAIT_SECONDS;

        while (! str_contains($this->serverLogContents(), 'Accepted')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The server does not log its connections.');
            }
            usleep(50_000);
        }
    }

    protected function serverLogContents(): string
    {
        return (string) @file_get_contents($this->serverLog);
    }

    protected function assertNothingElseConnected(): void
    {
        $this->assertSame(1, substr_count($this->serverLogContents(), 'Accepted'), 'Only the readiness request reached the server.');
    }
}
