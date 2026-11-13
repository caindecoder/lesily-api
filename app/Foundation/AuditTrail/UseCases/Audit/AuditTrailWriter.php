<?php

namespace App\Foundation\AuditTrail\UseCases\Audit;

use App\Models\User;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;

/**
 * The fields every audit row fills the same way, written inside the caller's transaction.
 */
class AuditTrailWriter
{
    /** @return array<string, mixed> */
    public function actorAndOrigin(User $actor, ?string $roleCode, RequestOrigin $origin): array
    {
        return [
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $roleCode,
            'actor_label' => $actor->name,
            'ip_address' => $origin->ip,
            'user_agent' => $origin->boundedUserAgent(),
            'request_id' => $origin->boundedRequestId(),
        ];
    }

    /** @return array<string, null> */
    public function withoutActor(): array
    {
        return [
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
        ];
    }

    /** @param array<string, mixed> $row */
    public function write(array $row, AuditAction $action, string $severity): void
    {
        AuditEntry::query()->create([
            ...$row,
            'action' => $action,
            'description' => __('dsar.codes.action.'.$action->value),
            'severity' => $severity,
            'recorded_at' => now(),
        ]);
    }
}
