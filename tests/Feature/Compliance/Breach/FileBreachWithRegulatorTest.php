<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Database\Factories\BreachIncidentCompanyFactory;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\RegulatorAddressNotConfiguredException;

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
        $response = $this->fileFor(null, $notice->breach_incident_id, $this->companyA->getKey());

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
        $response = $this->fileFor($owner, $notice->breach_incident_id, $this->companyA->getKey());

        // Then the admin surface is not acknowledged and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($notice->breach_incident_id, $before);
    }

    #[Test]
    #[DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given no incident carries the id

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then it is not found and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', self::FILED_EVENT)->count());
    }

    #[Test]
    #[DataProvider('provideCompaniesNotOnTheIncident')]
    public function when_the_company_cannot_be_found_on_the_incident_then_it_is_not_found(Closure $companyId): void
    {
        // Given company B asked Lesily to file an incident only B was told of
        $breachId = $this->aNotifiedIncident($this->companyB);
        $this->aFilingRequestBy($this->companyB, $breachId);
        $before = $this->snapshotOf($breachId);

        // When an admin files it for a company that is not on it
        $response = $this->fileFor($this->admin, $breachId, $companyId($this));

        // Then it is not found and nothing is filed
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingFiled($breachId, $before);
    }

    #[Test]
    public function when_no_regulator_address_is_configured_then_the_filing_is_unavailable_and_nothing_is_recorded(): void
    {
        // Given company A asked Lesily to file an incident it was told of, and no regulator address is configured
        $breachId = $this->aNotifiedIncident($this->companyA);
        $this->aFilingRequestBy($this->companyA, $breachId);
        $before = $this->snapshotOf($breachId);
        config(['platform.regulator_address' => '']);
        Exceptions::fake();

        // When an admin files it for company A
        $response = $this->fileFor($this->admin, $breachId, $this->companyA->getKey());

        // Then the filing is unavailable, the gap is reported and nothing is filed
        $this->assertRefusedWith($response, Response::HTTP_SERVICE_UNAVAILABLE, ApiErrorCode::RegulatorFilingUnavailable);
        $response->assertJsonPath('message', 'Filing with the regulator is unavailable: no regulator address is configured.');
        Exceptions::assertReported(RegulatorAddressNotConfiguredException::class);
        $this->assertNothingFiled($breachId, $before);
    }

    /**
     * @return array<string, array{Closure(self): string}>
     */
    public static function provideCompaniesNotOnTheIncident(): array
    {
        return [
            'a company not on it' => [fn (self $test): string => $test->companyA->getKey()],
            'an unknown company' => [fn (self $test): string => '019a5b2e-7c1d-7f00-8a00-0000000000c0'],
            'not a company id' => [fn (self $test): string => 'not-a-company'],
        ];
    }

    protected function fileFor(?User $caller, string $breachId, string $companyId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/admin/breach-incidents/{$breachId}/companies/{$companyId}/filing");
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
