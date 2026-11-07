<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The regulator-filing guards at the domain seam (conv-plan §2.14 Rule C): the
 * filing interactor refuses on the locked incident and company rows first, so
 * the aggregate's own guards are unreachable via HTTP — they protect future
 * callers and replay integrity, and are provable only at this seam.
 */
class FileBreachWithRegulatorOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    #[DataProvider('provideFilingsTheAggregateRefuses')]
    public function when_a_company_not_asking_or_already_filed_for_is_filed_then_the_aggregate_refuses_it(Closure $given, string $message, int $filingsBefore): void
    {
        // Given an incident company A has not asked Lesily to file, or one already filed for A
        $breachId = $given($this);

        // When an admin files it for company A
        try {
            BreachIncident::retrieve($breachId)->fileWithRegulator($this->aFilingCommandFor($breachId, $this->companyA), $this->admin, $this->aRegulatorFiling());
            $this->fail('A filing for a company that has not asked, or one already filed for, must be refused.');
        } catch (BreachFilingNotOpenException $refusal) {
            // Then it is refused with the reason and no further filing is recorded
            $this->assertSame($message, $refusal->getMessage());
            $this->assertSame($filingsBefore, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachFiledWithRegulator::class)->count());
        }
    }

    /**
     * @return array<string, array{Closure(self): string, string, int}>
     */
    public static function provideFilingsTheAggregateRefuses(): array
    {
        return [
            'a company that has not asked' => [
                fn (self $test): string => $test->aNotifiedIncident($test->companyA),
                'The company has not asked Lesily to file this breach.',
                0,
            ],
            'a second filing' => [
                fn (self $test): string => $test->anIncidentFiledFor($test->companyA),
                'Lesily has already filed this breach with the regulator for the company.',
                1,
            ],
        ];
    }
}
