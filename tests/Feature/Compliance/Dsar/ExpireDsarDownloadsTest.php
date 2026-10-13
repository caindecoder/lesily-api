<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
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
