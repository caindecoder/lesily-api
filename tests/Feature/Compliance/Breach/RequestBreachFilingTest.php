<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Database\Factories\BreachIncidentRecordFactory;
use Database\Factories\BreachIncidentCompanyFactory;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RequestBreachFilingTest extends BreachIncidentsTestCase
{
    use InteractsWithQueuedReactors;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function when_a_guest_asks_lesily_to_file_then_they_are_unauthenticated(): void
    {
        // Given company A was notified of a breach
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);

        // When a caller with no token asks Lesily to file for company A
        $response = $this->requestFiling(null, $this->companyA, $notice->breach_incident_id);

        // Then they are refused as unauthenticated and nothing is requested
        $this->assertUnauthenticated($response);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheRequestPermission')]
    public function when_a_member_without_the_request_permission_asks_then_the_role_is_insufficient_and_nothing_is_recorded(string $roleCode): void
    {
        // Given company A was notified of a breach, and a member of A whose role cannot ask Lesily to file
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member asks Lesily to file for company A
        $response = $this->requestFiling($member, $this->companyA, $notice->breach_incident_id);

        // Then the role is refused and nothing is requested
        $this->assertRoleInsufficient($response, 'breach.request-filing');
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    public function when_the_owner_of_another_company_asks_then_company_access_is_denied(): void
    {
        // Given company A was notified of a breach, and the owner of company B
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);
        $outsider = $this->anOwnerOf($this->companyB);

        // When B's owner asks Lesily to file for company A
        $response = $this->requestFiling($outsider, $this->companyA, $notice->breach_incident_id);

        // Then access to company A is denied and nothing is requested
        $this->assertCompanyAccessDenied($response);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    #[Test]
    public function when_the_owner_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given company A was notified of a breach, and A's owner accepted the policies before a new privacy policy version
        $notice = $this->aFactoryNotice($this->anIncident()->companiesNotified(), fn (BreachIncidentCompanyFactory $row): BreachIncidentCompanyFactory => $row->notified());
        $before = $this->snapshotOf($notice->breach_incident_id);
        $owner = $this->anOwnerOf($this->companyA);
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner asks Lesily to file for company A
        $response = $this->requestFiling($owner, $this->companyA, $notice->breach_incident_id);

        // Then the request is refused until they accept, and nothing is requested
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $this->assertNothingRequested($notice->breach_incident_id, $before);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheRequestPermission(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    protected function anOwnerOf(Company $company): User
    {
        return $this->aConsentingMember($company, 'owner');
    }

    protected function aFactoryNotice(BreachIncidentRecordFactory $incident, Closure $state): BreachIncidentCompany
    {
        return $state($this->aPartFor($incident->createOne(), $this->companyA))->createOne();
    }

    protected function requestFiling(?User $caller, Company $company, string $breachId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson("/api/v1/companies/{$company->getKey()}/breach-notices/{$breachId}/filing-request");
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function snapshotOf(string $breachId): array
    {
        return [
            BreachIncidentRecord::query()->whereKey($breachId)->firstOrFail()->getAttributes(),
            BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    /**
     * @param  array{0: array<string, mixed>, 1: list<array<string, mixed>>}  $before
     */
    protected function assertNothingRequested(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachFilingRequested::class)->count());
        $this->assertNoAuditRecorded();
        $this->assertNoHandlersQueuedFor(BreachFilingRequested::class);
    }
}
