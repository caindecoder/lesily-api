<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;

/**
 * The filing-request guard at the domain seam (conv-plan §2.14 Rule C): the
 * request interactor refuses on the locked incident and company rows first,
 * so the aggregate's own guard is unreachable via HTTP — it protects future
 * callers and replay integrity, and is provable only at this seam.
 */
class RequestBreachFilingOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    #[DataProvider('provideRequestsTheAggregateRefuses')]
    public function when_a_company_not_told_or_already_asking_asks_again_then_the_aggregate_refuses_it(Closure $given, string $message, int $requestsBefore): void
    {
        // Given an incident company A was never told of, or one A has already asked Lesily to file
        $breachId = $given($this);

        // When company A's owner asks Lesily to file it
        try {
            BreachIncident::retrieve($breachId)->requestFiling($this->aRequestFor($breachId), $this->aCompanyMember($this->companyA, 'owner'));
            $this->fail('A request from a company not told, or one already asking, must be refused.');
        } catch (BreachFilingNotOpenException $refusal) {
            // Then it is refused with the reason and no further request is recorded
            $this->assertSame($message, $refusal->getMessage());
            $this->assertSame($requestsBefore, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachFilingRequested::class)->count());
        }
    }

    /**
     * @return array<string, array{Closure(self): string, string, int}>
     */
    public static function provideRequestsTheAggregateRefuses(): array
    {
        return [
            'a company not notified' => [
                fn (self $test): string => $test->aRecordedIncident(),
                'Only a company notified of this breach can ask Lesily to file it.',
                0,
            ],
            'a second request' => [
                fn (self $test): string => $test->anIncidentAlreadyAskedToBeFiled(),
                'Lesily has already been asked to file this breach for the company.',
                1,
            ],
            'a request after the filing' => [
                fn (self $test): string => $test->anIncidentFiledFor($test->companyA),
                'Lesily has already filed this breach with the regulator for the company.',
                1,
            ],
        ];
    }

    protected function anIncidentAlreadyAskedToBeFiled(): string
    {
        $breachId = $this->aNotifiedIncident($this->companyA);
        BreachIncident::retrieve($breachId)
            ->requestFiling($this->aRequestFor($breachId), $this->aCompanyMember($this->companyA, 'owner'))
            ->persist();

        return $breachId;
    }

    protected function aRequestFor(string $breachId): RequestBreachFilingCommand
    {
        return RequestBreachFilingCommand::from(['breachId' => $breachId, 'company' => $this->companyA]);
    }
}
