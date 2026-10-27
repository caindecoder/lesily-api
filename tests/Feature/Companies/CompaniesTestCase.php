<?php

namespace Tests\Feature\Companies;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Consent\Events\ConsentRecorded;
use App\Companies\Entities\CompanySettings;
use Tests\Concerns\InducesProjectorFailures;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

abstract class CompaniesTestCase extends TestCase
{
    use InducesProjectorFailures;

    // Shared Companies fixtures populated on real need (§2.10) — first consumer:
    // the UC-1-032 view-company-details suite (slice-companies Task 5).

    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog() in
     * the calling suite's setUp. Duplicated from MembershipsTestCase/AuthTestCase
     * (module bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row the company-scoped gates read — who accepted the
     * live policy versions there.
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);
        $this->aConsentRecordedBy($user, $company);

        return $user;
    }

    /**
     * An authenticated member requests a company's details by URL id. $context
     * optionally supplies the X-Company-Context header (the pre-R8 mechanism) —
     * on this param route the URL must win over it.
     */
    protected function viewCompanyDetails(User $user, string $companyId, ?string $context = null): TestResponse
    {
        Sanctum::actingAs($user);

        $headers = $context === null ? [] : ['X-Company-Context' => $context];

        return $this->getJson('/api/v1/companies/'.$companyId, $headers);
    }

    /**
     * A guest (no Sanctum session) requests a company's details by URL id.
     */
    protected function viewCompanyDetailsAsGuest(string $companyId): TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$companyId);
    }

    /**
     * A guest (no Sanctum session) submits a company-details update by URL id.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateCompanyDetailsAsGuest(string $companyId, array $payload): TestResponse
    {
        return $this->putJson('/api/v1/companies/'.$companyId, $payload);
    }

    /**
     * An authenticated member submits a company-details update by URL id. The PUT
     * route is param-authoritative (R8), so no X-Company-Context header is sent.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateCompanyDetails(User $user, string $companyId, array $payload): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/v1/companies/'.$companyId, $payload);
    }

    /**
     * The input was refused as invalid on $invalidField — 422 VALIDATION_FAILED
     * whose field-level errors contain $expectedMessage (§S21; conv-plan §2.2
     * Rule 10 — assertJsonValidationErrors matches by containment, so the pin
     * fails when the rule behind the message is dropped).
     */
    protected function assertValidationRejected(TestResponse $response, string $invalidField, string $expectedMessage): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$invalidField => $expectedMessage]);
    }

    /**
     * A rejected update left the company untouched — the name still reads
     * $expectedName, the settings still hold the country defaults, and no update
     * event escaped the guard (factory companies carry no genesis events, so the
     * only events that could exist are the update's). The LD / amendment-R
     * state-unchanged discipline for sad paths.
     */
    protected function assertCompanyUnchanged(Company $company, string $expectedName): void
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame($expectedName, $company->name);
        $this->assertSame('Asia/Colombo', $settings->timezone);
        $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope (AuthTestCase mirror; base-level consolidation
     * is a flagged future refactor).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The requested resource does not exist — 404 with the §S21 envelope and no
     * existence leak (AuthorizeCompanyTest mirror; module bases are independent).
     */
    protected function assertResourceNotFound(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The member's role lacks the required permission — 403 ROLE_INSUFFICIENT
     * naming the missing grant (TwoGateCheckTest::assertPermissionDenied mirror).
     */
    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The exact UC-1-032 CompanyResource wire shape for $company — read back
     * through the models so casts govern the expected types. B7 teeth: no
     * employee_count / legal_hold / owner_id keys exist to be expected.
     *
     * @return array<string, mixed>
     */
    protected function expectedCompanyDetails(Company $company): array
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'br_number' => $company->br_number,
            'epf_number' => $company->epf_number,
            'country' => $company->country,
            'plan' => $company->current_billing_plan?->value,
            'status' => $company->status->value,
            'trial_ends_at' => $company->trial_ends_at?->toISOString(),
            'settings' => [
                'timezone' => $settings->timezone,
                'currency' => $settings->currency,
                'locale_default' => $settings->locale_default,
                'fiscal_year_start_month' => $settings->fiscal_year_start_month,
                'working_days_per_week' => $settings->working_days_per_week,
                'default_work_hours_per_day' => $settings->default_work_hours_per_day,
            ],
        ];
    }

    /**
     * A name-only update recorded exactly one CompanyDetailsUpdated event (the
     * settings event stays silent — conditional emission) and the name landed on
     * the read model. The single total event proves nothing else was recorded.
     */
    protected function assertOnlyDetailsEventRecorded(Company $company, string $expectedName): void
    {
        $company->refresh();

        $this->assertSame($expectedName, $company->name);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanyDetailsUpdated::class)
            ->whereForCompany($company->getKey())->count());
        $this->assertSame(1, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    /**
     * A settings-only update recorded exactly one CompanySettingsUpdated event
     * (the details event stays silent — conditional emission) and the timezone
     * landed on the settings read model. The single total event proves nothing
     * else was recorded.
     */
    protected function assertOnlySettingsEventRecorded(Company $company, string $expectedTimezone): void
    {
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame($expectedTimezone, $settings->timezone);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingsUpdated::class)
            ->whereForCompany($company->getKey())->count());
        $this->assertSame(1, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    /**
     * A no-op update (the current values re-submitted) still recorded both events:
     * the aggregate is guardless — it does not diff before recording (changeRole
     * same-role precedent). One details event, one settings event, two in total.
     */
    protected function assertUpdateEventsRecorded(Company $company): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanyDetailsUpdated::class)
            ->whereForCompany($company->getKey())->count());
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingsUpdated::class)
            ->whereForCompany($company->getKey())->count());
        $this->assertSame(2, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }

    /**
     * Both edits landed the event-sourced way: a CompanyDetailsUpdated and a
     * CompanySettingsUpdated stored event, each scoped to the company and stamped
     * with the acting user + an updatedAt, carry the new read-model values
     * (amendment R — a direct read-model write would leave these events absent and
     * be silently reverted on a projection replay). The float hours are covered by
     * the read-model assertDatabaseHas (JSON float equality is avoided here).
     */
    protected function assertCompanyUpdateWasEventSourced(Company $company, string $updatedBy): void
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanyDetailsUpdated::class)
            ->where('event_properties->name', $company->name)
            ->where('event_properties->updatedBy', $updatedBy)
            ->whereNotNull('event_properties->updatedAt')
            ->where('company_id', $company->getKey())
            ->count());

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanySettingsUpdated::class)
            ->where('event_properties->timezone', $settings->timezone)
            ->where('event_properties->fiscalYearStartMonth', $settings->fiscal_year_start_month)
            ->where('event_properties->workingDaysPerWeek', $settings->working_days_per_week)
            ->where('event_properties->updatedBy', $updatedBy)
            ->whereNotNull('event_properties->updatedAt')
            ->where('company_id', $company->getKey())
            ->count());
    }
}
