<?php

namespace App\Compliance\Dsar\UseCases\Jobs;

use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;
use App\Compliance\Dsar\Exceptions\DsarRequestNotInProgressException;
use App\Compliance\Dsar\UseCases\Interactors\FulfilDsarRequestInteractor;

class BuildDsarExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $requestId,
        public string $companyId,
        public string $subjectUserId,
    ) {}

    public function handle(DsarSections $sections, DsarExportArchive $archive, FulfilDsarRequestInteractor $fulfil): void
    {
        Storage::disk('dsar')->put($this->filePath(), $archive->build($sections->collectFor($this->subjectUserId, $this->companyId)));

        try {
            $fulfil->execute(FulfilDsarRequestCommand::from([
                'requestId' => $this->requestId,
                'filePath' => $this->filePath(),
            ]));
        } catch (DsarRequestNotInProgressException) {
            Storage::disk('dsar')->delete($this->filePath());
        }
    }

    private function filePath(): string
    {
        return 'dsar/'.$this->companyId.'/'.$this->requestId.'.zip';
    }
}
