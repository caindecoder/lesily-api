<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachAmended;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Breach\Entities\BreachAmendment;
use Database\Factories\BreachIncidentRecordFactory;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
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

    #[Test]
    public function when_the_discovery_moment_is_corrected_to_after_the_recording_then_it_is_refused(): void
    {
        // Given an incident recorded 2026-05-05 09:00 UTC, and the clock a day later
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse('2026-05-06T09:00:00Z'));

        // When the admin corrects the discovery moment to 2026-05-05 12:00 UTC, after the recording
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['dateDiscovered' => '2026-05-05T12:00:00Z']));

        // Then it is refused on the discovery moment and nothing is amended
        $this->assertValidationRejected($response, 'dateDiscovered', 'The date discovered cannot be later than when the incident was recorded.');
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
    public function when_the_audit_row_cannot_be_written_then_nothing_is_amended(): void
    {
        // Given a recorded incident, and an audit trail that refuses the next row
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin corrects its description
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then the request fails closed and the event, the rows and the history are rolled back
        $this->assertFailedClosed($response);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_locked_fact_is_sent_unchanged_beside_an_open_one_after_the_notices_then_only_the_open_one_is_amended(): void
    {
        // Given an incident discovered 2026-05-04 14:00 UTC with severity high, whose company A was notified
        $breachId = $this->aNotifiedIncident($this->companyA);

        // When the admin sends the same discovery moment and severity beside a new description
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection([
            'dateDiscovered' => '2026-05-04T14:00:00Z',
            'severity' => 'high',
            'description' => 'Payroll and bank export left on a shared drive',
        ]));

        // Then only the description is amended
        $response->assertOk();
        $this->assertAmendedOnce($breachId, [
            ['field' => 'description', 'companyId' => null, 'old' => 'A payroll export was emailed to the wrong recipient.', 'new' => 'Payroll and bank export left on a shared drive'],
        ]);
    }

    #[Test]
    public function when_only_some_values_sent_differ_then_only_those_are_recorded(): void
    {
        // Given a recorded incident with severity high
        $breachId = $this->aRecordedIncident();

        // When the admin sends the same severity beside new measures
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection([
            'severity' => 'high',
            'measures' => 'The shared drive was locked and its access log is being reviewed.',
        ]));

        // Then only the measures are recorded as changed
        $response->assertOk();
        $this->assertHistory($breachId, [
            ['measures', null, 'The recipient was asked to delete the file; exports now need a second check.', 'The shared drive was locked and its access log is being reviewed.'],
        ]);
    }

    #[Test]
    public function when_the_same_discovery_moment_is_sent_with_an_offset_then_nothing_differs(): void
    {
        // Given a recorded incident discovered 2026-05-04 14:00 UTC
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin sends the same instant written at +05:30
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['dateDiscovered' => '2026-05-04T19:30:00+05:30']));

        // Then it is refused as nothing to amend
        $this->assertValidationRejected($response, 'amendment', 'Nothing to amend: every value sent matches the incident.');
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_company_list_is_sent_in_another_order_then_nothing_differs(): void
    {
        // Given a recorded incident affecting company A (pii, banking) and company B (payroll)
        $breachId = $this->aRecordedIncident(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii', 'banking'], 'estimatedCount' => 40],
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 7],
        ]]);
        $before = $this->snapshotOf($breachId);

        // When the admin sends company B first and company A's data types the other way round
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 7],
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['banking', 'pii'], 'estimatedCount' => 40],
        ]]));

        // Then it is refused as nothing to amend
        $this->assertValidationRejected($response, 'amendment', 'Nothing to amend: every value sent matches the incident.');
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_company_count_is_cleared_then_it_is_recorded_as_unknown(): void
    {
        // Given a recorded incident affecting company A, about 40 people
        $breachId = $this->aRecordedIncident();

        // When the admin clears company A's count
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => null],
        ]]));

        // Then the count is recorded as unknown, on the company row and in the history
        $response->assertOk();
        $this->assertNull(BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->sole()->estimated_count);
        $this->assertHistory($breachId, [['estimated_count', $this->companyA->getKey(), 40, null]]);
    }

    #[Test]
    public function when_the_discovery_date_is_corrected_then_the_deadline_moves_with_it(): void
    {
        // Given a recorded incident discovered 2026-05-04 14:00 UTC, due 2026-05-07 14:00 UTC
        $breachId = $this->aRecordedIncident();

        // When the admin corrects the discovery moment to 2026-05-03 20:00 UTC
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['dateDiscovered' => '2026-05-03T20:00:00Z']));

        // Then the deadline is 2026-05-06 20:00 UTC on the record and in the event, and the change is in the history
        $response->assertOk();
        $this->assertSame(['2026-05-03T20:00:00.000000Z', '2026-05-06T20:00:00.000000Z'], [$response->json('data.date_discovered'), $response->json('data.deadline')]);
        $this->assertTrue(CarbonImmutable::parse('2026-05-06T20:00:00Z')->equalTo(BreachIncidentRecord::query()->findOrFail($breachId)->deadline));
        $this->assertHistory($breachId, [['date_discovered', null, '2026-05-04T14:00:00Z', '2026-05-03T20:00:00Z']]);
    }

    #[Test, DataProvider('provideNewDeadlineEdges')]
    public function when_a_flagged_incident_s_new_deadline_is_ahead_then_the_missed_flag_is_cleared(string $correctedDiscovery, bool $cleared): void
    {
        // Given a recorded incident due 2026-05-07 14:00 UTC, flagged as missed by the run at 2026-05-08 00:00 UTC
        $breachId = $this->aRecordedIncident();
        $this->travelTo(CarbonImmutable::parse('2026-05-08T00:00:00Z'));
        BreachIncident::retrieve($breachId)->flagMissedDeadline(CarbonImmutable::now())->persist();

        // When the admin corrects the discovery moment so the new deadline falls at or just after now
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['dateDiscovered' => $correctedDiscovery]));

        // Then the flag is cleared only when the new deadline is still ahead
        $response->assertOk();
        $this->assertSame($cleared, BreachIncidentRecord::query()->findOrFail($breachId)->deadline_missed_at === null);
        $this->assertSame($cleared, $response->json('data.deadline_missed_at') === null);
    }

    #[Test]
    public function when_a_flag_kept_by_one_correction_meets_a_deadline_moved_ahead_by_the_next_then_it_is_cleared(): void
    {
        // Given a recorded incident flagged as missed at 2026-05-08 00:00 UTC, whose discovery was first corrected
        // to 2026-05-04 20:00 UTC - due 2026-05-07 20:00, still behind, so the flag stayed
        $breachId = $this->aRecordedIncident();
        $this->travelTo(CarbonImmutable::parse('2026-05-08T00:00:00Z'));
        BreachIncident::retrieve($breachId)->flagMissedDeadline(CarbonImmutable::now())->persist();
        $this->amendIncident($this->admin, $breachId, $this->aCorrection(['dateDiscovered' => '2026-05-04T20:00:00Z']));

        // When the admin corrects the discovery moment again, to the recording moment 2026-05-05 09:00 UTC - due 2026-05-08 09:00, ahead
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['dateDiscovered' => '2026-05-05T09:00:00Z']));

        // Then the flag the first correction kept is cleared
        $response->assertOk();
        $this->assertNull(BreachIncidentRecord::query()->findOrFail($breachId)->deadline_missed_at);
    }

    #[Test]
    public function when_a_second_correction_is_sent_then_it_is_compared_with_the_first(): void
    {
        // Given a recorded incident whose description was already corrected once
        $breachId = $this->aRecordedIncident();
        $this->amendIncident($this->admin, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));
        $before = $this->snapshotOf($breachId);

        // When the admin sends the corrected description again
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then it is refused as nothing to amend, and the history keeps the one change
        $this->assertValidationRejected($response, 'amendment', 'Nothing to amend: every value sent matches the incident.');
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(1, BreachAmendment::query()->count());
    }

    #[Test]
    public function when_a_dropped_company_is_left_out_again_then_nothing_differs(): void
    {
        // Given a recorded incident whose company B was already dropped, leaving company A
        $breachId = $this->aRecordedIncident(['companies' => [$this->aNotifiedPartOf($this->companyA), $this->aNotifiedPartOf($this->companyB)]]);
        $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [$this->aNotifiedPartOf($this->companyA)]]));
        $before = $this->snapshotOf($breachId);

        // When the admin sends company A only again
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [$this->aNotifiedPartOf($this->companyA)]]));

        // Then it is refused as nothing to amend, and the history keeps the one removal
        $this->assertValidationRejected($response, 'amendment', 'Nothing to amend: every value sent matches the incident.');
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(1, BreachAmendment::query()->count());
    }

    #[Test]
    public function when_several_facts_change_in_one_request_then_one_event_carries_them_and_the_history_rows_share_its_reason_and_moment(): void
    {
        // Given a recorded incident affecting company A (pii)
        $breachId = $this->aRecordedIncident();

        // When the admin sends company A's data types before the severity and the description in one correction
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection([
            'companies' => [['companyId' => $this->companyA->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 40]],
            'severity' => 'medium',
            'description' => 'Payroll and bank export left on a shared drive',
        ]));

        // Then one event carries the three changes in the field order, and three history rows share its reason and moment
        $response->assertOk();
        $this->assertAmendedOnce($breachId, [
            ['field' => 'description', 'companyId' => null, 'old' => 'A payroll export was emailed to the wrong recipient.', 'new' => 'Payroll and bank export left on a shared drive'],
            ['field' => 'severity', 'companyId' => null, 'old' => 'high', 'new' => 'medium'],
            ['field' => 'data_types', 'companyId' => $this->companyA->getKey(), 'old' => ['pii'], 'new' => ['payroll']],
        ]);
        $this->assertHistory($breachId, [
            ['data_types', $this->companyA->getKey(), ['pii'], ['payroll']],
            ['description', null, 'A payroll export was emailed to the wrong recipient.', 'Payroll and bank export left on a shared drive'],
            ['severity', null, 'high', 'medium'],
        ]);
    }

    #[Test, DataProvider('provideOpenFacts')]
    public function when_an_open_fact_is_corrected_after_the_notices_then_it_is_recorded_and_nothing_is_sent(Closure $correction, Closure $change, Closure $shown): void
    {
        // Given an incident whose companies A and B were notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);

        // When the admin corrects a fact the notices leave open
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection($correction($this)));

        // Then the change is recorded and shown at the companies-notified stage, and nobody is told
        $response->assertOk();
        $response->assertJsonPath('data.stage', 'companies_notified');
        $this->assertAmendedOnce($breachId, [$change($this)]);
        $this->assertSame($change($this)['new'], $shown($this, $response));
        Notification::assertNothingSent();
    }

    #[Test]
    public function when_a_company_count_is_corrected_after_the_notices_then_its_own_notices_list_shows_it_and_the_other_company_s_does_not_change(): void
    {
        // Given an incident whose companies A (about 120 people) and B (about 30) were notified, and their owners
        $breachId = $this->aNotifiedIncidentWith([
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 120],
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 30],
        ]);
        $ownerA = $this->anOwnerOf($this->companyA);
        $ownerB = $this->anOwnerOf($this->companyB);

        // When the admin corrects company A's count to 145
        $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [
            ['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 145],
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 30],
        ]]));

        // Then company A's notices list shows 145, company B's still shows 30, and nobody is told
        $this->assertSame([145], $this->listNotices($ownerA, $this->companyA)->json('data.*.estimated_count'));
        $this->assertSame([30], $this->listNotices($ownerB, $this->companyB)->json('data.*.estimated_count'));
        Notification::assertNothingSent();
    }

    #[Test]
    public function when_a_company_is_added_before_the_notices_then_it_joins_the_incident(): void
    {
        // Given a recorded incident affecting company A
        $breachId = $this->aRecordedIncident();

        // When the admin sends company A and company B (payroll, about 7 people)
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [
            $this->aNotifiedPartOf($this->companyA),
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['payroll'], 'estimatedCount' => 7],
        ]]));

        // Then company B joins the incident, added by the admin now, and the addition is in the history
        $response->assertOk();
        $row = BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->where('company_id', $this->companyB->getKey())->sole();
        $this->assertSame([['payroll'], 7, $this->admin->getKey(), '2026-05-05 09:00:00'], [$row->data_types, $row->estimated_count, $row->added_by, $row->added_at->toDateTimeString()]);
        $this->assertHistory($breachId, [['company_added', $this->companyB->getKey(), null, ['dataTypes' => ['payroll'], 'estimatedCount' => 7]]]);
    }

    #[Test]
    public function when_a_company_is_dropped_before_the_notices_then_it_leaves_the_incident(): void
    {
        // Given a recorded incident affecting companies A and B (each pii, about 40 people)
        $breachId = $this->aRecordedIncident(['companies' => [$this->aNotifiedPartOf($this->companyA), $this->aNotifiedPartOf($this->companyB)]]);

        // When the admin sends company A only
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [$this->aNotifiedPartOf($this->companyA)]]));

        // Then company B leaves the incident and its removal, with its figures, is in the history
        $response->assertOk();
        $this->assertSame([$this->companyA->getKey()], BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->pluck('company_id')->all());
        $this->assertHistory($breachId, [['company_removed', $this->companyB->getKey(), ['dataTypes' => ['pii'], 'estimatedCount' => 40], null]]);
    }

    #[Test]
    public function when_one_company_is_dropped_and_another_s_figures_change_then_every_company_change_is_recorded(): void
    {
        // Given a recorded incident affecting company A (pii, about 40) and company B (banking and pii, about 40)
        $breachId = $this->aRecordedIncident(['companies' => [
            $this->aNotifiedPartOf($this->companyA),
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['banking', 'pii'], 'estimatedCount' => 40],
        ]]);

        // When the admin sends company B only, its data types now banking and payroll, about 12 people
        $response = $this->amendIncident($this->admin, $breachId, $this->aCorrection(['companies' => [
            ['companyId' => $this->companyB->getKey(), 'dataTypes' => ['banking', 'payroll'], 'estimatedCount' => 12],
        ]]));

        // Then company A's removal and both of company B's changes are recorded in one event
        $response->assertOk();
        $this->assertAmendedOnce($breachId, [
            ['field' => 'company_removed', 'companyId' => $this->companyA->getKey(), 'old' => ['dataTypes' => ['pii'], 'estimatedCount' => 40], 'new' => null],
            ['field' => 'data_types', 'companyId' => $this->companyB->getKey(), 'old' => ['banking', 'pii'], 'new' => ['banking', 'payroll']],
            ['field' => 'estimated_count', 'companyId' => $this->companyB->getKey(), 'old' => 40, 'new' => 12],
        ]);
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

    /**
     * @return array<string, array{Closure, Closure}>
     */
    public static function provideOpenFacts(): array
    {
        return [
            'the description' => [
                fn (self $test): array => ['description' => 'Payroll and bank export left on a shared drive'],
                fn (self $test): array => ['field' => 'description', 'companyId' => null, 'old' => 'A payroll export was emailed to the wrong recipient.', 'new' => 'Payroll and bank export left on a shared drive'],
                fn (self $test, TestResponse $response): mixed => $response->json('data.description'),
            ],
            'the likely consequences' => [
                fn (self $test): array => ['likelyConsequences' => 'Bank details may be used to attempt payment fraud.'],
                fn (self $test): array => ['field' => 'likely_consequences', 'companyId' => null, 'old' => 'Salary and bank details may be read by one outside person.', 'new' => 'Bank details may be used to attempt payment fraud.'],
                fn (self $test, TestResponse $response): mixed => $response->json('data.likely_consequences'),
            ],
            'the measures' => [
                fn (self $test): array => ['measures' => 'The shared drive was locked and its access log is being reviewed.'],
                fn (self $test): array => ['field' => 'measures', 'companyId' => null, 'old' => 'The recipient was asked to delete the file; exports now need a second check.', 'new' => 'The shared drive was locked and its access log is being reviewed.'],
                fn (self $test, TestResponse $response): mixed => $response->json('data.measures'),
            ],
            'a company\'s data types' => [
                fn (self $test): array => ['companies' => [
                    ['companyId' => $test->companyA->getKey(), 'dataTypes' => ['pii', 'banking'], 'estimatedCount' => 40],
                    $test->aNotifiedPartOf($test->companyB),
                ]],
                fn (self $test): array => ['field' => 'data_types', 'companyId' => $test->companyA->getKey(), 'old' => ['pii'], 'new' => ['pii', 'banking']],
                fn (self $test, TestResponse $response): mixed => $response->collect('data.companies')->firstWhere('company_id', $test->companyA->getKey())['data_types'],
            ],
        ];
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideNewDeadlineEdges(): array
    {
        return [
            'the new deadline is now' => ['2026-05-05T00:00:00Z', false],
            'the new deadline is a second ahead' => ['2026-05-05T00:00:01Z', true],
        ];
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

    protected function aNotifiedIncidentWith(array $companies): string
    {
        $breachId = $this->aRecordedIncident(['companies' => $companies]);
        $parts = collect($companies)->mapWithKeys(fn (array $company): array => [
            $company['companyId'] => ['description' => 'A payroll export was emailed to the wrong recipient.'],
        ])->all();
        BreachIncident::retrieve($breachId)->notifyCompanies($this->admin, $parts)->persist();

        return $breachId;
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
