<?php

namespace App\Compliance\Dsar\UseCases\Export;

use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Reports\DsarReportPresenter;
use App\Compliance\Dsar\UseCases\Renderers\DsarReportRendererInterface;

class DsarExport
{
    public function __construct(
        private readonly DsarSections $dsarSections,
        private readonly DsarReportPresenter $dsarReportPresenter,
        private readonly DsarReportRendererInterface $dsarReportRenderer,
        private readonly DsarExportArchive $dsarExportArchive,
    ) {}

    /**
     * The export bundle of the subject's data in $dsarRequest's company, as ZIP
     * bytes: the data, and the report of it.
     */
    public function build(DsarRequestRecord $dsarRequest): string
    {
        $sections = $this->dsarSections->collectFor($dsarRequest->subject_user_id, $dsarRequest->company_id);

        return $this->dsarExportArchive->build($sections, $this->dsarReportRenderer->render($this->dsarReportPresenter->present($dsarRequest, $sections)));
    }
}
