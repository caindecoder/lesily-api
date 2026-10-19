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

    protected function assertCountedAgain(AccessLogEntry $before): void
    {
        $after = AccessLogEntry::query()->sole();
        $unchanged = fn (AccessLogEntry $entry): array => Arr::except($entry->getAttributes(), ['occurrences', 'last_seen_at']);

        $this->assertSame(2, $after->occurrences);
        $this->assertSame(now()->toDateTimeString(), $after->last_seen_at->toDateTimeString());
        $this->assertSame($unchanged($before), $unchanged($after));
    }
}
