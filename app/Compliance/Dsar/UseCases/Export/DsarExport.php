<?php

namespace App\Compliance\Dsar\UseCases\Export;

use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Renderers\DsarReportRendererInterface;

class DsarExport
{
    public function __construct(
        private readonly DsarSections $sections,
        private readonly DsarReportRendererInterface $renderer,
        private readonly DsarExportArchive $archive,
    ) {}

    /**
     * The export bundle of the subject's data in one company, as ZIP bytes.
     */
    public function build(string $subjectUserId, string $companyId): string
    {
        $sections = $this->sections->collectFor($subjectUserId, $companyId);

        return $this->archive->build($sections, $this->renderer->render(new DsarReport));
    }
}
