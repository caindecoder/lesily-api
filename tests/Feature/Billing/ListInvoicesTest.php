<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The tenant invoice list (UC-1-120, slice-plans-billing T4): GET /api/v1/invoices —
 * filter whitelist (5 stored SM-1-04 states + derived overdue/disputed, s129 R4),
 * §8.3 pagination, empty-collection contract, tenant isolation. Read bucket
 * (conv-plan §2.15): controller → repository → resource; the aggregate is never
 * touched — state fixtures arrive through the BillingTestCase event seam (B4).
 */
class ListInvoicesTest extends BillingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path fidelity: prod/CI render with debug off (amendment M).
        config(['app.debug' => false]);

        $this->seedRbacCatalog();
    }

    // --- Sad ---

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueryInputs')]
    public function when_invalid_filter_given_then_validation_error_occurs(array $query, string $invalidField): void
    {
        // Given an owner of a company
        [$company, $owner] = $this->aCompanyWithMember('owner');

        // When they request the invoice list with invalid query input
        $response = $this->listInvoicesAs($owner, $company, $query);

        // Then the input is refused as invalid on that field (api-conventions §10.1
        // per-endpoint whitelist; unknown params 422 per the s134 F-a ruling —
        // includes the company_id tenant-override attempt)
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_no_token_presented_then_authentication_required(): void
    {
        // Given a company with an owner member
        [$company] = $this->aCompanyWithMember('owner');

        // When the invoice list is requested with no auth token
        $response = $this->getJson('/api/v1/invoices', ['X-Company-Context' => $company->getKey()]);

        // Then it is refused as unauthenticated (upstream Sanctum — UC-1-120 flow 1a)
        $this->assertUnauthenticated($response);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidQueryInputs(): array
    {
        return [
            'status not whitelisted' => [['status' => 'archived'], 'status'],
            'dropped pre-R4 status token' => [['status' => 'draft'], 'status'],
            'non-whitelisted sort field' => [['sort' => 'customer_name'], 'sort'],
            'column name as sort token' => [['sort' => 'amount_cents'], 'sort'],
            'within date not ISO-8601' => [['within' => '2026/01/01,bad-date'], 'within'],
            'within missing second date' => [['within' => '2026-01-01'], 'within'],
            'non-integer min_amount' => [['min_amount' => 'abc'], 'min_amount'],
            'per_page over max' => [['per_page' => 101], 'per_page'],
            'unknown filter key' => [['foo' => 'bar'], 'foo'],
            'tenant override attempt rejected' => [['company_id' => 'ACME'], 'company_id'],
        ];
    }
}
