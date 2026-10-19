<?php

namespace Tests\Feature\Foundation\AccessLog;

use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class FoldRepeatedRefusalsTest extends FoundationTestCase
{
    #[Test]
    public function when_five_minutes_have_passed_since_the_first_refusal_then_a_new_row_is_opened(): void
    {
        // Given one refusal on record, five minutes ago to the second
        $this->freezeTime();
        $this->refuseAnonymously();
        $this->travel(5)->minutes();

        // When the same refusal arrives again
        $response = $this->refuseAnonymously();

        // Then it opens a row of its own
        $response->assertUnauthorized();
        $this->assertCountedRows([1, 1]);
    }

    #[Test]
    public function when_repeats_keep_arriving_then_the_window_still_closes_five_minutes_after_the_first(): void
    {
        // Given refusals at minute zero and minute three
        $this->freezeTime();
        $this->refuseAnonymously();
        $this->travel(3)->minutes();
        $this->refuseAnonymously();
        $this->travel(3)->minutes();

        // When the same refusal arrives at minute six
        $response = $this->refuseAnonymously();

        // Then the first row closed at minute five and the third refusal opens a new one
        $response->assertUnauthorized();
        $this->assertCountedRows([2, 1]);
    }

    #[Test]
    public function when_the_same_refusal_repeats_inside_the_window_then_one_row_counts_them(): void
    {
        // Given one refusal on record, a second short of five minutes ago
        $this->freezeTime();
        $this->refuseAnonymously();
        $before = AccessLogEntry::query()->sole();
        $this->travel(299)->seconds();

        // When the same refusal arrives again
        $response = $this->refuseAnonymously();

        // Then the one row counts both, and only its count and last sighting moved
        $response->assertUnauthorized();
        $this->assertCountedAgain($before);
    }

    protected function refuseAnonymously(): TestResponse
    {
        return $this->getJson('/api/_test/authenticated-probe');
    }

    /** @param  list<int>  $occurrences  each row's count, oldest first */
    protected function assertCountedRows(array $occurrences): void
    {
        $this->assertSame($occurrences, AccessLogEntry::query()->orderBy('first_seen_at')->pluck('occurrences')->all());
    }

    protected function assertCountedAgain(AccessLogEntry $before): void
    {
        $after = AccessLogEntry::query()->sole();
        $unchanged = fn (AccessLogEntry $entry): array => Arr::except($entry->getAttributes(), ['occurrences', 'last_seen_at']);

        $this->assertSame(2, $after->occurrences);
        $this->assertSame(now()->toDateTimeString(), $after->last_seen_at->toDateTimeString());
        $this->assertSame($unchanged($before), $unchanged($after));
    }
}
