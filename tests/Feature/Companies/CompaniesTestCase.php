<?php

namespace Tests\Feature\Companies;

use Tests\TestCase;
use Illuminate\Testing\TestResponse;

abstract class CompaniesTestCase extends TestCase
{
    // Shared Companies fixtures populated on real need (§2.10) — first consumer:
    // the UC-1-032 view-company-details suite (slice-companies Task 5).

    /**
     * A guest (no Sanctum session) requests a company's details by URL id.
     */
    protected function viewCompanyDetailsAsGuest(string $companyId): TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$companyId);
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope (AuthTestCase mirror; base-level consolidation
     * is a flagged future refactor).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
