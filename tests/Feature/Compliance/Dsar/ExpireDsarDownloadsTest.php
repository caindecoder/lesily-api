<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use App\Compliance\Dsar\UseCases\Jobs\DeleteDsarExportFileJob;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ExpireDsarDownloadsTest extends DsarTestCase
{
    protected Company $company;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->member = $this->aCompanyMember($this->company);
    }

    #[Test]
    public function when_no_download_window_has_closed_then_nothing_is_expired(): void
    {
        // Given a ready export whose download window is still open
        $request = $this->aReadyExportFor($this->company, $this->member);

        // When the daily scan runs with no --date (defaults to now)
        $this->runExpireLinks()->expectsOutputToContain('0 download(s) expired.')->assertSuccessful();

        // Then the export is untouched
        $this->assertNotExpired($request);
    }

    #[Test]
    public function when_the_download_window_has_closed_then_the_expiry_is_recorded_and_audited(): void
    {
        // Given a ready export whose seven-day window closed a day ago
        $request = $this->aClosedWindowExport();

        // When the daily scan runs
        $this->runExpireLinks()->expectsOutputToContain('1 download(s) expired.')->assertSuccessful();

        // Then the expiry is recorded now, audited, and the download is refused as expired
        $this->assertDownloadExpired($request, now());
        $this->assertExpiryAudited($request, $this->member);
        $this->assertRefusedAsExpired($this->downloadDsarExport($this->member, $this->company, $request->getKey()));
    }

    #[Test]
    public function when_a_download_expires_then_its_export_file_is_deleted(): void
    {
        // Given a closed-window export and another member's export still open
        $expired = $this->aClosedWindowExport();
        $open = $this->anExportFor($this->aCompanyMember($this->company));

        // When the daily scan runs and the worker deletes the expired files
        $this->runExpireLinks()->assertSuccessful();
        $this->deleteTheExpiredFiles();

        // Then only the expired export's file is gone
        Storage::disk('dsar')->assertMissing($this->exportPathOf($expired));
        Storage::disk('dsar')->assertExists($this->exportPathOf($open));
    }

    #[Test]
    public function when_an_export_file_is_queued_for_deletion_then_the_worker_gets_three_tries(): void
    {
        // Given a closed-window export expired by the daily scan
        $request = $this->aClosedWindowExport();
        $this->runExpireLinks()->assertSuccessful();

        // When its expiry handler runs
        $this->runQueuedHandlersFor(DsarDownloadExpired::class);

        // Then the queued deletion names its file and gives the worker three tries, one minute then five apart
        Queue::assertPushed(DeleteDsarExportFileJob::class, fn (DeleteDsarExportFileJob $job): bool => [$job->filePath, $job->tries, $job->backoff]
            === [$this->exportPathOf($request), 3, [60, 300]]);
    }

    /**
     * $subject's ready export in this company — its window opens now.
     */
    protected function anExportFor(User $subject): DsarRequestRecord
    {
        return $this->aReadyExportFor($this->company, $subject);
    }

    /**
     * $this->member's ready export, with the clock a day past the close of its
     * seven-day download window.
     */
    protected function aClosedWindowExport(): DsarRequestRecord
    {
        $request = $this->aReadyExportFor($this->company, $this->member);
        $this->travelTo($request->download_expires_at?->addDay());

        return $request;
    }

    protected function runExpireLinks(?string $date = null): PendingCommand
    {
        $command = $this->artisan('dsar:expire-links', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * $request's download was never expired: no DsarDownloadExpired on its
     * stream, no expiry on its row, and its export still on the dsar disk.
     */
    protected function assertNotExpired(DsarRequestRecord $request): void
    {
        $this->assertNull($request->fresh()?->download_expired_at);
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->where('event_class', DsarDownloadExpired::class)->count());
        Storage::disk('dsar')->assertExists($this->exportPathOf($request));
    }
}
