<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use Illuminate\Database\Eloquent\Builder;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;

class AuditTrailSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'audit_trail';
    }

    /**
     * The audit entries in this company the subject acted in or that are
     * about them, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return AuditEntry::query()
            ->where('company_id', $companyId)
            ->where(fn (Builder $builder): Builder => $builder
                ->where('actor_user_id', $subjectUserId)
                ->orWhere(fn (Builder $aboutBuilder): Builder => $aboutBuilder
                    ->where('subject_type', AuditSubjectType::User)
                    ->where('subject_id', $subjectUserId)))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditEntry $auditEntry): array => [
                'recorded_at' => $auditEntry->recorded_at->toISOString(),
                'action' => $auditEntry->action->value,
                'description' => $auditEntry->description,
                'subject_type' => $auditEntry->subject_type->value,
                'subject_id' => $auditEntry->subject_id,
                ...$this->originOf($auditEntry, $subjectUserId),
                'changes' => $this->changesOf($auditEntry),
            ])
            ->all();
    }

    /**
     * Each changed field with its old and new value, in plain words.
     *
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    protected function changesOf(AuditEntry $auditEntry): array
    {
        $changes = FieldChanges::readable($auditEntry->metadata['changes'] ?? null) ?? [];

        return array_map(
            fn (string $field, array $change): array => [
                'field' => $field,
                'from' => $change['from'] ?? null,
                'to' => $change['to'] ?? null,
            ],
            array_keys($changes),
            $changes,
        );
    }

    /**
     * Where the entry came from — only when the subject acted; another
     * person's network address and device are never in their export.
     *
     * @return array{ip_address: ?string, user_agent: ?string}
     */
    protected function originOf(AuditEntry $auditEntry, string $subjectUserId): array
    {
        if ($auditEntry->actor_user_id !== $subjectUserId) {
            return ['ip_address' => null, 'user_agent' => null];
        }

        return ['ip_address' => $auditEntry->ip_address, 'user_agent' => $auditEntry->user_agent];
    }
}
