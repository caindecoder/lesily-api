<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;

/**
 * The notify guard at the domain seam (conv-plan §2.14 Rule C): the notify
 * interactor refuses on the locked incident row first, so the aggregate's own
 * guard is unreachable via HTTP — it protects future callers and replay
 * integrity, and is provable only at this seam.
 */
class NotifyBreachCompaniesOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    #[DataProvider('provideStagesTheAggregateRefuses')]
    public function when_an_incident_not_at_recorded_is_notified_then_the_aggregate_refuses_it(bool $recorded, int $notifiedBefore, string $message): void
    {
        // Given an incident never recorded, or one whose companies were already notified
        $breachId = $recorded ? $this->aRecordedIncident() : Str::uuid7()->toString();
        $parts = [$this->companyA->getKey() => ['description' => 'A payroll export was emailed to the wrong recipient.']];
        if ($notifiedBefore > 0) {
            BreachIncident::retrieve($breachId)->notifyCompanies($this->admin, $parts)->persist();
        }

        // When its companies are notified
        try {
            BreachIncident::retrieve($breachId)->notifyCompanies($this->admin, $parts);
            $this->fail('Notifying an incident that is not at Recorded must be refused.');
        } catch (BreachIncidentNotRecordedException $refusal) {
            // Then it is refused with its stage named and no further notice is recorded
            $this->assertSame($message, $refusal->getMessage());
            $this->assertSame($notifiedBefore, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachCompaniesNotified::class)->count());
        }
    }

    /**
     * @return array<string, array{bool, int, string}>
     */
    public static function provideStagesTheAggregateRefuses(): array
    {
        return [
            'never recorded' => [false, 0, 'Only a recorded breach incident can have its companies notified; this incident is not recorded.'],
            'already notified' => [true, 1, 'Only a recorded breach incident can have its companies notified; this incident is companies_notified.'],
        ];
    }
}
