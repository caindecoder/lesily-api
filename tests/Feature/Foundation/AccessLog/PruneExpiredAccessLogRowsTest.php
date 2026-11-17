<?php

namespace Tests\Feature\Foundation\AccessLog;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Tests\Concerns\AssertsAuditTrail;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class PruneExpiredAccessLogRowsTest extends FoundationTestCase
{
    use AssertsAuditTrail;

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

    #[Test]
    public function when_no_row_is_past_its_retention_then_nothing_is_deleted_or_audited(): void
    {
        // Given a company row at its seven-year cutoff and a row with no company at its one-year cutoff
        $rows = [
            $this->anAccessRow(Company::factory()->create(), '2019-11-17 00:00:00'),
            $this->anAccessRow(null, '2025-11-17 00:00:00'),
        ];

        // When the purge runs
        $this->runPrune()->expectsOutput('0 expired access-log row(s) deleted.')->assertSuccessful();

        // Then both rows are still there and nothing is audited
        $this->assertRemaining($rows);
        $this->assertNothingAudited();
    }

    #[Test]
    public function when_a_company_row_was_last_seen_at_its_cutoff_then_only_the_row_just_before_is_deleted(): void
    {
        // Given a company keeping seven years, with a row at its cutoff and one a second before it
        $company = Company::factory()->create();
        $atCutoff = $this->anAccessRow($company, '2019-11-17 00:00:00');
        $this->anAccessRow($company, '2019-11-16 23:59:59');

        // When the purge runs
        $this->runPrune()->assertSuccessful();

        // Then only the row at the cutoff is left
        $this->assertRemaining([$atCutoff]);
    }

    #[Test]
    public function when_a_row_with_no_company_reaches_one_year_then_only_the_row_just_before_the_cutoff_is_deleted(): void
    {
        // Given two rows with no company: one at the one-year cutoff, one a second before it
        $atCutoff = $this->anAccessRow(null, '2025-11-17 00:00:00');
        $this->anAccessRow(null, '2025-11-16 23:59:59');

        // When the purge runs
        $this->runPrune()->assertSuccessful();

        // Then only the row at the cutoff is left
        $this->assertRemaining([$atCutoff]);
    }

    #[Test]
    public function when_two_companies_hold_different_retentions_then_each_is_cut_at_its_own_years(): void
    {
        // Given a two-year-old row in a company keeping one year, and one in a company on the default seven
        $this->anAccessRow(Company::factory()->keepingAccessLogFor(1)->create(), '2024-11-17 10:00:00');
        $sevenYearRow = $this->anAccessRow(Company::factory()->create(), '2024-11-17 10:00:00');

        // When the purge runs
        $this->runPrune()->assertSuccessful();

        // Then only the seven-year company's row is left
        $this->assertRemaining([$sevenYearRow]);
    }

    #[Test]
    public function when_a_folded_row_was_first_seen_before_the_cutoff_and_last_seen_after_it_then_it_is_kept(): void
    {
        // Given a refusal first seen before the seven-year cutoff and repeated after it
        $folded = AccessLogEntry::factory()
            ->forCompany(Company::factory()->create())
            ->seenAt(CarbonImmutable::parse('2020-01-01 00:00:00'))
            ->foldedSince(CarbonImmutable::parse('2019-11-10 00:00:00'), 4)
            ->create();

        // When the purge runs
        $this->runPrune()->assertSuccessful();

        // Then the row is kept: its last sighting is inside the retention
        $this->assertRemaining([$folded]);
    }

    #[Test]
    public function when_the_run_falls_on_29_february_then_the_cutoff_is_28_february(): void
    {
        // Given a run on 29 February, and rows with no company on 28 February a year back and a second before
        $this->travelTo(CarbonImmutable::parse('2028-02-29 10:00:00'));
        $onThe28th = $this->anAccessRow(null, '2027-02-28 00:00:00');
        $this->anAccessRow(null, '2027-02-27 23:59:59');

        // When the purge runs
        $this->runPrune()->assertSuccessful();

        // Then the row on the 28th is kept
        $this->assertRemaining([$onThe28th]);
    }

    #[Test]
    public function when_a_missed_night_is_replayed_then_the_cutoff_counts_back_from_the_given_date(): void
    {
        // Given rows with no company just inside and just outside a year before 10 November
        $insideTheReplayYear = $this->anAccessRow(null, '2025-11-10 00:00:00');
        $this->anAccessRow(null, '2025-11-09 23:59:59');

        // When the night of 10 November is replayed
        $this->runPrune('2026-11-10')->assertSuccessful();

        // Then the row inside that year is kept, though it is more than a year before today
        $this->assertRemaining([$insideTheReplayYear]);
    }

    #[Test]
    public function when_a_replayed_date_carries_an_offset_then_its_utc_day_sets_the_cutoff(): void
    {
        // Given rows with no company at the cutoff of 16 November UTC and a second before it
        $onTheUtcDay = $this->anAccessRow(null, '2025-11-16 00:00:00');
        $this->anAccessRow(null, '2025-11-15 23:59:59');

        // When a date of 17 November in Colombo, still 16 November in UTC, is replayed
        $this->runPrune('2026-11-17T03:00:00+05:30')->assertSuccessful();

        // Then the cutoff is the UTC day's, so the row on 16 November is kept
        $this->assertRemaining([$onTheUtcDay]);
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
