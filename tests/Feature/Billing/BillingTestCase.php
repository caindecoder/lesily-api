<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;
use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsAuditTrail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Exceptions;
use App\Billing\Invoices\Entities\InvoiceLineItem;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Invoices\Exceptions\CorruptReceiptSnapshotException;
use App\Billing\Invoices\Exceptions\ReceiptRenderingFailedException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Billing\Invoices\UseCases\Renderers\ReceiptPdfRendererInterface;

abstract class BillingTestCase extends TestCase
{
    use AssertsAuditTrail;
    use CreatesInvoices;

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
     * An authenticated member requests the company's invoice list under
     * $company's context, with optional whitelisted query params (UC-1-120).
     */
    protected function listInvoicesAs(User $user, Company $company, array $query = []): TestResponse
    {
        Sanctum::actingAs($user);

        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return $this->getJson('/api/v1/invoices'.$queryString, ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * An authenticated member downloads $invoiceId's receipt under $company's
     * context (UC-1-121). Plain get() — the happy body is a binary PDF stream,
     * not JSON; error paths still render §S21 envelopes.
     */
    protected function downloadReceiptAs(User $user, Company $company, string $invoiceId): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->get("/api/v1/invoices/{$invoiceId}/receipt", [
            'X-Company-Context' => $company->getKey(),
            'Accept' => 'application/json',
        ]);
    }

    /**
     * The receipt was refused by the state guard — 409 STATE_GUARD_VIOLATION
     * with the exact refusal message and a JSON (never PDF) body; non-paid
     * refusals echo the stored status as current_status (UC-1-121 5a/5b,
     * s135 F-c).
     */
    protected function assertReceiptStateGuardRejected(TestResponse $response, string $message, ?string $currentStatus = null): void
    {
        $response->assertConflict();
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', $message);
        $response->assertHeader('Content-Type', 'application/json');

        if ($currentStatus !== null) {
            $response->assertJsonPath('current_status', $currentStatus);
        } else {
            // The dispute refusal carries NO current_status key — pins the
            // null arm of the bootstrap render ternary, which Infection and
            // coverage cannot see (test-analyzer IMPORTANT-1, s135).
            $response->assertJsonMissingPath('current_status');
        }
    }

    /**
     * The receipt lookup was refused as not found — 404 RESOURCE_NOT_FOUND with
     * the §S21 envelope and a JSON (never PDF) body. One shared helper for the
     * unknown-id AND cross-tenant walks so the two responses cannot drift apart
     * (UC-1-121 4a no-existence-leak parity).
     */
    protected function assertInvoiceNotFound(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
    }

    /**
     * The invoice list came back empty — 200 with `data: []` under the exact
     * default §8.3 pagination meta (never a 404; UC-1-120 alt 6a / alt 4a).
     */
    protected function assertEmptyInvoiceCollection(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertExactJson([
            'data' => [],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
        ]);
    }

    /**
     * Exactly these invoice ids came back, in exactly this order — 200 plus
     * ordered row identity, so nothing extra, missing, or shuffled can pass.
     *
     * @param  array<int, string>  $invoiceIds
     */
    protected function assertInvoiceIdsInOrder(TestResponse $response, array $invoiceIds): void
    {
        $response->assertOk();
        $this->assertSame($invoiceIds, $response->json('data.*.id'));
    }

    /**
     * The invoice page came back as expected — 200, exactly $rowCount rows,
     * and the exact §8.3 pagination meta (key order included).
     *
     * @param  array<string, int>  $meta
     */
    protected function assertInvoicePage(TestResponse $response, int $rowCount, array $meta): void
    {
        $response->assertOk();
        $response->assertJsonCount($rowCount, 'data');
        $this->assertSame($meta, $response->json('meta.pagination'));
    }

    /**
     * Exactly these invoice ids came back, any order — 200 plus row identity,
     * for filter tests that pin membership without pinning sort.
     *
     * @param  array<int, string>  $invoiceIds
     */
    protected function assertInvoiceIdsExactly(TestResponse $response, array $invoiceIds): void
    {
        $response->assertOk();

        $returned = $response->json('data.*.id');
        sort($invoiceIds);
        sort($returned);

        $this->assertSame($invoiceIds, $returned);
    }

    /**
     * A hand-written throwing renderer stub bound for THIS test only (E23 —
     * stub across the boundary; every other walk uses the real renderer). The
     * app-owned exception is what the Chromium adapter itself raises on any
     * render-time failure, so the 503 contract is library-agnostic.
     */
    protected function bindThrowingReceiptRenderer(): void
    {
        $this->app->instance(ReceiptPdfRendererInterface::class, new class implements ReceiptPdfRendererInterface
        {
            public function render(InvoiceReadModel $invoice): string
            {
                throw new ReceiptRenderingFailedException('Chromium renderer unreachable (test stub).');
            }
        });
    }

    /**
     * A renderer stub that must never run — bound for the corrupt-snapshot walk
     * (7c validates BEFORE the port is called). If validation is missing, the
     * request reports THIS RuntimeException instead of the corrupt-snapshot
     * exception, and the assertReported pin below fails the test.
     */
    protected function bindMustNotRenderGuard(): void
    {
        $this->app->instance(ReceiptPdfRendererInterface::class, new class implements ReceiptPdfRendererInterface
        {
            public function render(InvoiceReadModel $invoice): string
            {
                throw new RuntimeException('The renderer must not be invoked for a corrupt snapshot (UC-1-121 7c).');
            }
        });
    }

    /**
     * The corrupt snapshot failed safely — generic 500 §S21 envelope (no
     * internal details), JSON not PDF, and the corrupt-snapshot exception
     * reported carrying the invoice id for ops triage (UC-1-121 7c; s135 F-e).
     */
    protected function assertCorruptSnapshotRejected(TestResponse $response, string $invoiceId): void
    {
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
        Exceptions::assertReported(
            fn (CorruptReceiptSnapshotException $e): bool => str_contains($e->getMessage(), $invoiceId)
        );
    }

    /**
     * Nothing was persisted to app storage by the render — the receipt is a
     * transient projection, regenerated per request (R3; ERD-1-04 OQ#7
     * Confirmed). Requires Storage::fake() in the test's arrange.
     */
    protected function assertNoArtifactPersisted(): void
    {
        $this->assertSame([], Storage::disk()->allFiles());
    }

    /**
     * The receipt downloaded as a complete PDF — 200 application/pdf with the
     * exact attachment filename, %PDF magic bytes, and a non-empty stream
     * (UC-1-121 main flow step 7; LD ICS getContent byte-assert idiom).
     */
    protected function assertPdfReceiptResponse(TestResponse $response, string $invoiceNumber): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="receipt-'.$invoiceNumber.'.pdf"');

        $content = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertGreaterThan(4, strlen($content));
    }

    /**
     * The receipt failed service-side — 503 RECEIPT_GENERATION_UNAVAILABLE
     * §S21 envelope with Retry-After: 30 and a JSON (never truncated-PDF)
     * body (UC-1-121 7b; s135 F-d).
     */
    protected function assertReceiptGenerationUnavailable(TestResponse $response): void
    {
        $response->assertServiceUnavailable();
        $response->assertJsonPath('error_code', 'RECEIPT_GENERATION_UNAVAILABLE');
        $response->assertJsonPath('message', 'Receipt generation is temporarily unavailable. Please try again shortly.');
        $response->assertHeader('Retry-After', '30');
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $this->assertStringStartsNotWith('%PDF', (string) $response->getContent());
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
     * The invoice list matches the contract exactly — payload identity for the
     * whole envelope (8-key rows + §8.3 meta), so nothing beyond the contract
     * can leak (spec §6 exact-envelope leak-guard).
     *
     * @param  array<string, mixed>  $expectedEnvelope
     */
    protected function assertExactInvoiceListContract(TestResponse $response, array $expectedEnvelope): void
    {
        $response->assertOk();
        $response->assertExactJson($expectedEnvelope);
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
