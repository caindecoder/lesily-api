<?php

namespace App\Compliance\Dsar\IO\Pdf;

use Throwable;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Enums\Format;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;
use App\Compliance\Dsar\UseCases\Renderers\DsarReportRendererInterface;

class ChromiumDsarReportRenderer implements DsarReportRendererInterface
{
    public function render(DsarReport $report): string
    {
        return $this->print(view('dsar.report', ['report' => $report])->render());
    }

    /**
     * The HTML printed to PDF bytes by Chromium — the one step that needs a
     * browser.
     */
    protected function print(string $html): string
    {
        try {
            $bytes = base64_decode(Pdf::html($html)
                ->format(Format::A4)
                ->withBrowsershot(function (Browsershot $browsershot): void {
                    $browsershot->noSandbox()
                        ->addChromiumArguments(['disable-dev-shm-usage'])
                        ->blockUrls(['http://', 'https://']);
                })
                ->base64());
        } catch (Throwable $e) {
            throw new DsarReportRenderingFailedException('The report could not be rendered.', previous: $e);
        }

        if (! str_starts_with($bytes, '%PDF')) {
            throw new DsarReportRenderingFailedException('The report rendered to an incomplete PDF.');
        }

        return $bytes;
    }
}
