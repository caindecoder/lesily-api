<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class AddLateBreachCompanyTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string REASON = "The export also reached Initech's folder.";

    protected Company $initech;

    /** @var list<User> */
    protected array $initechMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
        $this->initech = Company::factory()->create(['name' => 'Initech Systems']);
        $this->initechMembers = $this->anOwnerAndAdmins($this->initech);
    }

    #[Test]
    public function when_an_unauthenticated_caller_adds_a_company_then_it_is_refused_as_unauthenticated(): void
    {
        // Given an incident whose companies A and B have been notified
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->stateOf($breachId);

        // When a caller with no token adds Initech to it
        $response = $this->addCompany(null, $breachId, $this->aLateCompany());

        // Then they are refused as unauthenticated and nothing is added
        $this->assertUnauthenticated($response);
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_adds_a_company_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given an incident whose companies A and B have been notified, and company A's owner
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->stateOf($breachId);

        // When the owner adds Initech on the admin surface
        $response = $this->addCompany($owner, $breachId, $this->aLateCompany());

        // Then the admin surface is not acknowledged and nothing is added
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingAdded($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id

        // When the admin adds Initech to it
        $response = $this->addCompany($this->admin, $breachId, $this->aLateCompany());

        // Then it is not found and nothing is recorded or queued
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertNoHandlersQueuedFor(BreachCompanyNotified::class);
    }

    protected function addCompany(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/companies", $body);
    }

    protected function aLateCompany(array $overrides = []): array
    {
        return [
            'companyId' => $this->initech->getKey(),
            'dataTypes' => ['payroll', 'bank'],
            'estimatedCount' => 15,
            'reason' => self::REASON,
            ...$overrides,
        ];
    }

    /**
     * @return array{0: array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}, 1: int, 2: int}
     */
    protected function stateOf(string $breachId): array
    {
        return [
            $this->snapshotOf($breachId),
            LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->count(),
            $this->queuedHandlerCountFor(BreachCompanyNotified::class),
        ];
    }

    protected function assertNothingAdded(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->stateOf($breachId));
        $this->assertDatabaseCount('breach_amendments', 0);
        $this->assertDatabaseCount('audit_trail', 0);
    }
}
