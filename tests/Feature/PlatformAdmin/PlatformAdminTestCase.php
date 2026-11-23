<?php

namespace Tests\Feature\PlatformAdmin;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\QueryException;
use Tests\Feature\Billing\CreatesInvoices;
use Tests\Concerns\InducesDatabaseFailures;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;

abstract class PlatformAdminTestCase extends TestCase
{
    use CreatesInvoices;
    use HasPlatformAdmin;
    use InducesDatabaseFailures;

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
     * The request's one audit row records the platform admin's read, in the cd-audit-trail
     * Direct-write shape (stored_event_id NULL — a read emits no domain event,
     * B3; severity rides the DB default 'info'), with the request-context
     * columns IDENTITY-pinned (agent-triage MINOR-3: presence-only asserts
     * couldn't catch an ip↔user_agent swap): ip = the test client's loopback,
     * request_id = the very Correlation-Id the response carries.
     */
    protected function assertAdminReviewAuditRecorded(InvoiceReadModel $invoice, User $platformAdmin, TestResponse $response): void
    {
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'AdminInvoiceReviewed',
            'subject_type' => 'invoice',
            'subject_id' => $invoice->getKey(),
            'subject_label' => $invoice->invoice_number,
            'action' => 'admin_reviewed',
            'description' => "Platform admin reviewed invoice {$invoice->invoice_number}.",
            'company_id' => $invoice->company_id,
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $platformAdmin->name,
            'stored_event_id' => null,
            'metadata' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        // recorded_at needs no assert: the column is NOT NULL, so the row
        // matched above could not exist without it.
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->where('request_id', $correlationId)->sole();
        $this->assertSame('Symfony', $entry->user_agent);
    }

    /**
     * The admin surface refused the caller with the generic 404 §S21 envelope —
     * byte-identical to any real not-found, so the /api/v1/admin/* family never
     * acknowledges its existence to anyone but a platform admin (UC-1-122 2a no-info-leak).
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
