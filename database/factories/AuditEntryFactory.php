<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Append-only audit rows for the T6 foundation pins and T7's admin-read
 * walks (ERD-1-11). The definition is the UC-1-122 direct-write shape —
 * tenant-scoped, human actor, stored_event_id NULL (the outside-the-event-
 * flow case ERD-1-11 §5 anticipates). Concrete FK closures per the LD
 * factory idiom — the subject reference stays soft (polymorphic strings,
 * no morph relation). severity is deliberately OMITTED so the DB default
 * ('info') is the live path the T6 pin proves.
 *
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoiceNumber = 'INV-2026-000001';

        return [
            'company_id' => Company::factory(),
            'stored_event_id' => null,
            'event_class' => 'AdminInvoiceReviewed',
            'subject_type' => 'invoice',
            'subject_id' => (string) Str::uuid(),
            'subject_label' => $invoiceNumber,
            'action' => 'admin_reviewed',
            'description' => "Platform admin reviewed invoice {$invoiceNumber}.",
            'actor_user_id' => User::factory(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->faker->name(),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'request_id' => (string) Str::uuid(),
            'metadata' => null,
            'recorded_at' => now(),
        ];
    }

    /**
     * A platform-level row — not attributable to a single tenant
     * (company_id NULL per ERD-1-11 §3; visible only to platform admins).
     */
    public function platformLevel(): static
    {
        return $this->state(['company_id' => null]);
    }

    /**
     * A system-driven row — no human actor (timer expiry, scheduled jobs,
     * webhook handlers; actor columns NULL per ERD-1-11 §3).
     */
    public function system(): static
    {
        return $this->state([
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
        ]);
    }

    /**
     * A row linked back to a source domain event via the SOFT FK
     * (ERD-1-11 §5 — application-layer reference, no DB constraint).
     */
    public function fromStoredEvent(int $storedEventId): static
    {
        return $this->state(['stored_event_id' => $storedEventId]);
    }
}
