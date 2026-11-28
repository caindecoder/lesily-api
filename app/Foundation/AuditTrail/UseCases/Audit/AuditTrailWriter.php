<?php

namespace App\Foundation\AuditTrail\UseCases\Audit;

use App\Models\User;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;

/**
 * The fields every audit row fills the same way, written inside the caller's transaction when it has one.
 */
class AuditTrailWriter
{
    /** @return array<string, mixed> */
    public function actorAndOrigin(User $actor, ?string $roleCode, RequestOrigin $requestOrigin): array
    {
        return [
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $roleCode,
            'actor_label' => $actor->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
        ];
    }

    /** @param array<string, mixed> $row */
    public function write(array $row, AuditAction $auditAction, string $severity): void
    {
        AuditEntry::query()->create([
            ...$row,
            'action' => $auditAction,
            'description' => __('dsar.codes.action.'.$auditAction->value),
            'severity' => $severity,
            'recorded_at' => now(),
        ]);
    }
}
