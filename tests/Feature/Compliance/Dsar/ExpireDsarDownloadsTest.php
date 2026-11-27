<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use App\Compliance\Dsar\UseCases\Jobs\DeleteDsarExportFileJob;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ExpireDsarDownloadsTest extends DsarTestCase
{
    #[Test]
    public function when_no_download_window_has_closed_then_nothing_is_expired(): void
    {
        // Given a ready export whose download window is still open
        $dsarRequest = $this->aReadyExportFor($this->company, $this->member);

        // When the daily scan runs with no --date (defaults to now)
        $this->runExpireLinks()->expectsOutput('0 download(s) expired.')->assertSuccessful();

        // Then the export is untouched
        $this->assertNotExpired($dsarRequest);
    }

    #[Test]
    public function when_an_expired_download_is_scanned_again_then_it_is_not_expired_twice(): void
    {
        // Given a download the daily scan already expired yesterday
        $dsarRequest = $this->aClosedWindowExport();
        $this->runExpireLinks()->assertSuccessful();
        $expiredAt = now();
        $this->travel(1)->day();

        // When the daily scan runs again today
        $this->runExpireLinks()->expectsOutput('0 download(s) expired.')->assertSuccessful();

        // Then no second expiry is recorded and its expiry time is unchanged
        $this->assertDownloadExpired($dsarRequest, $expiredAt);
    }

    // A concurrent run (a --date replay beside the nightly one) expired it
    // between this run's selection and its own expiry: the stream holds the
    // expiry while the read model this run selected from does not yet.
    #[Test]
    public function when_another_run_expired_the_download_first_then_it_is_neither_counted_nor_audited_again(): void
    {
        // Given a closed-window download already expired on its stream, its row not yet showing it
        $dsarRequest = $this->aClosedWindowExport();
        $this->expireDirectly($dsarRequest);
        DsarRequestRecord::query()->whereKey($dsarRequest->getKey())->update(['download_expired_at' => null]);

        // When the daily scan runs
        $this->runExpireLinks()->expectsOutput('0 download(s) expired.')->assertSuccessful();

        // Then no second expiry is recorded and none is audited
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $dsarRequest->getKey())->where('event_class', DsarDownloadExpired::class)->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $dsarRequest->getKey(), 'action' => 'dsar_download_expired']);
    }

    #[Test]
    public function when_the_date_is_not_a_date_then_the_scan_is_refused_and_nothing_expires(): void
    {
        // Given a ready export whose download window has closed
        $dsarRequest = $this->aClosedWindowExport();

        // When the scan is run with a --date that is not a date
        $this->runExpireLinks('not-a-date')->expectsOutputToContain('The --date option must be a date, such as 2026-10-08.')->assertFailed();

        // Then nothing is expired
        $this->assertNotExpired($dsarRequest);
    }

    #[Test]
    public function when_the_date_is_in_the_future_then_the_scan_is_refused_and_nothing_expires(): void
    {
        // Given a ready export whose download window has closed
        $dsarRequest = $this->aClosedWindowExport();

        // When the scan is run with a --date later than now
        $this->runExpireLinks(now()->addDay()->toDateString())->expectsOutputToContain('The --date option cannot be later than now.')->assertFailed();

        // Then nothing is expired
        $this->assertNotExpired($dsarRequest);
    }

    #[Test]
    #[DataProvider('provideFailingPositions')]
    public function when_one_expiry_fails_then_it_is_reported_and_the_others_still_expire(int $failingPosition): void
    {
        // Given two closed-window exports, the one at the given position failing to expire
        $exports = [$this->anExportFor($this->member), $this->anExportFor($this->aCompanyMember($this->company))];
        $failingDsarRequest = $exports[$failingPosition];
        $otherDsarRequest = $exports[1 - $failingPosition];
        $inducedRuntimeException = $this->failTheAuditOf($failingDsarRequest);
        $this->travel(8)->days();

        // When the daily scan runs
        $this->runExpireLinks()->expectsOutput('1 download(s) expired.')->assertSuccessful();

        // Then the failure is reported, the failed one is left for the next run, the other expired
        $this->assertExpiryFailureReported($failingDsarRequest, $inducedRuntimeException);
        $this->assertNotExpired($failingDsarRequest);
        $this->assertDownloadExpired($otherDsarRequest, now());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideFailingPositions(): array
    {
        return [
            'the first one fails' => [0],
            'the last one fails' => [1],
        ];
    }

    // The aggregate's own no-op arms (SM-1-06): the scan never selects these
    // requests, so they are asked to expire outright.
    #[Test]
    #[DataProvider('provideRequestsThatCannotExpire')]
    public function when_a_request_cannot_expire_then_expiring_it_directly_records_nothing(string $state, int $kept): void
    {
        // Given a request whose download cannot expire now
        $dsarRequest = $this->aRequestInState($state);
        $expiredAt = $dsarRequest->fresh()?->download_expired_at?->toISOString();

        // When its download is expired directly
        $this->expireDirectly($dsarRequest);

        // Then no further expiry is recorded and its row is unchanged
        $this->assertSame($kept, LesilyStoredEvent::query()->where('aggregate_uuid', $dsarRequest->getKey())->where('event_class', DsarDownloadExpired::class)->count());
        $this->assertSame($expiredAt, $dsarRequest->fresh()?->download_expired_at?->toISOString());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideRequestsThatCannotExpire(): array
    {
        return [
            'still being prepared' => ['in progress', 0],
            'withdrawn' => ['withdrawn', 0],
            'rejected' => ['rejected', 0],
            'window still open' => ['window still open', 0],
            'already expired' => ['already expired', 1],
        ];
    }

    #[Test]
    #[DataProvider('provideRequestStates')]
    public function when_the_scan_selects_then_only_a_closed_window_not_yet_expired_is_picked(string $state, bool $picked): void
    {
        // Given a request in the given state
        $dsarRequest = $this->aRequestInState($state);

        // When the scan's selection is read as of now
        $selected = DsarRequestRecord::query()->whereDownloadDueToExpire(now())->pluck('id')->all();

        // Then only a closed download window whose expiry is not yet recorded is picked
        $this->assertSame($picked ? [$dsarRequest->getKey()] : [], $selected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideRequestStates(): array
    {
        return [
            'still being prepared' => ['in progress', false],
            'withdrawn' => ['withdrawn', false],
            'window still open' => ['window still open', false],
            'already expired' => ['already expired', false],
            'window closed, not yet expired' => ['window closed, not yet expired', true],
        ];
    }

    #[Test]
    #[DataProvider('provideWindowEdges')]
    public function when_the_scan_runs_at_the_window_edge_then_only_a_closed_window_expires(int $offsetSeconds, bool $expires): void
    {
        // Given a ready export, with the clock at the close of its window plus the offset
        $dsarRequest = $this->aReadyExportFor($this->company, $this->member);
        $this->travelTo($dsarRequest->download_expires_at?->addSeconds($offsetSeconds));

        // When the daily scan runs
        $this->runExpireLinks()->assertSuccessful();

        // Then the download expires only once its window has closed
        $expires ? $this->assertDownloadExpired($dsarRequest, now()) : $this->assertNotExpired($dsarRequest);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function provideWindowEdges(): array
    {
        return [
            'one second before it closes' => [-1, false],
            'exactly as it closes' => [0, true],
        ];
    }

    #[Test]
    public function when_a_missed_night_is_replayed_then_the_expiry_is_stamped_with_its_date(): void
    {
        // Given a ready export whose window closed before a night the scheduler missed
        $dsarRequest = $this->aReadyExportFor($this->company, $this->member);
        $this->travelTo('2026-10-10 12:00:00');

        // When the scan is replayed for that night
        $this->runExpireLinks('2026-10-09')->expectsOutput('1 download(s) expired.')->assertSuccessful();

        // Then the expiry is stamped with the replayed night, not the moment of the replay
        $this->assertDownloadExpired($dsarRequest, CarbonImmutable::parse('2026-10-09 00:00:00'));
    }

    #[Test]
    public function when_the_download_window_has_closed_then_the_expiry_is_recorded_and_audited(): void
    {
        // Given a ready export whose seven-day window closed a day ago
        $dsarRequest = $this->aClosedWindowExport();

        // When the daily scan runs
        $this->runExpireLinks()->expectsOutput('1 download(s) expired.')->assertSuccessful();

        // Then the expiry is recorded now, audited, and the download is refused as expired
        $this->assertDownloadExpired($dsarRequest, now());
        $this->assertExpiryAudited($dsarRequest, $this->member);
        $this->assertRefusedAsExpired($this->downloadDsarExport($this->member, $this->company, $dsarRequest->getKey()));
    }

    #[Test]
    public function when_a_download_expires_then_its_export_file_is_deleted(): void
    {
        // Given a closed-window export and another member's export still open
        $expiredDsarRequest = $this->aClosedWindowExport();
        $openDsarRequest = $this->anExportFor($this->aCompanyMember($this->company));

        // When the daily scan runs and the worker deletes the expired files
        $this->runExpireLinks()->assertSuccessful();
        $this->deleteTheExpiredFiles();

        // Then only the expired export's file is gone
        Storage::disk('dsar')->assertMissing($this->exportPathOf($expiredDsarRequest));
        Storage::disk('dsar')->assertExists($this->exportPathOf($openDsarRequest));
    }

    #[Test]
    public function when_an_export_file_is_queued_for_deletion_then_the_worker_gets_three_tries(): void
    {
        // Given a closed-window export expired by the daily scan
        $dsarRequest = $this->aClosedWindowExport();
        $this->runExpireLinks()->assertSuccessful();

        // When its expiry handler runs
        $this->runQueuedHandlersFor(DsarDownloadExpired::class);

        // Then the queued deletion names its file and gives the worker three tries, one minute then five apart
        Queue::assertPushed(DeleteDsarExportFileJob::class, fn (DeleteDsarExportFileJob $deleteDsarExportFileJob): bool => [$deleteDsarExportFileJob->filePath, $deleteDsarExportFileJob->tries, $deleteDsarExportFileJob->backoff]
            === [$this->exportPathOf($dsarRequest), 3, [60, 300]]);
    }

    #[Test]
    public function when_several_download_windows_have_closed_then_one_run_expires_them_all(): void
    {
        // Given two members' exports whose download windows have closed
        $firstDsarRequest = $this->anExportFor($this->member);
        $secondDsarRequest = $this->anExportFor($this->aCompanyMember($this->company));
        $this->travel(8)->days();

        // When the daily scan runs
        $this->runExpireLinks()->expectsOutput('2 download(s) expired.')->assertSuccessful();

        // Then both downloads are expired
        $this->assertDownloadExpired($firstDsarRequest, now());
        $this->assertDownloadExpired($secondDsarRequest, now());
    }

    /**
     * $this->member's request in $state, the clock moved past where a
     * fulfilled export's window would close — except for the export whose
     * window is still open; an already-expired one a second after its expiry.
     */
    protected function aRequestInState(string $state): DsarRequestRecord
    {
        $dsarRequest = match ($state) {
            'in progress' => $this->aSubmittedRequest($this->company, $this->member),
            'withdrawn', 'rejected' => $this->aClosedRequest($this->company, $this->member, $state),
            'window still open' => $this->anExportFor($this->member),
            'window closed, not yet expired', 'already expired' => $this->aClosedWindowExport(),
            default => throw new InvalidArgumentException("Unknown state: {$state}"),
        };

        if ($state === 'already expired') {
            $this->runExpireLinks()->assertSuccessful();
            $this->travel(1)->second();
        } elseif (in_array($state, ['in progress', 'withdrawn', 'rejected'], true)) {
            $this->travel(8)->days();
        }

        return $dsarRequest;
    }

    /**
     * The scan reported $dsarRequest's failed expiry by its id, keeping $inducedRuntimeException.
     */
    protected function assertExpiryFailureReported(DsarRequestRecord $dsarRequest, RuntimeException $inducedRuntimeException): void
    {
        Exceptions::assertReported(fn (RuntimeException $reportedRuntimeException): bool => $reportedRuntimeException->getMessage() === "DSAR download expiry failed for request {$dsarRequest->getKey()}."
            && $reportedRuntimeException->getPrevious() === $inducedRuntimeException);
    }

    /**
     * Asks $dsarRequest's aggregate to expire its download now, outside the scan.
     */
    protected function expireDirectly(DsarRequestRecord $dsarRequest): void
    {
        DsarRequest::retrieve($dsarRequest->getKey())->expireDownload(CarbonImmutable::now())->persist();
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
        $dsarRequest = $this->aReadyExportFor($this->company, $this->member);
        $this->travelTo($dsarRequest->download_expires_at?->addDay());

        return $dsarRequest;
    }

    protected function runExpireLinks(?string $date = null): PendingCommand
    {
        $command = $this->artisan('dsar:expire-links', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * $dsarRequest's download was never expired: no DsarDownloadExpired on its
     * stream, no expiry on its row, and its export still on the dsar disk.
     */
    protected function assertNotExpired(DsarRequestRecord $dsarRequest): void
    {
        $this->assertNull($dsarRequest->fresh()?->download_expired_at);
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $dsarRequest->getKey())->where('event_class', DsarDownloadExpired::class)->count());
        Storage::disk('dsar')->assertExists($this->exportPathOf($dsarRequest));
    }
}
