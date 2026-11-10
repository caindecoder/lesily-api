<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Events\BreachMarkedFalseAlarm;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class MarkBreachIncidentAsFalseAlarmTest extends BreachIncidentsTestCase
{
    protected const string REASON = 'monitoring misconfiguration';

    protected const string FINDINGS = 'The alert fired on the company\'s own test export; no personal data left Lesily.';

    protected const string MARKED_AT = '2026-05-05 10:00:00';

    protected const string MARKED_AT_ISO = '2026-05-05T10:00:00+00:00';

    protected const string MARKED_AT_JSON = '2026-05-05T10:00:00.000000Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
    }

    #[Test]
    public function when_an_unauthenticated_caller_marks_a_false_alarm_then_it_is_refused_as_unauthenticated(): void
    {
        // Given a recorded incident affecting company A
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When a caller with no token marks it a false alarm
        $response = $this->markFalseAlarm(null, $breachId, $this->aFalseAlarm());

        // Then they are refused as unauthenticated and nothing is marked
        $this->assertUnauthenticated($response);
        $this->assertNothingMarked($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_marks_a_false_alarm_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a recorded incident affecting company A, and company A's owner
        $breachId = $this->aRecordedIncident();
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->snapshotOf($breachId);

        // When the owner marks it a false alarm on the admin surface
        $response = $this->markFalseAlarm($owner, $breachId, $this->aFalseAlarm());

        // Then the admin surface is not acknowledged and nothing is marked
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingMarked($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id

        // When the admin marks it a false alarm
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // Then it is not found and nothing is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }

    #[Test, DataProvider('provideInvalidFalseAlarms')]
    public function when_the_false_alarm_is_invalid_then_it_is_refused_and_nothing_is_recorded(array $override, string $field, string $message): void
    {
        // Given a recorded incident affecting company A
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin marks it a false alarm with one text wrong
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm($override));

        // Then the false alarm is refused on that text and nothing is marked
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingMarked($breachId, $before);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidFalseAlarms(): array
    {
        return [
            'no reason' => [['reason' => null], 'reason', 'The reason field is required.'],
            'a reason that is not text' => [['reason' => ['a list']], 'reason', 'The reason field must be a string.'],
            'a reason over 1,000 characters' => [['reason' => str_repeat('a', 1001)], 'reason', 'The reason field must not be greater than 1000 characters.'],
            'no findings' => [['findings' => null], 'findings', 'The findings field is required.'],
            'findings that are not text' => [['findings' => ['a list']], 'findings', 'The findings field must be a string.'],
            'findings over 1,000 characters' => [['findings' => str_repeat('a', 1001)], 'findings', 'The findings field must not be greater than 1000 characters.'],
        ];
    }

    #[Test]
    public function when_the_companies_were_notified_then_the_false_alarm_is_refused(): void
    {
        // Given an incident whose companies A and B have been notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->snapshotOf($breachId);
        Notification::fake();
        Mail::fake();

        // When the admin marks it a false alarm
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // Then it is refused because the notices are out, nothing is marked and nobody is told
        $this->assertRefusedAsNotOpen($response, 'A breach incident is marked a false alarm only before its companies are notified.');
        $this->assertNothingMarked($breachId, $before);
        $this->assertNobodyTold();
    }

    #[Test, DataProvider('provideClosedStages')]
    public function when_the_incident_is_closed_then_the_false_alarm_is_refused(Closure $closed, string $message): void
    {
        // Given a closed incident
        $breachId = $closed($this->anIncident())->createOne()->getKey();
        $before = $this->snapshotOf($breachId);

        // When the admin marks it a false alarm
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // Then it is refused with the stage named, and nothing is marked
        $this->assertRefusedAsNotOpen($response, $message);
        $this->assertNothingMarked($breachId, $before);
    }

    /**
     * @return array<string, array{Closure(BreachIncidentRecordFactory): BreachIncidentRecordFactory, string}>
     */
    public static function provideClosedStages(): array
    {
        return [
            'resolved' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->resolved(),
                'A resolved breach incident cannot be marked a false alarm.',
            ],
            'a false alarm' => [
                fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm(),
                'A breach incident already marked as a false alarm cannot be marked again.',
            ],
        ];
    }

    #[Test]
    public function when_an_incident_already_a_false_alarm_is_marked_again_then_it_is_refused_and_recorded_once(): void
    {
        // Given a recorded incident affecting company A, already marked a false alarm by the admin
        $breachId = $this->aRecordedIncident();
        $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());
        $before = $this->snapshotOf($breachId);

        // When the admin marks it a false alarm again
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm(['findings' => 'A second look found the same.']));

        // Then it is refused, the incident is unchanged, and the close is recorded and audited once
        $this->assertRefusedAsNotOpen($response, 'A breach incident already marked as a false alarm cannot be marked again.');
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachMarkedFalseAlarm::class)->count());
        $this->assertDatabaseCount('audit_trail', 1);
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_nothing_is_marked(): void
    {
        // Given a recorded incident affecting company A, and an audit trail that refuses every write
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin marks it a false alarm
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // Then the request fails closed: no BreachMarkedFalseAlarm is recorded and the incident is unchanged
        $this->assertFailedClosed($response);
        $this->assertNothingMarked($breachId, $before);
    }

    #[Test]
    public function when_every_text_is_at_its_longest_then_the_incident_is_marked(): void
    {
        // Given a recorded incident affecting company A, and a reason and findings of 1,000 characters each
        $breachId = $this->aRecordedIncident();
        $reason = str_repeat('r', 1000);
        $findings = str_repeat('f', 1000);

        // When the admin marks it a false alarm
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm(['reason' => $reason, 'findings' => $findings]));

        // Then it is marked with both texts whole
        $response->assertOk();
        $row = BreachIncidentRecord::query()->findOrFail($breachId);
        $this->assertSame([BreachStage::FalseAlarm, $reason, $findings], [$row->stage, $row->false_alarm_reason, $row->false_alarm_findings]);
    }

    #[Test]
    public function when_a_recorded_incident_has_missed_its_deadline_then_it_is_still_marked(): void
    {
        // Given a recorded incident affecting company A, flagged as past its 72-hour deadline
        $breachId = $this->aRecordedIncident();
        $this->travelTo(CarbonImmutable::parse('2026-05-08T10:00:00Z'));
        BreachIncident::retrieve($breachId)->flagMissedDeadline(CarbonImmutable::now())->persist();

        // When the admin marks it a false alarm
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // Then it is marked, and the missed deadline stays on the record
        $response->assertOk();
        $row = BreachIncidentRecord::query()->findOrFail($breachId);
        $this->assertSame([BreachStage::FalseAlarm, '2026-05-08T10:00:00+00:00'], [$row->stage, $row->deadline_missed_at?->toIso8601String()]);
    }

    #[Test]
    public function when_an_incident_is_marked_then_the_queue_shows_it_as_a_false_alarm(): void
    {
        // Given a recorded incident affecting company A, marked a false alarm by the admin
        $breachId = $this->aRecordedIncident();
        $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // When the admin opens the queue at the false-alarm stage
        $response = $this->aQueueAtFalseAlarm();

        // Then the incident is the one row, shown as a false alarm
        $response->assertOk();
        $this->assertSame([[$breachId, 'false_alarm']], array_map(
            fn (array $row): array => [$row['id'], $row['stage']],
            $response->json('data'),
        ));
    }

    #[Test]
    public function when_the_body_names_another_incident_then_the_incident_in_the_address_is_marked(): void
    {
        // Given two recorded incidents affecting company A
        $addressed = $this->aRecordedIncident();
        $other = $this->aRecordedIncident();
        $otherBefore = $this->snapshotOf($other);

        // When the admin marks the first a false alarm, with the second's id in the body
        $response = $this->markFalseAlarm($this->admin, $addressed, $this->aFalseAlarm(['breachId' => $other]));

        // Then the incident in the address is marked and the other is untouched
        $response->assertOk();
        $this->assertSame(BreachStage::FalseAlarm, BreachIncidentRecord::query()->findOrFail($addressed)->stage);
        $this->assertSame($otherBefore, $this->snapshotOf($other));
    }

    #[Test]
    public function when_a_platform_admin_marks_a_recorded_incident_then_it_is_recorded_projected_audited_and_nobody_is_told(): void
    {
        // Given a recorded incident affecting companies A and B, discovered 20 hours ago
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40],
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 12],
        ]]);
        Notification::fake();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse(self::MARKED_AT));

        // When the admin marks it a false alarm with the reason and the findings
        $response = $this->markFalseAlarm($this->admin, $breachId, $this->aFalseAlarm());

        // Then the incident is a false alarm in the event store and the read model, the close is audited,
        // and nobody - neither company nor the regulator - is told, nor any notice recorded
        $this->assertMarkAnswered($response);
        $storedEventId = $this->assertMarkStored($breachId);
        $this->assertMarkProjected($breachId);
        $this->assertMarkAudited($response, $breachId, $storedEventId);
        $this->assertNobodyTold();
    }

    protected function markFalseAlarm(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/breach-incidents/{$breachId}/false-alarm", $body);
    }

    protected function aFalseAlarm(array $overrides = []): array
    {
        return [
            'reason' => self::REASON,
            'findings' => self::FINDINGS,
            ...$overrides,
        ];
    }

    protected function aQueueAtFalseAlarm(): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/v1/admin/breach-incidents?stage=false_alarm');
    }

    protected function assertMarkAnswered(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(
            ['false_alarm', self::REASON, self::FINDINGS, $this->admin->getKey(), self::MARKED_AT_JSON],
            [
                $response->json('data.stage'),
                $response->json('data.false_alarm_reason'),
                $response->json('data.false_alarm_findings'),
                $response->json('data.false_alarm_by'),
                $response->json('data.false_alarm_at'),
            ],
        );
    }

    protected function assertMarkStored(string $breachId): int
    {
        $this->assertSame(
            [BreachRecorded::class, BreachMarkedFalseAlarm::class],
            LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->orderBy('id')->pluck('event_class')->all(),
        );

        $marked = LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->latest('id')->firstOrFail();
        $this->assertSame([$breachId, self::REASON, self::FINDINGS, $this->admin->getKey(), self::MARKED_AT_ISO], [
            $marked->event_properties['breachId'],
            $marked->event_properties['reason'],
            $marked->event_properties['findings'],
            $marked->event_properties['markedByUserId'],
            CarbonImmutable::parse($marked->event_properties['markedAt'])->toIso8601String(),
        ]);

        return (int) $marked->getKey();
    }

    protected function assertMarkProjected(string $breachId): void
    {
        $row = BreachIncidentRecord::query()->findOrFail($breachId);
        $this->assertSame(
            [BreachStage::FalseAlarm, self::REASON, self::FINDINGS, $this->admin->getKey(), self::MARKED_AT_ISO],
            [$row->stage, $row->false_alarm_reason, $row->false_alarm_findings, $row->false_alarm_by, $row->false_alarm_at?->toIso8601String()],
        );
    }

    protected function assertMarkAudited(TestResponse $response, string $breachId, int $storedEventId): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => null,
            'stored_event_id' => $storedEventId,
            'event_class' => 'BreachMarkedFalseAlarm',
            'subject_type' => 'breach_incident',
            'subject_id' => $breachId,
            'subject_label' => null,
            'action' => 'breach_marked_false_alarm',
            'description' => 'Platform admin marked a data breach incident a false alarm.',
            'metadata' => null,
            'recorded_at' => self::MARKED_AT,
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

    protected function assertNobodyTold(): void
    {
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    protected function assertNothingMarked(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachMarkedFalseAlarm::class)->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }
}
