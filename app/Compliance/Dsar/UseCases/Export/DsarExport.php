<?php

namespace App\Compliance\Dsar\UseCases\Export;

use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;

class DsarExport
{
    public function __construct(
        private readonly DsarSections $sections,
        private readonly DsarExportArchive $archive,
    ) {}

    /**
     * The export bundle of the subject's data in one company, as ZIP bytes.
     */
    public function build(string $subjectUserId, string $companyId): string
    {
        return $this->archive->build($this->sections->collectFor($subjectUserId, $companyId));
    }
}
