<?php

namespace App\Compliance\Dsar\UseCases\Jobs;

use Throwable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Export\DsarExport;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\RejectDsarRequestCommand;
use App\Compliance\Dsar\Exceptions\DsarRequestNotInProgressException;
use App\Compliance\Dsar\UseCases\Interactors\FulfilDsarRequestInteractor;
use App\Compliance\Dsar\UseCases\Interactors\RejectDsarRequestInteractor;

class BuildDsarExportJob implements ShouldQueue
{
    use Queueable;

    private const string REJECTION_REASON = 'The export could not be built. Please submit a new request.';

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public string $requestId,
        public string $companyId,
        public string $subjectUserId,
    ) {}

    public function handle(DsarExport $export, FulfilDsarRequestInteractor $fulfil): void
    {
        if ($this->status() !== DsarRequestStatus::InProgress) {
            $this->discardExportUnlessFulfilled();

            return;
        }

        $dsarRequest = DsarRequestRecord::query()->with('subject')->findOrFail($this->requestId);

        Storage::disk('dsar')->put($this->filePath(), $export->build($dsarRequest));

        try {
            $fulfil->execute(FulfilDsarRequestCommand::from([
                'dsarRequest' => $dsarRequest,
                'filePath' => $this->filePath(),
            ]));
        } catch (DsarRequestNotInProgressException) {
            $this->discardExportUnlessFulfilled();
        }
    }

    /**
     * After the last try: the partial file goes, and the request is rejected
     * with a fixed reason — the error itself stays in the worker's reports.
     */
    public function failed(Throwable $exception): void
    {
        try {
            resolve(RejectDsarRequestInteractor::class)->execute(RejectDsarRequestCommand::from([
                'dsarRequest' => DsarRequestRecord::query()->findOrFail($this->requestId),
                'reason' => self::REJECTION_REASON,
            ]));
        } catch (DsarRequestNotInProgressException) {
            // closed meanwhile — withdrawn, or fulfilled by a sibling run: that outcome stands
        } finally {
            $this->discardExportUnlessFulfilled();
        }
    }

    /**
     * A personal-data file outlives its request only as a fulfilled export —
     * any other file at its path is a leftover and goes (ruling N27).
     */
    protected function discardExportUnlessFulfilled(): void
    {
        if ($this->status() !== DsarRequestStatus::Fulfilled) {
            Storage::disk('dsar')->delete($this->filePath());
        }
    }

    protected function status(): DsarRequestStatus
    {
        return DsarRequestRecord::query()->findOrFail($this->requestId)->status;
    }

    protected function filePath(): string
    {
        return 'dsar/'.$this->companyId.'/'.$this->requestId.'.zip';
    }
}
