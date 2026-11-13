<?php

namespace App\Foundation\AuditTrail\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\FieldChanges;

/**
 * @mixin AuditEntry
 */
class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'recorded_at' => $this->recorded_at->toISOString(),
            ...$this->who(),
            'action' => $this->action->value,
            'action_words' => __("dsar.codes.action.{$this->action->value}"),
            'subject_type' => $this->subject_type->value,
            'subject_type_words' => __("dsar.codes.subject_type.{$this->subject_type->value}"),
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'description' => $this->description,
            'changes' => FieldChanges::readable($this->metadata['changes'] ?? null),
            ...$this->origin(),
            'severity' => $this->severity,
        ];
    }

    /**
     * Lesily staff act on a company's data as "Lesily support" — never by name.
     *
     * @return array<string, mixed>
     */
    protected function who(): array
    {
        if ($this->isStaff()) {
            return ['actor_id' => null, 'actor_name' => __('dsar.codes.actor.lesily_support'), 'actor_role' => null, 'actor_role_name' => null];
        }

        return [
            'actor_id' => $this->actor_user_id,
            'actor_name' => $this->actor_label,
            'actor_role' => $this->actor_role_code,
            'actor_role_name' => $this->getAttribute('actor_role_name'),
        ];
    }

    /**
     * @return array{ip_address: ?string, device: ?string}
     */
    protected function origin(): array
    {
        if ($this->isStaff()) {
            return ['ip_address' => null, 'device' => null];
        }

        return ['ip_address' => $this->ip_address, 'device' => $this->user_agent];
    }

    protected function isStaff(): bool
    {
        return $this->actor_role_code === AuditEntry::STAFF_ROLE_CODE;
    }
}
