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
        private readonly DsarSections $sections,
        private readonly DsarReportPresenter $presenter,
        private readonly DsarReportRendererInterface $renderer,
        private readonly DsarExportArchive $archive,
    ) {}

    /**
     * The export bundle of the subject's data in $request's company, as ZIP
     * bytes: the data, and the report of it.
     */
    public function build(DsarRequestRecord $request): string
    {
        $sections = $this->sections->collectFor($request->subject_user_id, $request->company_id);

        return $this->archive->build($sections, $this->renderer->render($this->presenter->present($request)));
    }
}
