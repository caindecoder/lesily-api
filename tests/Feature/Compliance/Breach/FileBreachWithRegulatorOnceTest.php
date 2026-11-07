<?php

namespace Tests\Feature\Compliance\Breach;

use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The once-only filing guard at the domain seam (conv-plan §2.14 Rule C): the
 * filing interactor refuses a company already filed for on the locked company
 * row first, so the aggregate's own guard is unreachable via HTTP — it protects
 * future callers and replay integrity, and is provable only at this seam.
 */
class FileBreachWithRegulatorOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_a_company_already_filed_for_is_filed_again_then_the_aggregate_refuses_it(): void
    {
        // Given an incident already filed with the regulator for company A
        $breachId = $this->anIncidentFiledFor($this->companyA);

        // When an admin files it for company A again
        try {
            BreachIncident::retrieve($breachId)->fileWithRegulator($this->aFilingCommandFor($breachId, $this->companyA), $this->admin, $this->aRegulatorFiling());
            $this->fail('A second filing for a company must be refused.');
        } catch (BreachFilingNotOpenException $refusal) {
            // Then it is refused with the reason and no second filing is recorded
            $this->assertSame('Lesily has already filed this breach with the regulator for the company.', $refusal->getMessage());
            $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachFiledWithRegulator::class)->count());
        }
    }
}
