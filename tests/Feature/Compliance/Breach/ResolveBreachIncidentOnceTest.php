<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use DomainException;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\UseCases\Commands\ResolveBreachCommand;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Compliance\Breach\UseCases\Commands\AddBreachCompanyCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachAmendmentRefusedException;
use App\Compliance\Breach\Exceptions\BreachResolutionRefusedException;
use App\Compliance\Breach\Exceptions\LateBreachCompanyRefusedException;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;

/**
 * The close's finality at the domain seam (conv-plan §2.14 Rule C): every
 * interactor refuses a resolved incident on its locked row first, so the
 * aggregate's own resolved arms are unreachable via HTTP — they protect future
 * callers and replay integrity, and are provable only at this seam.
 */
class ResolveBreachIncidentOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    #[DataProvider('provideCommandsAfterTheClose')]
    public function when_a_resolved_incident_takes_another_command_then_the_aggregate_refuses_it(Closure $command, string $refusal, string $message): void
    {
        // Given an incident company A was notified of, resolved after its deadline
        $breachId = $this->aResolvedIncident();
        $eventsBefore = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count();

        // When the aggregate is given another command
        try {
            $command($this, $breachId);
            $this->fail('A resolved breach incident must accept no further command.');
        } catch (DomainException $refused) {
            // Then it is refused with the reason named, and nothing more is recorded
            $this->assertInstanceOf($refusal, $refused);
            $this->assertSame($message, $refused->getMessage());
            $this->assertSame($eventsBefore, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count());
        }
    }

    /**
     * @return array<string, array{Closure(self, string): void, class-string<DomainException>, string}>
     */
    public static function provideCommandsAfterTheClose(): array
    {
        return [
            'resolve it again' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->resolve($test->aResolutionFor($breachId), $test->admin)->persist(),
                BreachResolutionRefusedException::class,
                'A resolved breach incident cannot be resolved again.',
            ],
            'correct it' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->amend(AmendBreachCommand::from([
                    'breachId' => $breachId,
                    'reason' => 'The description named the wrong export.',
                    'description' => 'A payroll export was emailed to two wrong recipients.',
                ]), $test->admin)->persist(),
                BreachAmendmentRefusedException::class,
                'A resolved breach incident cannot be amended.',
            ],
            'add a late company' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->addLateCompany(AddBreachCompanyCommand::from([
                    'breachId' => $breachId,
                    'companyId' => $test->companyB,
                    'dataTypes' => ['payroll'],
                    'estimatedCount' => 12,
                    'reason' => 'The export also reached this company.',
                ]), $test->admin, ['description' => 'A payroll export was emailed to the wrong recipient.'])->persist(),
                LateBreachCompanyRefusedException::class,
                'A company cannot be added to a resolved breach incident.',
            ],
            'ask Lesily to file it' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->requestFiling(
                    RequestBreachFilingCommand::from(['breachId' => $breachId, 'company' => $test->companyA]),
                    $test->anOwnerOf($test->companyA),
                )->persist(),
                BreachFilingNotOpenException::class,
                'A resolved breach incident takes no filing request.',
            ],
            'file it with the regulator' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)
                    ->fileWithRegulator($test->aFilingCommandFor($breachId, $test->companyA), $test->admin, $test->aRegulatorFiling())
                    ->persist(),
                BreachFilingNotOpenException::class,
                'A resolved breach incident is not filed with the regulator.',
            ],
        ];
    }

    protected function aResolvedIncident(): string
    {
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->travelTo(CarbonImmutable::parse('2026-05-08T10:00:00Z'));
        BreachIncident::retrieve($breachId)->resolve($this->aResolutionFor($breachId), $this->admin)->persist();

        return $breachId;
    }

    protected function aResolutionFor(string $breachId): ResolveBreachCommand
    {
        return ResolveBreachCommand::from([
            'breachId' => $breachId,
            'rootCause' => 'A payroll export job sent files without checking the recipient.',
            'remediationSteps' => 'Exports now need a second person to confirm the recipient.',
            'summary' => 'One file reached one outside person, who deleted it.',
        ]);
    }
}
