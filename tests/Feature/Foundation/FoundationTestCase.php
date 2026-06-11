<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Illuminate\Testing\TestResponse;

abstract class FoundationTestCase extends TestCase
{
    // Shared setUp() fixtures populated by future UCs on real need (§2.10 + spec §1 D3).

    /**
     * The company-scope claim was rejected by AuthorizeCompany — 403 envelope
     * with COMPANY_ACCESS_DENIED per api-1-infrastructure §4.2. Shared across
     * the middleware and two-gate suites (first real §2.10 helper).
     */
    protected function assertCompanyAccessDenied(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'COMPANY_ACCESS_DENIED');
        $response->assertJsonPath('message', 'You do not have access to this company.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
