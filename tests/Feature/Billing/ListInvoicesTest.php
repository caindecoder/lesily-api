<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;
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
    use AssertsFilterEmptyValueRejection;
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
    #[DataProvider('provideEmptyFilterValues')]
    public function when_an_invoice_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for a whitelisted filter key — reachable only at
        // the DTO seam (ConvertEmptyStringsToNull nulls it before HTTP validation)

        // When the filter is built directly, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(InvoiceFilters::class, $field, $value);
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
            default => throw new InvalidArgumentException("Unknown invoiceless company kind [{$companyKind}]."),
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
        // Day-boundary pin: due TODAY is not yet overdue (strictly past due date).
        $this->anIssuedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000004',
            'dueDate' => CarbonImmutable::today(),
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

    #[Test]
    public function when_filtering_by_amount_range_then_only_invoices_within_bounds_returned(): void
    {
        // Given invoices at LKR 500.00, 600.00 and 700.00
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $atFloor = $this->anIssuedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000001',
            'amountCents' => 50000,
            'lineItems' => [self::planLineItem(50000)],
        ]);
        $atCeiling = $this->anIssuedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000002',
            'amountCents' => 60000,
            'lineItems' => [self::planLineItem(60000)],
        ]);
        $this->anIssuedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000003',
            'amountCents' => 70000,
            'lineItems' => [self::planLineItem(70000)],
        ]);

        // When they filter by the 50000–60000 cents range
        $response = $this->listInvoicesAs($owner, $company, ['min_amount' => 50000, 'max_amount' => 60000]);

        // Then both boundary invoices come back — the bounds are inclusive
        $this->assertInvoiceIdsExactly($response, [$atFloor->getKey(), $atCeiling->getKey()]);
    }

    #[Test]
    public function when_status_and_period_filters_combined_then_matching_invoices_in_amount_order(): void
    {
        // Given paid invoices issued exactly on both period edges, a paid one
        // issued before the period, and a pending one inside it
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));

        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00'));
        $fromEdge = $this->aPaidInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000001',
            'amountCents' => 50000,
            'lineItems' => [self::planLineItem(50000)],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-06-30 10:00:00'));
        $toEdge = $this->aPaidInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000002',
            'amountCents' => 70000,
            'lineItems' => [self::planLineItem(70000)],
        ]);
        $this->travelTo(CarbonImmutable::parse('2025-12-31 10:00:00'));
        $this->aPaidInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000003',
            'amountCents' => 60000,
            'lineItems' => [self::planLineItem(60000)],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-02-01 10:00:00'));
        $this->anIssuedInvoiceFor($company, $subscription, ['invoiceNumber' => 'INV-2026-000004']);
        $this->travelBack();

        // When they combine the paid status, the period, and ascending amount sort
        $response = $this->listInvoicesAs($owner, $company, [
            'status' => 'paid',
            'within' => '2026-01-01,2026-06-30',
            'sort' => 'amount',
        ]);

        // Then only paid invoices issued inside the period come back, cheapest
        // first — both period edges included (Gherkin: filter by status + range)
        $this->assertInvoiceIdsInOrder($response, [$fromEdge->getKey(), $toEdge->getKey()]);
    }

    /**
     * @param  array<int, string>  $expectedSeedOrder
     */
    #[Test]
    #[DataProvider('provideSortOrders')]
    public function when_sort_parameter_given_then_rows_ordered_accordingly(?string $sort, array $expectedSeedOrder): void
    {
        // Given three invoices with distinct issue dates and amounts
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $seeds = $this->threeInvoicesVaryingDateAndAmount($company, $subscription);

        // When they request the list under that sort token
        $response = $this->listInvoicesAs($owner, $company, $sort === null ? [] : ['sort' => $sort]);

        // Then the rows come back in the token's order — newest issued first
        // when no sort is given (the api-1-invoices §2 default)
        $this->assertInvoiceIdsInOrder($response, array_map(
            fn (string $seedKey) => $seeds[$seedKey]->getKey(),
            $expectedSeedOrder,
        ));
    }

    /**
     * @return array<string, array{?string, array<int, string>}>
     */
    public static function provideSortOrders(): array
    {
        return [
            'absent - newest issued first' => [null, ['march-60000', 'february-50000', 'january-70000']],
            'issued_at - oldest first' => ['issued_at', ['january-70000', 'february-50000', 'march-60000']],
            'amount - cheapest first' => ['amount', ['february-50000', 'march-60000', 'january-70000']],
            '-amount - dearest first' => ['-amount', ['january-70000', 'march-60000', 'february-50000']],
        ];
    }

    /**
     * Three pending invoices whose issue months and amounts deliberately
     * disagree, so every sort token produces a distinct order.
     *
     * @return array<string, InvoiceReadModel>
     */
    private function threeInvoicesVaryingDateAndAmount(Company $company, SubscriptionReadModel $subscription): array
    {
        $rows = [
            'january-70000' => ['2026-01-10 09:00:00', 70000, 'INV-2026-000001'],
            'february-50000' => ['2026-02-10 09:00:00', 50000, 'INV-2026-000002'],
            'march-60000' => ['2026-03-10 09:00:00', 60000, 'INV-2026-000003'],
        ];

        $seeds = [];
        foreach ($rows as $seedKey => [$issuedAt, $amountCents, $invoiceNumber]) {
            $this->travelTo(CarbonImmutable::parse($issuedAt));
            $seeds[$seedKey] = $this->anIssuedInvoiceFor($company, $subscription, [
                'invoiceNumber' => $invoiceNumber,
                'amountCents' => $amountCents,
                'lineItems' => [self::planLineItem($amountCents)],
            ]);
        }
        $this->travelBack();

        return $seeds;
    }

    #[Test]
    public function when_issue_dates_tie_then_rows_return_in_stable_order(): void
    {
        // Given three invoices issued at the same instant
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $this->travelTo(CarbonImmutable::parse('2026-04-04 12:00:00'));
        $invoiceIds = [];
        foreach ([1, 2, 3] as $sequence) {
            $invoiceIds[] = $this->anIssuedInvoiceFor($company, $subscription, [
                'invoiceNumber' => sprintf('INV-2026-%06d', $sequence),
            ])->getKey();
        }
        $this->travelBack();

        // When the list is requested at the default sort
        $response = $this->listInvoicesAs($owner, $company);

        // Then equal sort keys fall back to ascending id — page order can never
        // shuffle between requests of a paginated list (stable tie-break)
        sort($invoiceIds);
        $this->assertInvoiceIdsInOrder($response, $invoiceIds);
    }

    #[Test]
    public function when_page_size_raised_to_maximum_then_single_page_serves_all(): void
    {
        // Given twenty invoices — one through the event seam, nineteen bulk
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $this->anIssuedInvoiceFor($company, $subscription);
        InvoiceReadModel::factory()->count(19)->forCompany($company)->create();

        // When they request per_page=100 — the whitelist ceiling
        $response = $this->listInvoicesAs($owner, $company, ['per_page' => 100]);

        // Then one page serves all twenty rows under the raised size
        $this->assertInvoicePage($response, 20, ['page' => 1, 'per_page' => 100, 'total' => 20, 'last_page' => 1]);
    }

    #[Test]
    public function when_second_page_requested_then_remaining_invoices_with_pagination_meta(): void
    {
        // Given twenty invoices — one through the event seam, nineteen bulk
        // factory rows (the factory exists for pure-read bulk seeding only; B4)
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $this->anIssuedInvoiceFor($company, $subscription);
        InvoiceReadModel::factory()->count(19)->forCompany($company)->create();

        // When they request the second page at the default page size
        $response = $this->listInvoicesAs($owner, $company, ['page' => 2]);

        // Then the remaining five rows ride the exact §8.3 meta
        $this->assertInvoicePage($response, 5, ['page' => 2, 'per_page' => 15, 'total' => 20, 'last_page' => 2]);
    }

    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_role_may_view_billing_then_invoice_list_returned(string $roleCode): void
    {
        // Given a member whose role carries billing.view and one company invoice
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        // When they request the invoice list
        $response = $this->listInvoicesAs($actor, $company);

        // Then the company's invoice comes back — owner full, admin view-only
        // (s129 F3), auditor read-only (2026-05-24 matrix `billing.view` row)
        $this->assertInvoiceIdsInOrder($response, [$invoice->getKey()]);
    }

    #[Test]
    public function when_owner_lists_invoices_then_exact_contract_returned(): void
    {
        // Given a paid May invoice and a disputed pending June invoice
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));

        $this->travelTo(CarbonImmutable::parse('2026-05-05 09:30:00'));
        $paid = $this->aPaidInvoiceFor($company, $subscription, ['invoiceNumber' => 'INV-2026-000101']);
        $this->travelTo(CarbonImmutable::parse('2026-06-06 10:15:00'));
        $disputed = $this->aDisputedInvoiceFor($company, $subscription, ['invoiceNumber' => 'INV-2026-000102']);
        $this->travelBack();

        // When the owner requests the invoice list
        $response = $this->listInvoicesAs($owner, $company);

        // Then exactly the eight contract keys per row come back, newest issued
        // first — literal payload identity, so nothing beyond the contract leaks
        $this->assertExactInvoiceListContract($response, [
            'data' => [
                [
                    'id' => $disputed->getKey(),
                    'number' => 'INV-2026-000102',
                    'issued_at' => '2026-06-06T10:15:00.000000Z',
                    'due_at' => '2026-06-20',
                    'status' => 'pending',
                    'amount_cents' => 60000,
                    'currency' => 'LKR',
                    'has_dispute' => true,
                ],
                [
                    'id' => $paid->getKey(),
                    'number' => 'INV-2026-000101',
                    'issued_at' => '2026-05-05T09:30:00.000000Z',
                    'due_at' => '2026-05-19',
                    'status' => 'paid',
                    'amount_cents' => 60000,
                    'currency' => 'LKR',
                    'has_dispute' => false,
                ],
            ],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 15, 'total' => 2, 'last_page' => 1]],
        ]);
    }

    #[Test]
    public function when_owner_of_two_companies_lists_then_only_context_company_invoices_returned(): void
    {
        // Given one owner of companies A and B — B's invoice seeded FIRST so an
        // unscoped fetch would surface it (s131 isolation idiom)
        [$companyB, $owner] = $this->aCompanyWithMember('owner');
        $subscriptionB = $this->aTrialSubscriptionFor($companyB, CarbonImmutable::now()->addDays(30));
        $this->anIssuedInvoiceFor($companyB, $subscriptionB, ['invoiceNumber' => 'INV-2026-000201']);

        $companyA = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $companyA->users()->attach($owner, ['role_id' => $this->seededRole('owner')->getKey()]);
        $subscriptionA = $this->aTrialSubscriptionFor($companyA, CarbonImmutable::now()->addDays(30));
        $first = $this->anIssuedInvoiceFor($companyA, $subscriptionA, ['invoiceNumber' => 'INV-2026-000202']);
        $second = $this->aPaidInvoiceFor($companyA, $subscriptionA, ['invoiceNumber' => 'INV-2026-000203']);

        // When they request company A's invoice list
        $response = $this->listInvoicesAs($owner, $companyA);

        // Then exactly A's two invoices come back — row identity proves B's
        // invoice is never enumerated (UC-1-120 minimal guarantee)
        $this->assertInvoiceIdsExactly($response, [$first->getKey(), $second->getKey()]);
    }

    #[Test]
    public function when_factory_state_used_then_row_matches_projector_output(): void
    {
        // Given the same paid state arranged both ways — through the event seam
        // (the production path) and through the bulk factory
        [$company] = $this->aCompanyWithMember('owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));
        $seamPaid = $this->aPaidInvoiceFor($company, $subscription);
        $factoryPaid = InvoiceReadModel::factory()->paid()->forCompany($company)->create();

        // When the drift-sensitive shape of each row is read back
        // Then the factory's shape matches the projector's output for the
        // reference state — the two row-truth sources cannot silently drift
        // (the InvoiceFactory docblock contract: each state stamps exactly the
        // columns its projector arm writes)
        $this->assertSame(
            $this->projectedRowShape($seamPaid),
            $this->projectedRowShape($factoryPaid),
        );
    }

    #[Test]
    public function when_an_invoice_filter_is_built_without_values_then_defaults_apply(): void
    {
        // Given no query values at all

        // When the filter is built at the DTO seam
        $filters = InvoiceFilters::validateAndCreate([]);

        // Then it constructs with every property at its null default — the
        // filled guard must never fire on an absent key
        $this->assertSame([
            'status' => null,
            'within' => null,
            'min_amount' => null,
            'max_amount' => null,
            'sort' => null,
            'page' => null,
            'per_page' => null,
        ], $filters->toArray());
    }

    /**
     * The drift-sensitive shape of an invoices row: every column's filled/null
     * state plus the status discriminator by value — identity, tenant, and
     * clock columns excluded (they legitimately differ per row).
     *
     * @return array<string, bool|string>
     */
    private function projectedRowShape(InvoiceReadModel $invoice): array
    {
        $attributes = $invoice->refresh()->getAttributes();

        $shape = collect($attributes)
            ->except(['id', 'company_id', 'subscription_id', 'invoice_number', 'created_at', 'updated_at'])
            ->map(fn (mixed $value): bool => $value !== null)
            ->all();
        $shape['status'] = $attributes['status'];
        ksort($shape);

        return $shape;
    }

    /**
     * A single plan line item whose amount matches the invoice total — the
     * aggregate validates SUM(lineItems) == amountCents at issue time.
     *
     * @return array<string, mixed>
     */
    private static function planLineItem(int $amountCents): array
    {
        return [
            'description' => 'Pro Plan — Monthly',
            'quantity' => 1,
            'unitPriceCents' => $amountCents,
            'amountCents' => $amountCents,
            'lineType' => 'plan_subscription',
            'sortOrder' => 0,
        ];
    }

    /**
     * One seam-seeded invoice per stored SM-1-04 state, keyed by state — unique
     * invoice numbers per row (the shared fixture pins a fixed number).
     *
     * @return array<string, InvoiceReadModel>
     */
    private function oneInvoicePerStoredState(Company $company, SubscriptionReadModel $subscription): array
    {
        // The pending seed deliberately carries an open dispute: a stored-status
        // filter must ignore the has_dispute modifier (SM-1-04 §4 orthogonality).
        $invoices = ['pending' => $this->aDisputedInvoiceFor($company, $subscription, [
            'invoiceNumber' => 'INV-2026-000001',
        ])];

        foreach (['paid', 'failed', 'refunded', 'voided'] as $index => $state) {
            $invoices[$state] = $this->anInvoiceInState($state, $company, $subscription, [
                'invoiceNumber' => sprintf('INV-2026-%06d', $index + 2),
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
            'per_page below minimum' => [['per_page' => 0], 'per_page'],
            'page below minimum' => [['page' => 0], 'page'],
            'unknown filter key' => [['foo' => 'bar'], 'foo'],
            'tenant override attempt rejected' => [['company_id' => 'ACME'], 'company_id'],
            'multi-value status list' => [['status' => 'paid,pending'], 'status'],
            'empty status value' => [['status' => ''], 'status'],
            'within given as an array' => [['within' => ['2026-01-01', '2026-06-30']], 'within'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        return [
            'empty status' => ['status', ''],
            'empty within' => ['within', ''],
            'empty min_amount' => ['min_amount', ''],
            'empty max_amount' => ['max_amount', ''],
            'empty sort' => ['sort', ''],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'whitespace per_page' => ['per_page', ' '],
        ];
    }
}
