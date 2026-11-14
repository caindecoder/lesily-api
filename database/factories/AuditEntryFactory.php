<?php

namespace Database\Factories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;

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
            'subject_type' => AuditSubjectType::Invoice,
            'subject_id' => (string) Str::uuid(),
            'subject_label' => $invoiceNumber,
            'action' => AuditAction::AdminReviewed,
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

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->getKey()]);
    }

    /**
     * A row written by a company member acting under $roleCode.
     */
    public function byMember(User $member, string $roleCode): static
    {
        return $this->state([
            'actor_user_id' => $member->getKey(),
            'actor_role_code' => $roleCode,
            'actor_label' => $member->name,
        ]);
    }

    /**
     * A row written by a Lesily platform admin acting on the company's data.
     */
    public function byStaff(User $staff): static
    {
        return $this->state([
            'actor_user_id' => $staff->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $staff->name,
        ]);
    }

    /**
     * A member's row with no role on record (their membership was gone when it was written).
     */
    public function withoutRole(): static
    {
        return $this->state(['actor_role_code' => null]);
    }

    public function ofAction(AuditAction $action, AuditSubjectType $subjectType): static
    {
        return $this->state(['action' => $action, 'subject_type' => $subjectType]);
    }

    public function concerning(string $subjectId): static
    {
        return $this->state(['subject_id' => $subjectId]);
    }

    public function withSeverity(string $severity): static
    {
        return $this->state(['severity' => $severity]);
    }

    public function fromOrigin(string $ipAddress, string $userAgent): static
    {
        return $this->state(['ip_address' => $ipAddress, 'user_agent' => $userAgent]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    public function withChanges(array $changes): static
    {
        return $this->withMetadata(['changes' => $changes]);
    }

    public function recordedAt(DateTimeInterface $recordedAt): static
    {
        return $this->state(['recorded_at' => $recordedAt]);
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

    /**
     * A row carrying structured metadata (ERD-1-11 §3 — event-class-specific
     * JSON shapes; the array cast is the contract under test at T6).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(['metadata' => $metadata]);
    }
}
