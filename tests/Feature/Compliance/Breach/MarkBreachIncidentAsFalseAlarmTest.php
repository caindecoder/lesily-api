<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachMarkedFalseAlarm;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class MarkBreachIncidentAsFalseAlarmTest extends BreachIncidentsTestCase
{
    protected const string REASON = 'monitoring misconfiguration';

    protected const string FINDINGS = 'The alert fired on the company\'s own test export; no personal data left Lesily.';

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

    protected function assertNothingMarked(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachMarkedFalseAlarm::class)->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }
}
