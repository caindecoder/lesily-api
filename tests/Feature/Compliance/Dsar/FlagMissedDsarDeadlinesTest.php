<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FlagMissedDsarDeadlinesTest extends DsarTestCase
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
    public function when_no_open_request_is_past_its_deadline_then_nothing_is_flagged(): void
    {
        // Given a submitted request whose deadline is still ahead
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When the daily scan runs with no --date (defaults to now)
        $this->runFlagMissedDeadlines()->expectsOutput('0 missed deadline(s) flagged.')->assertSuccessful();

        // Then the request is untouched
        $this->assertNotFlagged($request);
    }

    protected function runFlagMissedDeadlines(?string $date = null): PendingCommand
    {
        $command = $this->artisan('dsar:flag-missed-deadlines', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * $request's deadline was never flagged: no DsarSlaBreached on its
     * stream and no breach on its row.
     */
    protected function assertNotFlagged(DsarRequestRecord $request): void
    {
        $this->assertNull($request->fresh()?->getAttribute('sla_breached_at'));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->where('event_class', DsarSlaBreached::class)->count());
    }
}
