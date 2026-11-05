<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class ReviewBreachIncidentAsAdminTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_an_unauthenticated_caller_opens_an_incident_then_they_are_refused(): void
    {
        // Given a recorded incident
        $incident = $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->create();

        // When a caller with no token opens it
        $response = $this->showIncident(null, $incident->getKey());

        // Then they are refused as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoDetailReadRecorded();
    }

    #[Test]
    public function when_a_company_owner_opens_an_incident_then_it_is_not_found_and_nothing_is_audited(): void
    {
        // Given an incident naming company A, and company A's owner, who is not a platform admin
        $incident = $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->create();
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner opens the incident
        $response = $this->showIncident($owner, $incident->getKey());

        // Then the admin surface is not acknowledged — not even their own company's incident — and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoDetailReadRecorded();
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident with this id
        // When the admin opens it
        $response = $this->showIncident($this->admin, $breachId);

        // Then it is not found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoDetailReadRecorded();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownBreachIds(): array
    {
        return [
            'a random uuid' => ['0199a3f0-0000-7000-8000-00000000dead'],
            'not a uuid' => ['not-a-uuid'],
        ];
    }

    protected function showIncident(?User $caller, string $breachId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/breach-incidents/'.$breachId);
    }

    protected function assertNoDetailReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_admin_viewed']);
    }
}
