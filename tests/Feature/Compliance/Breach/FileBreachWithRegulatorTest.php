<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Database\Factories\BreachIncidentCompanyFactory;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FileBreachWithRegulatorTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected const string REGULATOR = 'dpa@regulator.test';

    protected const string FILED_EVENT = 'App\Compliance\Breach\Events\BreachFiledWithRegulator';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        config(['platform.regulator_address' => self::REGULATOR]);
    }

    #[Test]
    public function when_an_unauthenticated_caller_files_then_they_are_refused(): void
    {
        // Given company A asked Lesily to file an incident it was told of
        $owner = $this->anOwnerOf($this->companyA);
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->filingRequested($owner->getKey()));
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When a caller with no token files it for company A
        $response = $this->fileFor(null, $notice->breach_incident_id, $this->companyA);

        // Then they are refused as unauthenticated and nothing is filed
        $this->assertUnauthenticated($response);
        $this->assertNothingFiled($notice->breach_incident_id, $before);
    }

    #[Test]
    public function when_a_company_owner_files_then_it_is_not_found_and_nothing_is_sent(): void
    {
        // Given company A's owner asked Lesily to file an incident A was told of
        $owner = $this->anOwnerOf($this->companyA);
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->filingRequested($owner->getKey()));
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When the owner files it for company A on the admin surface
        $response = $this->fileFor($owner, $notice->breach_incident_id, $this->companyA);

        // Then the admin surface is not acknowledged and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($notice->breach_incident_id, $before);
    }

    protected function fileFor(?User $caller, string $breachId, Company $company): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/companies/{$company->getKey()}/filing");
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}  $before
     */
    protected function assertNothingFiled(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', self::FILED_EVENT)->count());
        $this->assertNoAuditRecorded();
        $this->assertNoHandlersQueuedFor(self::FILED_EVENT);
    }
}
