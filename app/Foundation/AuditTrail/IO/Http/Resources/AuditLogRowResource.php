<?php

namespace App\Foundation\AuditTrail\IO\Http\Resources;

use stdClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Foundation\AuditTrail\Entities\AuditEntry;

/**
 * One row of the merged audit logs: an access-log row (kind "access") or a security-relevant
 * audit-trail row (kind "audit").
 *
 * @property-read stdClass $resource
 */
class AuditLogRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kind' => $this->resource->kind,
            'time' => $this->isoTime($this->resource->time),
            'first_seen_at' => $this->isoTime($this->resource->first_seen_at),
            'occurrences' => $this->resource->occurrences,
            ...$this->who(),
            'event' => $this->resource->event,
            'event_words' => __("dsar.codes.{$this->eventFamily()}.{$this->resource->event}"),
            'outcome' => $this->resource->outcome,
            'outcome_words' => $this->resource->outcome === null ? null : __("dsar.codes.access_reason.{$this->resource->outcome}"),
            ...$this->origin(),
        ];
    }

    /**
     * Lesily staff act on a company's data as "Lesily support" — never by name (AR21).
     *
     * @return array{user_id: ?string, user_name: ?string}
     */
    protected function who(): array
    {
        if ($this->isStaff()) {
            return ['user_id' => null, 'user_name' => __('dsar.codes.actor.lesily_support')];
        }

        return ['user_id' => $this->resource->user_id, 'user_name' => $this->resource->user_name];
    }

    /**
     * @return array{ip_address: ?string, device: ?string}
     */
    protected function origin(): array
    {
        if ($this->isStaff()) {
            return ['ip_address' => null, 'device' => null];
        }

        return ['ip_address' => $this->resource->ip_address, 'device' => $this->resource->device];
    }

    protected function isStaff(): bool
    {
        return $this->resource->actor_role_code === AuditEntry::STAFF_ROLE_CODE;
    }

    protected function eventFamily(): string
    {
        return $this->resource->kind === 'access' ? 'access_event' : 'action';
    }

    protected function isoTime(string $storedTime): string
    {
        return CarbonImmutable::parse($storedTime, 'UTC')->toISOString();
    }
}
