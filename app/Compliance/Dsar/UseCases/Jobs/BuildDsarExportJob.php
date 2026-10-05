<?php

namespace App\Compliance\Dsar\UseCases\Jobs;

use Throwable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\RejectDsarRequestCommand;
use App\Compliance\Dsar\Exceptions\DsarRequestNotInProgressException;
use App\Compliance\Dsar\UseCases\Interactors\FulfilDsarRequestInteractor;
use App\Compliance\Dsar\UseCases\Interactors\RejectDsarRequestInteractor;

class BuildDsarExportJob implements ShouldQueue
{
    use Queueable;

    private const string REJECTION_REASON = 'The export could not be built. Please submit a new request.';

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

    /**
     * After the last try: the partial file goes, and the request is rejected
     * with a fixed reason — the error itself stays in the worker's reports.
     */
    public function failed(Throwable $exception): void
    {
        Storage::disk('dsar')->delete($this->filePath());

        try {
            resolve(RejectDsarRequestInteractor::class)->execute(RejectDsarRequestCommand::from([
                'requestId' => $this->requestId,
                'reason' => self::REJECTION_REASON,
            ]));
        } catch (DsarRequestNotInProgressException) {
            // withdrawn while the export kept failing: the withdrawal stands
        }
    }

    private function filePath(): string
    {
        return 'dsar/'.$this->companyId.'/'.$this->requestId.'.zip';
    }
}
