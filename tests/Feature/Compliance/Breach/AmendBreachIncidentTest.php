<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachAmended;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Entities\BreachAmendment;
use Database\Factories\BreachIncidentRecordFactory;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class AmendBreachIncidentTest extends BreachIncidentsTestCase
{
    protected const string COMPANY_A = 'company A';

    protected const string COMPANY_A_UPPERCASE = 'company A in upper case';

    protected const string REASON = 'An earlier log entry showed the export reached a second shared folder.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
    }

    #[Test]
    public function when_an_unauthenticated_caller_amends_an_incident_then_it_is_refused_as_unauthenticated(): void
    {
        // Given a recorded incident
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When a caller with no token corrects its description
        $response = $this->amendIncident(null, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then they are refused as unauthenticated and nothing is amended
        $this->assertUnauthenticated($response);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_amends_an_incident_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a recorded incident affecting company A, and company A's owner
        $breachId = $this->aRecordedIncident();
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->snapshotOf($breachId);

        // When the owner corrects its description on the admin surface
        $response = $this->amendIncident($owner, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then the admin surface is not acknowledged and nothing is amended
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id
        $correction = $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']);

        // When the admin corrects it
        $response = $this->amendIncident($this->admin, $breachId, $correction);

        // Then it is not found and nothing is amended
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachAmended::class)->count());
    }

    #[Test, DataProvider('provideInvalidAmendments')]
    public function when_the_amendment_is_invalid_then_it_is_refused_and_nothing_is_amended(array $changes, string $field, string $message): void
    {
        // Given a recorded incident and a correction with one invalid value
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin sends the correction
        $response = $this->amendIncident($this->admin, $breachId, $this->correctionWith($changes));

        // Then it is refused on that value and nothing is amended
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test, DataProvider('provideFieldsThatCannotBeAmended')]
    public function when_a_field_that_cannot_be_amended_is_sent_then_it_is_refused_and_nothing_is_amended(string $field, string $value): void
    {
        // Given a recorded incident
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin sends a correction that also sets a field no correction may set
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive', $field => $value]));

        // Then it is refused on that field and nothing is amended
        $this->assertValidationRejected($response, $field, 'This field cannot be updated.');
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test, DataProvider('provideNoChanges')]
    public function when_nothing_differs_from_the_current_record_then_it_is_refused_and_nothing_is_recorded(array $changes): void
    {
        // Given a recorded incident
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin sends a correction whose every value is the current one
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection($changes));

        // Then it is refused as nothing to amend, and no event, history or audit row is written
        $this->assertValidationRejected($response, 'amendment', 'Nothing to amend: every value sent matches the incident.');
        $this->assertNothingAmended($breachId, $before);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideNoChanges(): array
    {
        return [
            'only a reason' => [[]],
            'the same description' => [['description' => 'A payroll export was emailed to the wrong recipient.']],
            'the same severity' => [['severity' => 'high']],
            'the same likely consequences, measures and discovery moment' => [[
                'likelyConsequences' => 'Salary and bank details may be read by one outside person.',
                'measures' => 'The recipient was asked to delete the file; exports now need a second check.',
                'dateDiscovered' => '2026-05-04T14:00:00Z',
            ]],
        ];
    }

    #[Test]
    public function when_the_same_company_list_is_sent_then_it_is_refused_as_nothing_to_amend(): void
    {
        // Given a recorded incident affecting company A (pii, about 40 people)
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin sends company A with the same data types and count
        $response = $this->amendIncident($this->admin, $breachId, $this->correctionWith([]));

        // Then it is refused as nothing to amend and nothing is amended
        $this->assertValidationRejected($response, 'amendment', 'Nothing to amend: every value sent matches the incident.');
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test, DataProvider('provideClosedStages')]
    public function when_the_incident_is_closed_then_the_amendment_is_refused(Closure $closed, string $message): void
    {
        // Given a closed incident
        $breachId = $closed($this->anIncident())->createOne()->getKey();
        $before = $this->snapshotOf($breachId);

        // When the admin corrects its description
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then the correction is refused with the stage named, and nothing is amended
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test, DataProvider('provideLockedFacts')]
    public function when_a_locked_fact_is_changed_after_the_notices_then_it_is_refused(array $change, string $message): void
    {
        // Given an incident discovered 2026-05-04 14:00 UTC with severity high, whose companies A and B were notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->snapshotOf($breachId);

        // When the admin changes a fact the notices locked
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection($change));

        // Then the correction is refused with the fact named, the deadline stays 2026-05-07 14:00 UTC, and nothing is amended
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_company_is_dropped_after_the_notices_then_it_is_refused(): void
    {
        // Given an incident whose companies A and B were notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->snapshotOf($breachId);

        // When the admin sends the company list with company A only
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [$this->aNotifiedPartOf($this->companyA)]]));

        // Then the correction is refused as the list is locked, and company B is still on the incident
        $this->assertRefusedAsNotOpen($response, 'The company list is locked once the companies are notified.');
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_company_is_added_through_the_list_after_the_notices_then_it_is_refused(): void
    {
        // Given an incident whose company A was notified
        $breachId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($breachId);

        // When the admin sends the company list with companies A and B
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [$this->aNotifiedPartOf($this->companyA), $this->aNotifiedPartOf($this->companyB)]]));

        // Then the correction is refused as the list is locked, and only company A is on the incident
        $this->assertRefusedAsNotOpen($response, 'The company list is locked once the companies are notified.');
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_facts_are_corrected_before_the_notices_then_they_are_recorded_projected_audited_and_the_current_facts_are_returned(): void
    {
        // Given a recorded incident affecting company A (pii)
        $breachId = $this->aRecordedIncident();

        // When the admin corrects the description, the severity, the measures and company A's data types with one reason
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection([
            'description' => 'Payroll and bank export left on a shared drive',
            'severity' => 'critical',
            'measures' => 'The shared drive was locked and its access log is being reviewed.',
            'companies' => [['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii', 'banking'], 'estimatedCount' => 40]],
        ]));

        // Then the current facts are returned at the recorded stage, one event carries the four changes,
        // the history holds a row for each, and the request left one audit row with no company
        $response->assertOk();
        $this->assertSame(
            ['recorded', 'critical', 'Payroll and bank export left on a shared drive', 'The shared drive was locked and its access log is being reviewed.', ['pii', 'banking']],
            [$response->json('data.stage'), $response->json('data.severity'), $response->json('data.description'), $response->json('data.measures'), $response->json('data.companies.0.data_types')],
        );
        $storedEventId = $this->assertAmendedOnce($breachId, [
            ['field' => 'description', 'companyId' => null, 'old' => 'A payroll export was emailed to the wrong recipient.', 'new' => 'Payroll and bank export left on a shared drive'],
            ['field' => 'measures', 'companyId' => null, 'old' => 'The recipient was asked to delete the file; exports now need a second check.', 'new' => 'The shared drive was locked and its access log is being reviewed.'],
            ['field' => 'severity', 'companyId' => null, 'old' => 'high', 'new' => 'critical'],
            ['field' => 'data_types', 'companyId' => $this->companyA->getKey(), 'old' => ['pii'], 'new' => ['pii', 'banking']],
        ]);
        $this->assertHistory($breachId, [
            ['data_types', $this->companyA->getKey(), ['pii'], ['pii', 'banking']],
            ['description', null, 'A payroll export was emailed to the wrong recipient.', 'Payroll and bank export left on a shared drive'],
            ['measures', null, 'The recipient was asked to delete the file; exports now need a second check.', 'The shared drive was locked and its access log is being reviewed.'],
            ['severity', null, 'high', 'critical'],
        ]);
        $this->assertAmendAudited($response, $breachId, $storedEventId);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideLockedFacts(): array
    {
        return [
            'an earlier discovery moment' => [['dateDiscovered' => '2026-05-03T14:00:00Z'], 'The discovery date is locked once the companies are notified.'],
            'a higher severity' => [['severity' => 'critical'], 'The severity is locked once the companies are notified.'],
        ];
    }

    /**
     * @return array<string, array{Closure, string}>
     */
    public static function provideClosedStages(): array
    {
        return [
            'resolved' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->resolved(), 'A resolved breach incident cannot be amended.'],
            'a false alarm' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm(), 'A breach incident marked as a false alarm cannot be amended.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideFieldsThatCannotBeAmended(): array
    {
        return [
            'the stage' => ['stage', 'resolved'],
            'the deadline' => ['deadline', '2026-05-30T14:00:00Z'],
            'the recording moment' => ['recordedAt', '2026-05-01T09:00:00Z'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidAmendments(): array
    {
        return [
            'no reason' => [['reason' => null], 'reason', 'The reason field is required.'],
            'a reason over 1,000 characters' => [['reason' => str_repeat('a', 1001)], 'reason', 'The reason field must not be greater than 1000 characters.'],
            'a reason that is not text' => [['reason' => ['a list']], 'reason', 'The reason field must be a string.'],
            'no company list' => [['companies' => null], 'companies', 'At least one affected company must be selected.'],
            'no affected company' => [['companies' => []], 'companies', 'At least one affected company must be selected.'],
            'a company entry that is not a company' => [['companies.0' => 'company A'], 'companies.0', 'The companies.0 field must be an array.'],
            'a company without its id' => [['companies.0.companyId' => null], 'companies.0.companyId', 'The companies.0.companyId field is required.'],
            'a company id that is not a uuid' => [['companies.0.companyId' => 'company-a'], 'companies.0.companyId', 'The companies.0.companyId field must be a valid UUID.'],
            'a company that does not exist' => [['companies.0.companyId' => '01980000-0000-7000-8000-000000000000'], 'companies.0.companyId', 'The selected companies.0.companyId is invalid.'],
            'the same company twice' => [['companies.1.companyId' => self::COMPANY_A, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'the same company twice in another case' => [['companies.1.companyId' => self::COMPANY_A_UPPERCASE, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'a company with no data type' => [['companies.0.dataTypes' => []], 'companies.0.dataTypes', 'The companies.0.dataTypes field is required.'],
            'a data type outside the seven' => [['companies.0.dataTypes' => ['passwords']], 'companies.0.dataTypes.0', 'The selected companies.0.dataTypes.0 is invalid.'],
            'a negative count' => [['companies.0.estimatedCount' => -1], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be at least 0.'],
            'a count beyond the largest the record holds' => [['companies.0.estimatedCount' => 4294967296], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must not be greater than 4294967295.'],
            'a count that is not a number' => [['companies.0.estimatedCount' => 'many'], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be an integer.'],
            'an emptied description' => [['description' => null], 'description', 'The description field is required.'],
            'emptied likely consequences' => [['likelyConsequences' => null], 'likelyConsequences', 'The likely consequences field is required.'],
            'emptied measures' => [['measures' => null], 'measures', 'The measures field is required.'],
            'a description over 1,000 characters' => [['description' => str_repeat('a', 1001)], 'description', 'The description field must not be greater than 1000 characters.'],
            'likely consequences over 1,000 characters' => [['likelyConsequences' => str_repeat('a', 1001)], 'likelyConsequences', 'The likely consequences field must not be greater than 1000 characters.'],
            'measures over 1,000 characters' => [['measures' => str_repeat('a', 1001)], 'measures', 'The measures field must not be greater than 1000 characters.'],
            'discovered one second from now' => [['dateDiscovered' => '2026-05-05T09:00:01Z'], 'dateDiscovered', 'The date discovered field must be a date before or equal to now.'],
            'an emptied discovery moment' => [['dateDiscovered' => null], 'dateDiscovered', 'The date discovered field is required.'],
            'a discovery moment without a time' => [['dateDiscovered' => '2026-05-04'], 'dateDiscovered', 'The date discovered field must match the format Y-m-d\TH:i:sp.'],
            'an emptied severity' => [['severity' => null], 'severity', 'The severity field is required.'],
            'a severity outside the four' => [['severity' => 'extreme'], 'severity', 'The selected severity is invalid.'],
        ];
    }

    protected function amendIncident(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/breach-incidents/{$breachId}", $body);
    }

    protected function aCorrection(array $changes): array
    {
        return ['reason' => self::REASON, ...$changes];
    }

    protected function aNotifiedPartOf(Company $company): array
    {
        return ['companyId' => $company->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40];
    }

    protected function correctionWith(array $changes): array
    {
        $correction = $this->aCorrection(['companies' => [['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40]]]);

        foreach ($changes as $path => $value) {
            Arr::set($correction, $path, match ($value) {
                self::COMPANY_A => $this->companyA->getKey(),
                self::COMPANY_A_UPPERCASE => strtoupper($this->companyA->getKey()),
                default => $value,
            });
        }

        return $correction;
    }

    protected function assertAmendedOnce(string $breachId, array $changes): int
    {
        $event = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachAmended::class)->sole();
        $properties = $event->event_properties;

        $this->assertEquals($changes, $properties['changes']);
        $this->assertSame([self::REASON, $this->admin->getKey()], [$properties['reason'], $properties['amendedByUserId']]);
        $this->assertTrue(CarbonImmutable::now()->equalTo(CarbonImmutable::parse($properties['amendedAt'])));

        return (int) $event->getKey();
    }

    protected function assertHistory(string $breachId, array $rows): void
    {
        $history = BreachAmendment::query()->where('breach_incident_id', $breachId)->orderBy('field')->orderBy('company_id')->get();
        $this->assertCount(count($rows), $history);

        $this->assertSame($rows, $history->map(fn (BreachAmendment $row): array => [$row->field->value, $row->company_id, $row->old_value, $row->new_value])->all());
        $this->assertSame(
            [[self::REASON], [$this->admin->getKey()], [CarbonImmutable::now()->toDateTimeString()]],
            [$history->pluck('reason')->unique()->values()->all(), $history->pluck('amended_by')->unique()->values()->all(), $history->map(fn (BreachAmendment $row): string => $row->amended_at->toDateTimeString())->unique()->values()->all()],
        );
    }

    protected function assertAmendAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachAmended',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_amended',
            'description' => 'Platform admin corrected a data breach record.',
            'metadata' => null,
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'severity' => 'critical',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->sole();
        $this->assertSame([$correlationId, 'Symfony'], [$entry->request_id, $entry->user_agent]);
    }

    protected function assertNothingAmended(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachAmended::class)->count());
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_amended']);
    }
}
