<?php

namespace Tests\Feature\Foundation\AccessLog;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class PruneExpiredAccessLogRowsTest extends FoundationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00'));
    }

    #[Test]
    public function when_the_date_is_not_a_date_then_the_run_fails_and_nothing_is_deleted(): void
    {
        // Given a row with no company, two years old
        $row = $this->anAccessRow(null, '2024-11-17 10:00:00');

        // When the purge runs with a date that is not a date
        $this->runPrune('not-a-date')->expectsOutput('The --date option must be a date, such as 2026-11-17.')->assertFailed();

        // Then the row is still there
        $this->assertRemaining([$row]);
    }

    #[Test]
    public function when_the_date_is_in_the_future_then_the_run_fails_and_nothing_is_deleted(): void
    {
        // Given a row with no company, two years old
        $row = $this->anAccessRow(null, '2024-11-17 10:00:00');

        // When the purge runs with a date later than now
        $this->runPrune('2026-11-18')->expectsOutput('The --date option cannot be later than now.')->assertFailed();

        // Then the row is still there
        $this->assertRemaining([$row]);
    }

    protected function runPrune(?string $date = null): PendingCommand
    {
        $command = $this->artisan('access-log:prune', $date !== null ? ['--date' => $date] : []);
        assert($command instanceof PendingCommand);

        return $command;
    }

    protected function anAccessRow(?Company $company, string $lastSeenAt): AccessLogEntry
    {
        $factory = AccessLogEntry::factory()->seenAt(CarbonImmutable::parse($lastSeenAt));

        return ($company === null ? $factory : $factory->forCompany($company))->create();
    }

    /**
     * @param  list<AccessLogEntry>  $rows
     */
    protected function assertRemaining(array $rows): void
    {
        $expected = array_map(fn (AccessLogEntry $row): string => $row->getKey(), $rows);
        sort($expected);

        $this->assertSame($expected, AccessLogEntry::query()->orderBy('id')->pluck('id')->all());
    }
}
