<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthorizeCompanyTest extends FoundationTestCase
{
    use WithRolePermissionFixtures;

    #[Test]
    public function when_unauthenticated_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given a company and no authenticated user
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();

        // When an unauthenticated request hits a protected route with a valid company context
        $response = $this->getJson('/api/_test/two-gate/secured', [
            'X-Company-Context' => $company->getKey(),
        ]);

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticatedResponse($response);
    }

    #[Test]
    #[DataProvider('provideAbsentCompanyContexts')]
    public function when_no_company_context_is_supplied_then_company_access_is_denied(?string $companyContext): void
    {
        // Given an authenticated member of a company
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);
        Sanctum::actingAs($user);

        // When the request supplies no usable company context
        $headers = $companyContext === null ? [] : ['X-Company-Context' => $companyContext];
        $response = $this->getJson('/api/_test/two-gate/secured', $headers);

        // Then the company-scope claim is rejected
        $this->assertCompanyAccessDenied($response);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function provideAbsentCompanyContexts(): array
    {
        return [
            'header omitted' => [null],
            'header blank' => [''],
        ];
    }

    #[Test]
    #[DataProvider('provideUnknownCompanyContexts')]
    public function when_the_requested_company_is_unknown_then_the_resource_is_not_found(string $companyContext): void
    {
        // Given an authenticated member of a real company
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);
        Sanctum::actingAs($user);

        // When the supplied company context resolves to no company
        $response = $this->getJson('/api/_test/two-gate/secured', [
            'X-Company-Context' => $companyContext,
        ]);

        // Then the resource is not found (per OQ-1-101(c) + api-conventions §6)
        $this->assertResourceNotFound($response);
    }

    #[Test]
    public function when_an_authenticated_member_supplies_their_company_context_then_the_company_is_bound_to_the_request(): void
    {
        // Given an authenticated member of a company
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);
        Sanctum::actingAs($user);

        // When the member supplies their company context
        $response = $this->getJson('/api/_test/company-context-probe', [
            'X-Company-Context' => $company->getKey(),
        ]);

        // Then the resolved company is bound to the request for downstream consumers
        $response->assertOk();
        $response->assertJsonPath('company_id', $company->getKey());
    }

    #[Test]
    public function when_a_members_membership_is_soft_deleted_then_company_access_is_denied(): void
    {
        // Given an authenticated user whose membership was soft-deleted (removed
        // per UC-1-024) but whose session is still valid (R7 defense-in-depth)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);
        $this->softDeleteMembership($company, $user);
        Sanctum::actingAs($user);

        // When they hit a company-scoped route with that session
        $response = $this->getJson('/api/_test/company-context-probe', [
            'X-Company-Context' => $company->getKey(),
        ]);

        // Then the soft-deleted membership no longer grants company access
        $this->assertCompanyAccessDenied($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownCompanyContexts(): array
    {
        return [
            'nonexistent uuid' => ['0193abcd-0000-7000-8000-000000000000'],
            'garbage string' => ['not-a-company-id'],
            'numeric value' => ['12345'],
        ];
    }

    protected function assertResourceNotFound(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertUnauthenticatedResponse(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonPath('message', 'Unauthenticated.');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $response->assertJsonPath('errors', []);
    }
}
