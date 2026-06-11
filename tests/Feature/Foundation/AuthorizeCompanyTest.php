<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthorizeCompanyTest extends FoundationTestCase
{
    use RefreshDatabase;
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
    public function when_no_company_context_is_supplied_then_company_access_is_denied(): void
    {
        // Given an authenticated member of a company
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);
        Sanctum::actingAs($user);

        // When the request supplies no company context at all
        $response = $this->getJson('/api/_test/two-gate/secured');

        // Then the company-scope claim is rejected
        $this->assertCompanyAccessDenied($response);
    }

    protected function assertCompanyAccessDenied(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'COMPANY_ACCESS_DENIED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertUnauthenticatedResponse(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $response->assertJsonPath('errors', []);
    }
}
