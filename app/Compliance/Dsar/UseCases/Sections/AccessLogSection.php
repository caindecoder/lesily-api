<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Foundation\AccessLog\Entities\AccessLogEntry;

class AccessLogSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'access_log';
    }

    /**
     * The subject's sign-ins, sign-outs and refused requests, oldest first —
     * never the request id or anyone's id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return AccessLogEntry::query()
            ->where('user_id', $subjectUserId)
            ->orderBy('first_seen_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AccessLogEntry $entry): array => [
                'access_event' => $entry->event->value,
                'access_reason' => $entry->reason?->value,
                'http_method' => $entry->http_method,
                'route' => $entry->route,
                'ip_address' => $entry->ip_address,
                'user_agent' => $entry->user_agent,
                'occurrences' => $entry->occurrences,
                'first_seen_at' => $entry->first_seen_at->toISOString(),
                'last_seen_at' => $entry->last_seen_at->toISOString(),
            ])
            ->all();
    }
}
