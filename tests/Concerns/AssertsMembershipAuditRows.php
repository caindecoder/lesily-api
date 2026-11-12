<?php

namespace Tests\Concerns;

use App\Models\User;
use RuntimeException;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

trait AssertsMembershipAuditRows
{
    protected function assertRoleChangeAudited(TestResponse $response, CompanyUser $membership, string $fromRoleCode): void
    {
        $entry = $this->memberRowOf($response, $membership, AuditAction::RoleChanged);
        $toRoleCode = Role::query()->whereKey($membership->refresh()->role_id)->value('code');

        $roleChange = $entry->metadata['changes']['role'] ?? [];

        $this->assertSame([$fromRoleCode, $toRoleCode], [$roleChange['from'] ?? null, $roleChange['to'] ?? null]);
    }

    protected function memberRowOf(TestResponse $response, CompanyUser $membership, AuditAction $action): AuditEntry
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $member = User::query()->findOrFail($membership->user_id);
        $newestEvent = LesilyStoredEvent::query()->latest('id')->firstOrFail();
        $entry = AuditEntry::query()->where('action', $action)->where('subject_id', $member->getKey())->sole();

        $this->assertSame([
            'company_id' => $membership->company_id,
            'stored_event_id' => $newestEvent->getKey(),
            'event_class' => class_basename($newestEvent->event_class),
            'subject_type' => 'user',
            'subject_label' => $member->name,
            'membership_id' => $membership->getKey(),
            'description' => match ($action) {
                AuditAction::RoleChanged => 'Role changed',
                default => throw new RuntimeException("No member row words for {$action->value}."),
            },
            'ip_address' => self::DEVICE_IP,
            'user_agent' => self::DEVICE,
            'request_id' => $requestId,
            'severity' => 'warning',
        ], [
            'company_id' => $entry->company_id,
            'stored_event_id' => $entry->stored_event_id,
            'event_class' => $entry->event_class,
            'subject_type' => $entry->subject_type->value,
            'subject_label' => $entry->subject_label,
            'membership_id' => $entry->metadata['membership_id'] ?? null,
            'description' => $entry->description,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'request_id' => $entry->request_id,
            'severity' => $entry->severity,
        ]);

        return $entry;
    }
}
