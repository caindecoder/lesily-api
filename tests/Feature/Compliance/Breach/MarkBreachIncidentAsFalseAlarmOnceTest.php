<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\UseCases\Commands\ResolveBreachCommand;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Compliance\Breach\UseCases\Commands\AddBreachCompanyCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachAmendmentRefusedException;
use App\Compliance\Breach\Exceptions\BreachFalseAlarmRefusedException;
use App\Compliance\Breach\Exceptions\BreachResolutionRefusedException;
use App\Compliance\Breach\Exceptions\LateBreachCompanyRefusedException;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;
use App\Compliance\Breach\UseCases\Commands\MarkBreachFalseAlarmCommand;

/**
 * The false alarm at the domain seam (conv-plan §2.14 Rule C): every interactor
 * answers an unknown incident 404 and refuses a false alarm on its locked row
 * first, so the aggregate's own refusals are unreachable via HTTP — they protect
 * future callers and replay integrity, and are provable only at this seam.
 */
class MarkBreachIncidentAsFalseAlarmOnceTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_an_incident_was_never_recorded_then_the_aggregate_refuses_the_false_alarm(): void
    {
        // Given a breach id with no recorded incident
        $breachId = Str::uuid7()->toString();

        // When the aggregate is asked to mark it a false alarm
        try {
            BreachIncident::retrieve($breachId)->markFalseAlarm($this->aFalseAlarmFor($breachId), $this->admin)->persist();
            $this->fail('A breach incident never recorded must not be marked a false alarm.');
        } catch (BreachFalseAlarmRefusedException $refused) {
            // Then it is refused with the reason named, and nothing is recorded
            $this->assertSame('Only a recorded breach incident can be marked a false alarm.', $refused->getMessage());
            $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count());
        }
    }

    /**
     * @param  class-string<DomainException>  $refusal
     */
    #[Test]
    #[DataProvider('provideCommandsAfterTheFalseAlarm')]
    public function when_a_false_alarm_takes_another_command_then_the_aggregate_refuses_it(Closure $command, string $refusal, string $message): void
    {
        // Given an incident affecting company A, marked a false alarm before any notice
        $breachId = $this->aFalseAlarmedIncident();
        $eventsBefore = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count();

        // When the aggregate is given another command
        try {
            $command($this, $breachId);
            $this->fail('A breach incident marked as a false alarm must accept no further command.');
        } catch (DomainException $refused) {
            // Then it is refused with the reason named, and nothing more is recorded
            $this->assertInstanceOf($refusal, $refused);
            $this->assertSame($message, $refused->getMessage());
            $this->assertSame($eventsBefore, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count());
        }
    }

    /**
     * @return array<string, array{Closure(self, string): BreachIncident, class-string<DomainException>, string}>
     */
    public static function provideCommandsAfterTheFalseAlarm(): array
    {
        return [
            'mark it again' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->markFalseAlarm($test->aFalseAlarmFor($breachId), $test->admin)->persist(),
                BreachFalseAlarmRefusedException::class,
                'A breach incident already marked as a false alarm cannot be marked again.',
            ],
            'notify its companies' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->notifyCompanies($test->admin, [
                    $test->companyA->getKey() => ['description' => 'A payroll export was emailed to the wrong recipient.'],
                ])->persist(),
                BreachIncidentNotRecordedException::class,
                'Only a recorded breach incident can have its companies notified; this incident is false_alarm.',
            ],
            'correct it' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->amend(AmendBreachCommand::from([
                    'breachId' => $breachId,
                    'reason' => 'The description named the wrong export.',
                    'description' => 'A payroll export was emailed to two wrong recipients.',
                ]), $test->admin)->persist(),
                BreachAmendmentRefusedException::class,
                'A breach incident marked as a false alarm cannot be amended.',
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
                'A company cannot be added to a breach incident marked as a false alarm.',
            ],
            'ask Lesily to file it' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->requestFiling(
                    RequestBreachFilingCommand::from(['breachId' => $breachId, 'company' => $test->companyA]),
                    $test->anOwnerOf($test->companyA),
                )->persist(),
                BreachFilingNotOpenException::class,
                'Only a company notified of this breach can ask Lesily to file it.',
            ],
            'file it with the regulator' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)
                    ->fileWithRegulator($test->aFilingCommandFor($breachId, $test->companyA), $test->admin, $test->aRegulatorFiling())
                    ->persist(),
                BreachFilingNotOpenException::class,
                'A false alarm is not filed with the regulator.',
            ],
            'resolve it' => [
                fn (self $test, string $breachId) => BreachIncident::retrieve($breachId)->resolve(ResolveBreachCommand::from([
                    'breachId' => $breachId,
                    'rootCause' => 'A monitor flagged the company\'s own test export.',
                    'remediationSteps' => 'The monitor now ignores the test export.',
                    'summary' => 'No personal data left Lesily.',
                ]), $test->admin)->persist(),
                BreachResolutionRefusedException::class,
                'A breach incident marked as a false alarm cannot be resolved.',
            ],
        ];
    }

    protected function aFalseAlarmedIncident(): string
    {
        $breachId = $this->aRecordedIncident();
        BreachIncident::retrieve($breachId)->markFalseAlarm($this->aFalseAlarmFor($breachId), $this->admin)->persist();

        return $breachId;
    }

    protected function aFalseAlarmFor(string $breachId): MarkBreachFalseAlarmCommand
    {
        return MarkBreachFalseAlarmCommand::from([
            'breachId' => $breachId,
            'reason' => 'monitoring misconfiguration',
            'findings' => 'The alert fired on the company\'s own test export.',
        ]);
    }
}
