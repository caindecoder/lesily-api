<?php

namespace App\Compliance\Dsar\IO\Pdf;

use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Enums\Format;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
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
        return base64_decode(Pdf::html($html)
            ->format(Format::A4)
            ->withBrowsershot(function (Browsershot $browsershot): void {
                $browsershot->noSandbox()->addChromiumArguments(['disable-dev-shm-usage']);
            })
            ->base64());
    }
}
