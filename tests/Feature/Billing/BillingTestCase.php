<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Billing\Invoices\Entities\InvoiceLineItem;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Invoices\UseCases\Commands\VoidInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\IssueInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\RefundInvoiceCommand;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentFailedCommand;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentSucceededCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

abstract class BillingTestCase extends TestCase
{
    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog() in
     * the calling suite's setUp. Duplicated from CompaniesTestCase/AuthTestCase
     * (module bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row the billing.view gate reads. Duplicated from
     * CompaniesTestCase (module bases are independent — established precedent).
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * A company on a paid plan with one fresh member under a seeded role.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();

        return [$company, $this->aCompanyMember($company, $roleCode)];
    }

    /**
     * The plan catalog came back — 200 with all four standard tiers in the
     * non-paginated {data: [...]} envelope, each row carrying the six contract
     * keys (LD assertJsonCount shape-lock pattern).
     */
    protected function assertCatalogReturned(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonCount(4, 'data');
        $response->assertJsonStructure(['data' => ['*' => [
            'code', 'label', 'features', 'monthly_price_cents', 'annual_price_cents', 'currency',
        ]]]);
    }

    /**
     * An authenticated member requests the plan catalog under $company's context.
     */
    protected function viewPlansAs(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/plans', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * An authenticated member requests the company's subscription state under
     * $company's context.
     */
    protected function viewSubscriptionAs(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/subscription', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A guest (no token) requests the company's subscription state.
     */
    protected function viewSubscriptionAsGuest(): TestResponse
    {
        return $this->getJson('/api/v1/subscription');
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope. Duplicated from CompaniesTestCase/AuthTestCase
     * (module bases are independent — established precedent; base-level
     * consolidation stays a flagged future refactor).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The member's role lacks the required permission — 403 ROLE_INSUFFICIENT
     * naming the missing grant. Duplicated from CompaniesTestCase (module bases
     * are independent — established precedent).
     */
    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * A trial subscription projected for $company through the domain seam —
     * the genesis event path production takes (SubscriptionProjectionTest
     * precedent), never a direct row write.
     */
    protected function aTrialSubscriptionFor(Company $company, CarbonImmutable $trialEndsAt): SubscriptionReadModel
    {
        $subscriptionId = (string) Str::uuid();

        Subscription::retrieve($subscriptionId)
            ->startTrial(StartTrialCommand::validateAndCreate([
                'companyId' => $company->getKey(),
                'trialEndsAt' => $trialEndsAt,
            ]))
            ->persist();

        return SubscriptionReadModel::findOrFail($subscriptionId);
    }

    /**
     * A subscription whose trial ran out — started and ended through the domain
     * seam, exactly as the trials:expire scan does (ended_source 'trial-expired',
     * cancelled_at stamped from the travelled clock).
     */
    protected function anEndedTrialSubscriptionFor(Company $company, CarbonImmutable $trialEndsAt): SubscriptionReadModel
    {
        $subscription = $this->aTrialSubscriptionFor($company, $trialEndsAt);

        Subscription::retrieve($subscription->getKey())
            ->endTrial('trial-expired')
            ->persist();

        return $subscription->refresh();
    }

    /**
     * A valid issue command against $subscription's company — two line items
     * whose signed amounts sum to the invoice total (the proration-credit row
     * exercises ERD-1-04's signed line-item amount). Override any top-level
     * field per test; the fixed invoice number is safe per-test (RefreshDatabase
     * isolates rows) — pass an override where a test needs several invoices.
     */
    protected function issueInvoiceCommand(Company $company, SubscriptionReadModel $subscription, array $overrides = []): IssueInvoiceCommand
    {
        return IssueInvoiceCommand::validateAndCreate([
            'invoiceNumber' => 'INV-2026-000001',
            'subscriptionId' => $subscription->getKey(),
            'companyId' => $company->getKey(),
            'amountCents' => 60000,
            'currency' => 'LKR',
            'lineItems' => [
                [
                    'description' => 'Pro Plan — Monthly',
                    'quantity' => 1,
                    'unitPriceCents' => 75000,
                    'amountCents' => 75000,
                    'lineType' => 'plan_subscription',
                    'sortOrder' => 0,
                ],
                [
                    'description' => 'Proration credit — unused Starter days',
                    'quantity' => -1,
                    'unitPriceCents' => 15000,
                    'amountCents' => -15000,
                    'lineType' => 'proration_credit',
                    'sortOrder' => 1,
                ],
            ],
            'dueDate' => CarbonImmutable::now()->addDays(14),
            'source' => 'subscribe',
            'recipientName' => 'ACME (Pvt) Ltd',
            'recipientEmail' => 'billing@acme.example',
            ...$overrides,
        ]);
    }

    /**
     * An issued (pending) invoice projected for $company through the domain
     * seam — the genesis path production takes, never a direct row write.
     */
    protected function anIssuedInvoiceFor(Company $company, SubscriptionReadModel $subscription): InvoiceReadModel
    {
        $invoiceId = (string) Str::uuid();

        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription))
            ->persist();

        return InvoiceReadModel::findOrFail($invoiceId);
    }

    /**
     * An invoice paid through the domain seam (issue → payment webhook).
     */
    protected function aPaidInvoiceFor(Company $company, SubscriptionReadModel $subscription): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * An invoice whose first payment attempt failed, through the domain seam (T3).
     */
    protected function aFailedInvoiceFor(Company $company, SubscriptionReadModel $subscription): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid payment-failure webhook command for $invoice — first attempt,
     * fresh PayHere event id. Override per test (attempt numbers, replays).
     */
    protected function recordPaymentFailedCommand(InvoiceReadModel $invoice, array $overrides = []): RecordPaymentFailedCommand
    {
        return RecordPaymentFailedCommand::validateAndCreate([
            'invoiceId' => $invoice->getKey(),
            'payHereEventId' => 'evt_fail_'.$invoice->getKey(),
            'failureCode' => 'insufficient_funds',
            'failureMessage' => 'Card has insufficient funds.',
            'attemptNumber' => 1,
            ...$overrides,
        ]);
    }

    /**
     * A paid invoice subsequently refunded through the domain seam (T6).
     */
    protected function aRefundedInvoiceFor(Company $company, SubscriptionReadModel $subscription): InvoiceReadModel
    {
        $invoice = $this->aPaidInvoiceFor($company, $subscription);

        Invoice::retrieve($invoice->getKey())
            ->refund($this->refundInvoiceCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid full-refund command for $invoice (admin-refund, PayHere ref).
     * Override per test (T5 credit memos, amount mismatches).
     */
    protected function refundInvoiceCommand(InvoiceReadModel $invoice, array $overrides = []): RefundInvoiceCommand
    {
        return RefundInvoiceCommand::validateAndCreate([
            'invoiceId' => $invoice->getKey(),
            'refundAmountCents' => $invoice->amount_cents,
            'payHereRefundTransactionId' => 'refund_txn_'.$invoice->getKey(),
            'source' => 'admin-refund',
            'reason' => 'Goodwill refund issued by platform support.',
            ...$overrides,
        ]);
    }

    /**
     * A pending invoice voided through the domain seam (T7).
     */
    protected function aVoidedInvoiceFor(Company $company, SubscriptionReadModel $subscription): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription);

        Invoice::retrieve($invoice->getKey())
            ->void($this->voidInvoiceCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid admin-correction void command for $invoice, actor freshly minted.
     * Override per test (dispute-pre-payment source, specific actors).
     */
    protected function voidInvoiceCommand(InvoiceReadModel $invoice, array $overrides = []): VoidInvoiceCommand
    {
        return VoidInvoiceCommand::validateAndCreate([
            'invoiceId' => $invoice->getKey(),
            'voidedBy' => User::factory()->create()->getKey(),
            'reason' => 'Issued in error - admin correction.',
            'source' => 'admin-correction',
            ...$overrides,
        ]);
    }

    /**
     * Seam-fixture dispatch for DataProvider state matrices (§2.12) — every
     * state is arranged through real aggregate commands, never row writes.
     */
    protected function anInvoiceInState(string $state, Company $company, SubscriptionReadModel $subscription): InvoiceReadModel
    {
        return match ($state) {
            'pending' => $this->anIssuedInvoiceFor($company, $subscription),
            'paid' => $this->aPaidInvoiceFor($company, $subscription),
            'refunded' => $this->aRefundedInvoiceFor($company, $subscription),
            'voided' => $this->aVoidedInvoiceFor($company, $subscription),
            default => throw new InvalidArgumentException("Unknown invoice state fixture [{$state}]."),
        };
    }

    /**
     * A valid payment-succeeded webhook command for $invoice — full amount,
     * fresh PayHere identifiers. Override per test (e.g. replayed event ids).
     */
    protected function recordPaymentSucceededCommand(InvoiceReadModel $invoice, array $overrides = []): RecordPaymentSucceededCommand
    {
        return RecordPaymentSucceededCommand::validateAndCreate([
            'invoiceId' => $invoice->getKey(),
            'payHereEventId' => 'evt_'.$invoice->getKey(),
            'payHereTransactionId' => 'txn_'.$invoice->getKey(),
            'amountCents' => $invoice->amount_cents,
            ...$overrides,
        ]);
    }

    /**
     * Exactly $expectedCount events are stored for this invoice aggregate —
     * the no-op proof for guard and webhook-replay walks (LD stored-event-count
     * precedent; MembershipsTestCase event-pin family).
     */
    protected function assertNoNewInvoiceEvents(string $invoiceId, int $expectedCount): void
    {
        $this->assertSame(
            $expectedCount,
            LesilyStoredEvent::query()->where('aggregate_uuid', $invoiceId)->count(),
        );
    }

    /**
     * Exactly one $eventClass is stored for this invoice aggregate, matching
     * every pinned payload property — the amendment-R mechanism proof that the
     * write went through the event stream (MembershipsTestCase precedent).
     */
    protected function assertInvoiceEventRecorded(string $eventClass, string $invoiceId, array $propertyPins = []): void
    {
        $query = LesilyStoredEvent::query()
            ->where('event_class', $eventClass)
            ->where('aggregate_uuid', $invoiceId);

        foreach ($propertyPins as $property => $value) {
            $query->where("event_properties->{$property}", $value);
        }

        $this->assertSame(1, $query->count());
    }

    /**
     * The projected invoices row carries the expected column state.
     */
    protected function assertInvoiceRowState(string $invoiceId, array $expected): void
    {
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, ...$expected]);
    }

    /**
     * Exactly the expected line-item rows were projected for this invoice —
     * count plus per-row column pins (immutable after creation per ERD-1-04).
     */
    protected function assertLineItemsProjected(string $invoiceId, array $expectedRows): void
    {
        $this->assertSame(
            count($expectedRows),
            InvoiceLineItem::query()->where('invoice_id', $invoiceId)->count(),
        );

        foreach ($expectedRows as $row) {
            $this->assertDatabaseHas('invoice_line_items', ['invoice_id' => $invoiceId, ...$row]);
        }
    }

    /**
     * The subscription state matches the designed 13-key contract exactly —
     * payload identity against literal expectations, so nothing extra can leak
     * and every documented-null key is proven present (B5).
     */
    protected function assertExactSubscriptionContract(TestResponse $response, array $expected): void
    {
        $response->assertOk();
        $response->assertExactJson(['data' => $expected]);
    }

    /**
     * The subscription state came back — 200 with exactly the 13 designed
     * contract keys in the {data: {...}} envelope (LD assertJsonCount
     * shape-lock pattern).
     */
    protected function assertSubscriptionShapeReturned(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonCount(13, 'data');
        $response->assertJsonStructure(['data' => [
            'id', 'status', 'plan', 'trial_ends_at', 'add_ons', 'cancelled_at', 'ended_source',
            'billing_cycle', 'period_start', 'period_end', 'next_charge_at',
            'payment_method_summary', 'past_due_status',
        ]]);
    }

    /**
     * The roles the 2026-05-24 matrix grants billing.view (owner ✅, admin ✅,
     * auditor 👁️ read-only) — shared by every billing.view-gated read (§2.12).
     *
     * @return array<string, array{string}>
     */
    public static function provideViewingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * The seeded roles the 2026-05-24 matrix denies billing.view.
     *
     * @return array<string, array{string}>
     */
    public static function provideDeniedRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }
}
