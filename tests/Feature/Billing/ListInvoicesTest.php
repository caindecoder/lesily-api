<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

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

    #[Test]
    #[DataProvider('provideDeniedRoles')]
    public function when_role_lacks_billing_view_then_role_insufficient_response(string $roleCode): void
    {
        // Given a member whose role does not carry billing.view (2026-05-24 matrix)
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they request the invoice list
        $response = $this->listInvoicesAs($actor, $company);

        // Then the list is denied for the missing permission — no invoice fields
        // in the body (UC-1-120 error flow 3a)
        $this->assertRoleInsufficient($response, 'billing.view');
    }

    #[Test]
    public function when_company_context_is_foreign_then_company_access_denied(): void
    {
        // Given a member of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('owner');
        [$companyB] = $this->aCompanyWithMember('owner');

        // When they request the second company's invoice list
        $response = $this->listInvoicesAs($outsider, $companyB);

        // Then the company context is denied (AuthorizeCompany membership check —
        // cross-tenant invoice ids are unreachable, UC-1-120 tenant isolation)
        $this->assertCompanyAccessDenied($response);
    }

    // --- Edge ---

    #[Test]
    #[DataProvider('provideInvoicelessCompanyKinds')]
    public function when_company_has_no_invoices_then_empty_collection_returned(string $companyKind): void
    {
        // Given a company that has never been invoiced — Free, or Trial before
        // conversion (plan NULL during trial, s066)
        $company = match ($companyKind) {
            'free' => Company::factory()->onPlan(BillingPlan::Free)->create(),
            'trial' => tap(
                Company::factory()->withoutPlan()->create(),
                fn (Company $trialCompany) => $this->aTrialSubscriptionFor($trialCompany, CarbonImmutable::now()->addDays(20)),
            ),
        };
        $owner = $this->aCompanyMember($company, 'owner');

        // When the owner requests the invoice list
        $response = $this->listInvoicesAs($owner, $company);

        // Then an empty collection comes back — never an error (UC-1-120 alt 6a)
        $this->assertEmptyInvoiceCollection($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvoicelessCompanyKinds(): array
    {
        return [
            'free company - no invoices ever' => ['free'],
            'trial company - pre-conversion' => ['trial'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideZeroMatchFilters')]
    public function when_filters_match_no_invoices_then_empty_collection_returned(array $query): void
    {
        // Given a company whose only invoice is pending at LKR 600.00
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $this->anIssuedInvoiceFor($company, $subscription);

        // When they request the list with filters no invoice satisfies
        $response = $this->listInvoicesAs($owner, $company, $query);

        // Then the empty collection comes back — a semantically-tight range is a
        // zero-result query, not a validation error (UC-1-120 alt 4a; declared
        // divergence from LeaveDates' reversed-range auto-swap)
        $this->assertEmptyInvoiceCollection($response);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideZeroMatchFilters(): array
    {
        return [
            'status matching no rows' => [['status' => 'paid']],
            'min_amount above max_amount' => [['min_amount' => 60001, 'max_amount' => 59999]],
        ];
    }

    // --- Happy ---

    #[Test]
    #[DataProvider('provideStoredStatuses')]
    public function when_filtering_by_stored_status_then_only_matching_invoices_returned(string $status): void
    {
        // Given a company holding one invoice in every stored SM-1-04 state
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoicesByState = $this->oneInvoicePerStoredState($company, $subscription);

        // When they filter the list by that stored status
        $response = $this->listInvoicesAs($owner, $company, ['status' => $status]);

        // Then exactly the matching invoice comes back (E28 — the full
        // filter-value × invoice-state table, one row per provider case)
        $this->assertInvoiceIdsInOrder($response, [$invoicesByState[$status]->getKey()]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideStoredStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'paid' => ['paid'],
            'failed' => ['failed'],
            'refunded' => ['refunded'],
            'voided' => ['voided'],
        ];
    }

    #[Test]
    public function when_filtering_by_overdue_then_only_pending_past_due_invoices_returned(): void
    {
        // Given a pending invoice past its due date, a pending one not yet due,
        // and a paid one past its due date
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $overdue = $this->anIssuedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000001',
            'dueDate' => CarbonImmutable::now()->subDays(5),
        ]);
        $this->anIssuedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000002',
            'dueDate' => CarbonImmutable::now()->addDays(9),
        ]);
        $this->aPaidInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000003',
            'dueDate' => CarbonImmutable::now()->subDays(5),
        ]);

        // When they filter by the derived overdue view
        $response = $this->listInvoicesAs($owner, $company, ['status' => 'overdue']);

        // Then only the pending-past-due invoice comes back — overdue is derived
        // (pending past due date), never a stored status (s129 R4)
        $this->assertInvoiceIdsInOrder($response, [$overdue->getKey()]);
    }

    #[Test]
    public function when_filtering_by_disputed_then_only_open_dispute_invoices_returned(): void
    {
        // Given a pending invoice under an open dispute beside an undisputed
        // pending one and an undisputed paid one
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $disputed = $this->aDisputedInvoiceFor($company, $subscription, ['invoiceNumber' => 'INV-2026-000001']);
        $this->anIssuedInvoiceFor($company, $subscription, ['invoiceNumber' => 'INV-2026-000002']);
        $this->aPaidInvoiceFor($company, $subscription, ['invoiceNumber' => 'INV-2026-000003']);

        // When they filter by the derived disputed view
        $response = $this->listInvoicesAs($owner, $company, ['status' => 'disputed']);

        // Then only the open-dispute invoice comes back — disputed is the
        // has_dispute modifier, not a stored status (SM-1-04 §4; s129 R4 / F8)
        $this->assertInvoiceIdsInOrder($response, [$disputed->getKey()]);
    }

    /**
     * One seam-seeded invoice per stored SM-1-04 state, keyed by state — unique
     * invoice numbers per row (the shared fixture pins a fixed number).
     *
     * @return array<string, InvoiceReadModel>
     */
    private function oneInvoicePerStoredState(Company $company, SubscriptionReadModel $subscription): array
    {
        $invoices = [];

        foreach (['pending', 'paid', 'failed', 'refunded', 'voided'] as $index => $state) {
            $invoices[$state] = $this->anInvoiceInState($state, $company, $subscription, [
                'invoiceNumber' => sprintf('INV-2026-%06d', $index + 1),
            ]);
        }

        return $invoices;
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
