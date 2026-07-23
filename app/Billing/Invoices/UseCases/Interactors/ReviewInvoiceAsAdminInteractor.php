<?php

namespace App\Billing\Invoices\UseCases\Interactors;

use App\Models\User;
use App\Billing\Invoices\Entities\Invoice;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

/**
 * The platform-admin cross-tenant invoice review (UC-1-122) — the thin use
 * case behind PlatformAdminInvoicesController@show: the deliberately unscoped
 * find, then the ONE append-only audit_trail row recording the read.
 *
 * Deliberate, bounded CQS exception (E04 §9 — a query performing a write):
 * the audit insert is an orthogonal access-log observation, not part of the
 * return contract; it is append-only (repeat calls surprise nobody) and fires
 * ONLY on the success path — the not-found throw happens first, so no row is
 * ever written for a read that did not happen (UC-1-122 error-flow 3a).
 */
class ReviewInvoiceAsAdminInteractor
{
    public function __construct(private readonly InvoiceRepositoryInterface $invoices) {}

    public function execute(string $invoiceId, User $admin, AdminRequestContext $context): Invoice
    {
        $invoice = $this->invoices->findById($invoiceId);

        if ($invoice === null) {
            throw new ModelNotFoundException;
        }

        $this->recordAdminReview($invoice, $admin, $context);

        return $invoice;
    }

    /**
     * The cd-audit-trail Direct-write shape: stored_event_id NULL (a read
     * emits no domain event — B3), severity riding the DB default 'info'.
     * Immutable by convention — no update/delete path exists (UC-1-066
     * enforces the invariant mechanically in its own slice).
     */
    private function recordAdminReview(Invoice $invoice, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $invoice->company_id,
            'stored_event_id' => null,
            'event_class' => 'AdminInvoiceReviewed',
            'subject_type' => 'invoice',
            'subject_id' => $invoice->id,
            'subject_label' => $invoice->invoice_number,
            'action' => 'admin_reviewed',
            'description' => "Platform admin reviewed invoice {$invoice->invoice_number}.",
            'actor_user_id' => $admin->id,
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            // Client-controlled header values bounded to the varchar(255)
            // columns — MySQL strict mode 22001s an over-length insert, 500ing
            // a valid admin read otherwise (s137 I-1; bounded-fidelity ruling:
            // only allowlisted admins reach this write).
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
