<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class ReviewBreachIncidentQueueTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_an_unauthenticated_caller_opens_the_queue_then_they_are_refused(): void
    {
        // Given a recorded incident
        $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();

        // When a caller with no token opens the queue
        $response = $this->listQueue(null);

        // Then they are refused as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    public function when_a_company_owner_opens_the_queue_then_it_is_not_found_and_nothing_is_audited(): void
    {
        // Given an incident naming company A, and company A's owner, who is not a platform admin
        $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner opens the queue
        $response = $this->listQueue($owner);

        // Then the admin surface is not acknowledged — not even their own company's incident — and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoQueueReadRecorded();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function listQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/breach-incidents?'.http_build_query($query));
    }

    protected function assertNoQueueReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_queue_viewed']);
    }
}
