<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachAmended;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class AmendBreachIncidentTest extends BreachIncidentsTestCase
{
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

    protected function assertNothingAmended(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachAmended::class)->count());
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_amended']);
    }
}
