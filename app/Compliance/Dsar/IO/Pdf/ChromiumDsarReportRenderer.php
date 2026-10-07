<?php

namespace App\Compliance\Dsar\IO\Pdf;

use Throwable;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Enums\Format;
use Illuminate\Filesystem\Filesystem;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;
use App\Compliance\Dsar\UseCases\Renderers\DsarReportRendererInterface;

class ChromiumDsarReportRenderer implements DsarReportRendererInterface
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $workDirectory,
    ) {}

    public function render(DsarReport $report): string
    {
        return $this->print(view('dsar.report', ['report' => $report])->render());
    }

    /**
     * The HTML printed to PDF bytes by Chromium — the one step that needs a
     * browser. The page is loaded from a file of its own: Browsershot refuses
     * HTML handed over as a string when its text merely reads like a local
     * address, and a person's own data may.
     */
    protected function print(string $html): string
    {
        $page = sprintf('%s%s%s.html', $this->workDirectory, DIRECTORY_SEPARATOR, Str::uuid7());
        $this->files->put($page, $html);

        try {
            $bytes = base64_decode(Pdf::html('')
                ->format(Format::A4)
                ->withBrowsershot(function (Browsershot $browsershot) use ($page): void {
                    $browsershot->setHtmlFromFilePath($page)
                        ->noSandbox()
                        ->addChromiumArguments(['disable-dev-shm-usage'])
                        ->blockUrls(['http://', 'https://']);
                })
                ->base64());
        } catch (Throwable $e) {
            throw new DsarReportRenderingFailedException('The report could not be rendered.', previous: $e);
        } finally {
            $this->files->delete($page);
        }

        if (! str_starts_with($bytes, '%PDF')) {
            throw new DsarReportRenderingFailedException('The report rendered to an incomplete PDF.');
        }

        return $bytes;
    }
}
