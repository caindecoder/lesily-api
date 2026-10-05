<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Foundation\AuditTrail\Entities\AuditEntry;

class AuditTrailSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'audit_trail';
    }

    public function title(): string
    {
        return 'Audit trail';
    }

    /**
     * The audit entries the subject acted in, in this company, oldest first
     * (ruling N25 — no writer names a user as an entry's subject yet).
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return AuditEntry::query()
            ->where('company_id', $companyId)
            ->where('actor_user_id', $subjectUserId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditEntry $entry): array => [
                'recorded_at' => $entry->recorded_at->toISOString(),
                'action' => $entry->action,
                'description' => $entry->description,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'ip_address' => $entry->ip_address,
                'user_agent' => $entry->user_agent,
            ])
            ->all();
    }
}
