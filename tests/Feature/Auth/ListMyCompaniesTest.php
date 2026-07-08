<?php

namespace Tests\Feature\Auth;

use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ListMyCompaniesTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
    }

    // Sad — the switcher list is guarded: a request with no token is rejected by
    // the Sanctum guard before any listing runs (proves auth:sanctum, UC-1-034).
    #[Test]
    public function when_request_has_no_token_then_unauthenticated(): void
    {
        // Given no authenticated token

        // When the companies list is requested
        $response = $this->getJson('/api/v1/me/companies');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Sad — a removed membership (soft-deleted pivot, UC-1-024) must not resurface
    // in the switcher: the list reads live pivots only. Eloquent's SoftDeletes scope
    // does not reach pivot-table reads, so the query must filter deleted_at itself.
    #[Test]
    public function when_membership_was_removed_then_that_company_absent_from_list(): void
    {
        // Given a user with a live membership in Acme and a removed one in Beta
        $user = $this->aRegisteredUser();
        $this->aMemberOf($user, Company::factory()->named('Acme')->create());
        $this->aRemovedMembershipIn(Company::factory()->named('Beta')->create(), $user);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then only Acme is listed — the removed Beta membership is absent
        $this->assertCompaniesListed($response, ['Acme']);
    }

    /**
     * The list responded with exactly the expected companies, in order: 200, one
     * row per expected name, data.*.name matching $expectedNames verbatim.
     */
    protected function assertCompaniesListed(TestResponse $response, array $expectedNames): void
    {
        $response->assertOk();
        $response->assertJsonCount(count($expectedNames), 'data');
        $this->assertSame($expectedNames, $response->json('data.*.name'));
    }
}
