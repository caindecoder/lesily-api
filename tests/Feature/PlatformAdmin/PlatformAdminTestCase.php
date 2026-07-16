<?php

namespace Tests\Feature\PlatformAdmin;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\QueryException;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Invoices\UseCases\Commands\VoidInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\IssueInvoiceCommand;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Billing\Invoices\UseCases\Commands\FileInvoiceDisputeCommand;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentFailedCommand;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentSucceededCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

abstract class PlatformAdminTestCase extends TestCase
{
    /**
     * Set the platform-admin allowlist exactly as the middleware will see it —
     * raw post-explode shapes (config/platform.php does the bare env split;
     * normalization is the middleware's job, so tests feed it raw arrays).
     */
    protected function allowlist(array $ids): void
    {
        config(['platform.admin_user_ids' => $ids]);
    }

    /**
     * Probe the platform.admin-gated surface (routes/testing.php) as $user, or
     * as a guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design, with no auth.company
     * in its chain (cd-foundation-http §3).
     */
    protected function probeAdminSurfaceAs(?User $user): TestResponse
    {
        if ($user !== null) {
            Sanctum::actingAs($user);
        }

        return $this->getJson('/api/_test/platform-admin-probe');
    }

    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog()
     * in the calling suite's setUp. Duplicated from BillingTestCase (module
     * bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A fresh user made a live member of a fresh company under a seeded role —
     * the tenant-side caller the admin gate must turn away.
     */
    protected function aTenantMember(string $roleCode): User
    {
        $user = User::factory()->create();

        Company::factory()->create()
            ->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row role gates read. Duplicated from BillingTestCase
     * (module bases are independent — established precedent).
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * A company on a paid plan with one fresh member under a seeded role.
     * Duplicated from BillingTestCase (module bases are independent).
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();

        return [$company, $this->aCompanyMember($company, $roleCode)];
    }

    /**
     * A trial subscription projected for $company through the domain seam —
     * the genesis event path production takes, never a direct row write.
     * Duplicated from BillingTestCase (module bases are independent).
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
     * A valid issue command against $subscription's company — two line items
     * whose signed amounts sum to the invoice total. Duplicated from
     * BillingTestCase (module bases are independent).
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
     * seam. Duplicated from BillingTestCase (module bases are independent).
     */
    protected function anIssuedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoiceId = (string) Str::uuid();

        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription, $overrides))
            ->persist();

        return InvoiceReadModel::findOrFail($invoiceId);
    }

    /**
     * An invoice paid through the domain seam (issue → payment webhook).
     * Duplicated from BillingTestCase (module bases are independent).
     */
    protected function aPaidInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid payment-success webhook command for $invoice. Duplicated from
     * BillingTestCase (module bases are independent).
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
     * A pending invoice voided through the domain seam. Duplicated from
     * BillingTestCase (module bases are independent).
     */
    protected function aVoidedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->void(VoidInvoiceCommand::validateAndCreate([
                'invoiceId' => $invoice->getKey(),
                'voidedBy' => User::factory()->create()->getKey(),
                'reason' => 'Issued in error - admin correction.',
                'source' => 'admin-correction',
            ]))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * An invoice whose first payment attempt failed, through the domain seam.
     * Duplicated from BillingTestCase (module bases are independent).
     */
    protected function aFailedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed(RecordPaymentFailedCommand::validateAndCreate([
                'invoiceId' => $invoice->getKey(),
                'payHereEventId' => 'evt_fail_'.$invoice->getKey(),
                'failureCode' => 'insufficient_funds',
                'failureMessage' => 'Card has insufficient funds.',
                'attemptNumber' => 1,
            ]))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A pending invoice with an open dispute filed through the domain seam.
     * Duplicated from BillingTestCase (module bases are independent).
     */
    protected function aDisputedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->fileDispute(FileInvoiceDisputeCommand::validateAndCreate([
                'invoiceId' => $invoice->getKey(),
                'reason' => 'This charge does not match our agreed plan.',
                'filedBy' => User::factory()->create()->getKey(),
            ]))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * Seam-fixture dispatch for DataProvider state matrices (§2.12) — the
     * T7-scoped subset of BillingTestCase's dispatcher (module bases are
     * independent); every state is arranged through real aggregate commands.
     */
    protected function anInvoiceInState(string $state, Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        return match ($state) {
            'voided' => $this->aVoidedInvoiceFor($company, $subscription, $overrides),
            'failed' => $this->aFailedInvoiceFor($company, $subscription, $overrides),
            'disputed' => $this->aDisputedInvoiceFor($company, $subscription, $overrides),
            default => throw new InvalidArgumentException("Unknown invoice state fixture [{$state}]."),
        };
    }

    /**
     * Call the REAL admin invoice-review endpoint (UC-1-122) as $caller, or as
     * a guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design, with no auth.company
     * in its chain (cd-foundation-http §3).
     */
    protected function reviewInvoiceAsAdmin(?User $caller, string $invoiceId, array $headers = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson("/api/v1/admin/invoices/{$invoiceId}", $headers);
    }

    /**
     * No read was recorded — the audit trail holds zero rows (the UC-1-122
     * "no row on 404 / denied caller" invariant; the read did not happen).
     */
    protected function assertNoAuditRecorded(): void
    {
        $this->assertDatabaseCount('audit_trail', 0);
    }

    /**
     * Exactly ONE audit row records the admin read, in the cd-audit-trail
     * Direct-write shape (stored_event_id NULL — a read emits no domain event,
     * B3; severity rides the DB default 'info'), with the request-context
     * columns populated (ip / user agent / correlation id / recorded_at).
     */
    protected function assertAdminReviewAuditRecorded(InvoiceReadModel $invoice, User $admin): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'AdminInvoiceReviewed',
            'subject_type' => 'invoice',
            'subject_id' => $invoice->getKey(),
            'subject_label' => $invoice->invoice_number,
            'action' => 'admin_reviewed',
            'description' => "Platform admin reviewed invoice {$invoice->invoice_number}.",
            'company_id' => $invoice->company_id,
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'stored_event_id' => null,
            'metadata' => null,
            'severity' => 'info',
        ]);

        // recorded_at needs no assert: the column is NOT NULL, so the row
        // matched above could not exist without it.
        $entry = AuditEntry::query()->sole();
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->user_agent);
        $this->assertNotNull($entry->request_id);
    }

    /**
     * The admin surface refused the caller with the generic 404 §S21 envelope —
     * byte-identical to any real not-found, so the /api/v1/admin/* family never
     * acknowledges its existence to non-admins (UC-1-122 2a no-info-leak).
     */
    protected function assertResourceNotFoundNoLeak(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The request was rejected as unauthenticated — 401 §S21 UNAUTHENTICATED.
     * Duplicated from BillingTestCase (module bases are independent —
     * established precedent).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The gate passed the caller through to the probe body.
     */
    protected function assertProbePassed(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertExactJson(['ok' => true]);
    }

    /**
     * The insert was rejected by the NOT NULL constraint on $column — the
     * SPECIFIC integrity violation (SQLSTATE 23000) named in the DRIVER
     * message (errorInfo[2]), not the rendered query: the exception message
     * embeds the full insert column list, so matching it would false-pass
     * for a violation on any other column (review finding CR-M-1). A missing
     * table (42S02) cannot satisfy the assertion either.
     */
    protected function assertNotNullViolationOn(QueryException $e, string $column): void
    {
        $this->assertSame('23000', (string) $e->getCode());
        $this->assertStringContainsString(
            "Column '{$column}' cannot be null",
            (string) ($e->errorInfo[2] ?? ''),
        );
    }
}
