<?php

namespace Tests\Feature\Foundation;

use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthorizeCompanyTest extends FoundationTestCase
{
    use RefreshDatabase;

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

    protected function assertUnauthenticatedResponse(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $response->assertJsonPath('errors', []);
    }
}
