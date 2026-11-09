<?php

namespace Tests\Feature\Compliance\Breach;

use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\EventSourcing\Projectionist;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Projectors\BreachIncidentProjector;
use App\Compliance\Breach\Projectors\BreachAmendmentProjector;
use App\Compliance\Breach\Projectors\BreachIncidentCompanyProjector;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RecordBreachIncidentTest extends BreachIncidentsTestCase
{
    protected const string COMPANY_A = 'company A';

    protected const string COMPANY_A_UPPERCASE = 'company A in upper case';

    #[Test]
    public function when_an_unauthenticated_caller_records_a_breach_then_it_is_refused_as_unauthenticated(): void
    {
        // Given no one is signed in

        // When a breach is recorded
        $response = $this->recordBreach(null, $this->validPayload());

        // Then the caller is refused as unauthenticated and nothing is recorded
        $this->assertUnauthenticated($response);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_a_company_owner_records_a_breach_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given the owner of an affected company, who is not a platform admin
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner records a breach
        $response = $this->recordBreach($owner, $this->validPayload());

        // Then the admin surface answers as if it did not exist and nothing is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_the_idempotency_key_is_missing_then_no_breach_is_recorded(): void
    {
        // Given the admin sends no Idempotency-Key
        $this->idempotencyKey = null;

        // When the admin records a breach
        $response = $this->recordBreach($this->admin, $this->validPayload());

        // Then the request is refused for the missing key and nothing is recorded
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
        $this->assertNothingRecorded();
    }

    #[Test, DataProvider('invalidBreachProvider')]
    public function when_the_breach_details_are_invalid_then_the_breach_is_refused_and_nothing_is_recorded(array $changes, string $field, string $message): void
    {
        // Given the clock reads 2026-05-10 09:00 UTC and one detail of the breach is invalid
        $this->travelTo(CarbonImmutable::parse('2026-05-10T09:00:00Z'));

        // When the admin records the breach
        $response = $this->recordBreach($this->admin, $this->payloadWith($changes));

        // Then the breach is refused on that detail and nothing is recorded
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_the_audit_entry_cannot_be_written_then_no_breach_is_recorded(): void
    {
        // Given the audit write will fail
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin records a breach
        $response = $this->recordBreach($this->admin, $this->validPayload());

        // Then the request fails safely and the event, the rows and the audit row are all absent
        $this->assertRecordFailedSafely($response);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_the_breach_was_discovered_this_moment_then_it_is_recorded(): void
    {
        // Given the clock reads 2026-05-10 09:00 UTC, the moment the breach was discovered
        $this->travelTo(CarbonImmutable::parse('2026-05-10T09:00:00Z'));

        // When the admin records it
        $response = $this->recordBreach($this->admin, $this->validPayload(['dateDiscovered' => '2026-05-10T09:00:00Z']));

        // Then it is recorded
        $this->assertIncidentProjected($this->assertIncidentCreated($response), [$this->companyA->getKey() => [['pii'], 40]]);
    }

    #[Test]
    public function when_the_discovery_moment_carries_an_offset_then_it_is_kept_in_utc(): void
    {
        // Given a breach discovered at 19:30 in Colombo (+05:30), which is 14:00 UTC
        $payload = $this->validPayload(['dateDiscovered' => '2026-05-04T19:30:00+05:30']);

        // When the admin records it
        $response = $this->recordBreach($this->admin, $payload);

        // Then the discovery moment and the deadline are kept in UTC
        $breach = BreachIncidentRecord::query()->findOrFail($this->assertIncidentCreated($response));
        $this->assertSame(['2026-05-04 14:00:00', '2026-05-07 14:00:00'], [$breach->getRawOriginal('date_discovered'), $breach->getRawOriginal('deadline')]);
        $this->assertSame(['2026-05-04T14:00:00.000000Z', '2026-05-07T14:00:00.000000Z'], [$response->json('data.date_discovered'), $response->json('data.deadline')]);
    }

    #[Test]
    public function when_a_low_severity_breach_is_recorded_then_it_is_recorded_like_any_other(): void
    {
        // Given a breach the admin judges low severity
        $payload = $this->validPayload(['severity' => 'low']);

        // When the admin records it
        $response = $this->recordBreach($this->admin, $payload);

        // Then it is recorded at stage recorded with its severity
        $this->assertDatabaseHas('breach_incidents', ['id' => $this->assertIncidentCreated($response), 'stage' => 'recorded', 'severity' => 'low']);
    }

    #[Test]
    public function when_a_company_count_is_not_yet_known_then_it_is_recorded_empty(): void
    {
        // Given company A's number of affected people is not yet known
        $payload = $this->validPayload(['companies' => [['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => null]]]);

        // When the admin records the breach
        $response = $this->recordBreach($this->admin, $payload);

        // Then company A's count is left empty
        $this->assertIncidentProjected($this->assertIncidentCreated($response), [$this->companyA->getKey() => [['pii'], null]]);
    }

    #[Test]
    public function when_a_breach_is_recorded_then_the_deadline_is_72_hours_after_the_discovery_moment(): void
    {
        // Given a breach discovered on 2026-05-04 at 14:00 UTC
        $payload = $this->validPayload(['dateDiscovered' => '2026-05-04T14:00:00Z']);

        // When the admin records it
        $response = $this->recordBreach($this->admin, $payload);

        // Then its deadline is 2026-05-07 at 14:00 UTC
        $breachId = $this->assertIncidentCreated($response);
        $this->assertSame('2026-05-07T14:00:00.000000Z', $response->json('data.deadline'));
        $this->assertTrue(BreachIncidentRecord::query()->findOrFail($breachId)->deadline->equalTo(CarbonImmutable::parse('2026-05-07T14:00:00Z')));
    }

    #[Test]
    public function when_two_companies_are_affected_then_each_keeps_its_own_data_types_and_count(): void
    {
        // Given company A lost contact details of 40 people and company B payroll and bank details of 7
        $payload = $this->validPayload(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40],
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['payroll', 'banking'], 'estimatedCount' => 7],
        ]]);

        // When the admin records the breach
        $response = $this->recordBreach($this->admin, $payload);

        // Then each company keeps its own figures, stored per company and never as one incident-wide figure
        $this->assertIncidentProjected($this->assertIncidentCreated($response), [
            $this->companyA->getKey() => [['pii'], 40],
            $this->companyB->getKey() => [['payroll', 'banking'], 7],
        ]);
        $shown = array_column($response->json('data.companies'), null, 'company_id');
        $this->assertSame(
            [[['pii'], 40], [['payroll', 'banking'], 7]],
            [
                [$shown[$this->companyA->getKey()]['data_types'], $shown[$this->companyA->getKey()]['estimated_count']],
                [$shown[$this->companyB->getKey()]['data_types'], $shown[$this->companyB->getKey()]['estimated_count']],
            ],
        );
    }

    #[Test]
    public function when_the_company_projector_is_registered_first_then_a_recorded_breach_still_projects_its_rows(): void
    {
        // Given the breach projectors registered with the company projector ahead of the incident projector
        app(Projectionist::class)->withoutEventHandlers()->addProjectors([
            BreachIncidentCompanyProjector::class,
            BreachAmendmentProjector::class,
            BreachIncidentProjector::class,
        ]);

        // When a breach affecting company A is recorded
        $breachId = $this->aRecordedIncident();

        // Then the incident row and its company row are both projected
        $this->assertTrue(BreachIncidentRecord::query()->whereKey($breachId)->exists());
        $this->assertSame([$this->companyA->getKey()], BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->pluck('company_id')->all());
    }

    #[Test]
    public function when_a_platform_admin_records_a_breach_then_it_is_stored_projected_audited_and_nothing_is_sent(): void
    {
        // Given the clock reads 2026-05-10 09:00 UTC and a breach at company A discovered on 2026-05-04 14:00 UTC
        $this->travelTo(CarbonImmutable::parse('2026-05-10T09:00:00Z'));

        // When the platform admin records the breach
        $response = $this->recordBreach($this->admin, $this->validPayload());

        // Then the incident is answered, stored as its genesis event, projected,
        // audited once with no company, and no one is told anything yet
        $breachId = $this->assertIncidentResponded($response);
        $storedEventId = $this->assertBreachRecordedStored($breachId);
        $this->assertIncidentProjected($breachId, [$this->companyA->getKey() => [['pii'], 40]]);
        $this->assertRecordAudited($response, $breachId, $storedEventId);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidBreachProvider(): array
    {
        return [
            'no affected company' => [['companies' => []], 'companies', 'At least one affected company must be selected.'],
            'no company list' => [['companies' => null], 'companies', 'At least one affected company must be selected.'],
            'a company entry that is not a company' => [['companies.0' => 'company A'], 'companies.0', 'The companies.0 field must be an array.'],
            'a company without its id' => [['companies.0.companyId' => null], 'companies.0.companyId', 'The companies.0.companyId field is required.'],
            'a company id that is not a uuid' => [['companies.0.companyId' => 'company-a'], 'companies.0.companyId', 'The companies.0.companyId field must be a valid UUID.'],
            'a company that does not exist' => [['companies.0.companyId' => '01980000-0000-7000-8000-000000000000'], 'companies.0.companyId', 'The selected companies.0.companyId is invalid.'],
            'the same company twice' => [['companies.1.companyId' => self::COMPANY_A, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'the same company twice in another case' => [['companies.1.companyId' => self::COMPANY_A_UPPERCASE, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'no description' => [['description' => null], 'description', 'The description field is required.'],
            'no likely consequences' => [['likelyConsequences' => null], 'likelyConsequences', 'The likely consequences field is required.'],
            'no measures' => [['measures' => null], 'measures', 'The measures field is required.'],
            'a description over 1,000 characters' => [['description' => str_repeat('a', 1001)], 'description', 'The description field must not be greater than 1000 characters.'],
            'likely consequences over 1,000 characters' => [['likelyConsequences' => str_repeat('a', 1001)], 'likelyConsequences', 'The likely consequences field must not be greater than 1000 characters.'],
            'measures over 1,000 characters' => [['measures' => str_repeat('a', 1001)], 'measures', 'The measures field must not be greater than 1000 characters.'],
            'discovered one second from now' => [['dateDiscovered' => '2026-05-10T09:00:01Z'], 'dateDiscovered', 'The date discovered field must be a date before or equal to now.'],
            'no discovery moment' => [['dateDiscovered' => null], 'dateDiscovered', 'The date discovered field is required.'],
            'a discovery moment without a time' => [['dateDiscovered' => '2026-05-04'], 'dateDiscovered', 'The date discovered field must match the format Y-m-d\TH:i:sp.'],
            'no severity' => [['severity' => null], 'severity', 'The severity field is required.'],
            'a severity outside the four' => [['severity' => 'extreme'], 'severity', 'The selected severity is invalid.'],
            'a company with no data type' => [['companies.0.dataTypes' => []], 'companies.0.dataTypes', 'The companies.0.dataTypes field is required.'],
            'a data type outside the seven' => [['companies.0.dataTypes' => ['passwords']], 'companies.0.dataTypes.0', 'The selected companies.0.dataTypes.0 is invalid.'],
            'a negative count' => [['companies.0.estimatedCount' => -1], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be at least 0.'],
            'a count beyond the largest the record holds' => [['companies.0.estimatedCount' => 4294967296], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must not be greater than 4294967295.'],
            'a count that is not a number' => [['companies.0.estimatedCount' => 'many'], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be an integer.'],
        ];
    }

    protected function payloadWith(array $changes): array
    {
        $payload = $this->validPayload();

        foreach ($changes as $path => $value) {
            Arr::set($payload, $path, match ($value) {
                self::COMPANY_A => $this->companyA->getKey(),
                self::COMPANY_A_UPPERCASE => strtoupper($this->companyA->getKey()),
                default => $value,
            });
        }

        return $payload;
    }

    protected function assertIncidentCreated(TestResponse $response): string
    {
        $response->assertCreated();
        $breachId = $response->json('data.id');
        $this->assertIsString($breachId);

        return $breachId;
    }

    protected function assertRecordFailedSafely(TestResponse $response): void
    {
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
    }

    protected function assertIncidentResponded(TestResponse $response): string
    {
        $breachId = $this->assertIncidentCreated($response);

        $response->assertExactJson(['data' => [
            'id' => $breachId,
            'stage' => 'recorded',
            'severity' => 'high',
            'description' => $this->validPayload()['description'],
            'likely_consequences' => $this->validPayload()['likelyConsequences'],
            'measures' => $this->validPayload()['measures'],
            'date_discovered' => '2026-05-04T14:00:00.000000Z',
            'deadline' => '2026-05-07T14:00:00.000000Z',
            'recorded_at' => '2026-05-10T09:00:00.000000Z',
            'recorded_by' => $this->admin->getKey(),
            'deadline_missed_at' => null,
            'companies_notified_at' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'root_cause' => null,
            'remediation_steps' => null,
            'resolution_summary' => null,
            'false_alarm_at' => null,
            'false_alarm_by' => null,
            'false_alarm_reason' => null,
            'false_alarm_findings' => null,
            'companies' => [[
                'company_id' => $this->companyA->getKey(),
                'name' => $this->companyA->name,
                'data_types' => ['pii'],
                'estimated_count' => 40,
                'notified_at' => null,
                'filing_requested_at' => null,
                'filing_requested_by' => null,
                'filed_at' => null,
            ]],
            'total_estimated_count' => 40,
            'amendments' => [],
        ]]);

        return $breachId;
    }

    protected function assertBreachRecordedStored(string $breachId): int
    {
        $event = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachId)
            ->where('event_class', BreachRecorded::class)
            ->sole();

        $properties = $event->event_properties;
        $this->assertNull($event->meta_data['company_id'] ?? null);
        $this->assertSame([$breachId, $this->admin->getKey(), 'high'], [$properties['breachId'], $properties['recordedByUserId'], $properties['severity']]);
        $this->assertTrue(CarbonImmutable::parse($properties['deadline'])->equalTo(CarbonImmutable::parse('2026-05-07T14:00:00Z')));
        $this->assertSame([['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40]], $properties['companies']);

        return (int) $event->getKey();
    }

    protected function assertRecordAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachRecorded',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_recorded',
            'description' => 'Platform admin recorded a data breach.',
            'metadata' => null,
            'recorded_at' => '2026-05-10 09:00:00',
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'severity' => 'critical',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->sole();
        $this->assertSame($correlationId, $entry->request_id);
        $this->assertSame('Symfony', $entry->user_agent);
    }
}
