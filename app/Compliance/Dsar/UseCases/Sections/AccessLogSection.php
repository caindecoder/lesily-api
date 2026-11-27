<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use Illuminate\Database\Eloquent\Builder;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class AccessLogSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'access_log';
    }

    /**
     * The subject's access-log rows in this company or with no company,
     * oldest first — never the request id or anyone's id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return AccessLogEntry::query()
            ->where('user_id', $subjectUserId)
            ->where(fn (Builder $builder): Builder => $builder->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderBy('first_seen_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AccessLogEntry $accessLogEntry): array => [
                'access_event' => $accessLogEntry->event->value,
                'access_reason' => $accessLogEntry->reason?->value,
                'http_method' => $accessLogEntry->http_method,
                'route' => $accessLogEntry->route,
                'ip_address' => $accessLogEntry->ip_address,
                'user_agent' => $accessLogEntry->user_agent,
                'occurrences' => $accessLogEntry->occurrences,
                'first_seen_at' => $accessLogEntry->first_seen_at->toISOString(),
                'last_seen_at' => $accessLogEntry->last_seen_at->toISOString(),
            ])
            ->all();
    }
}
