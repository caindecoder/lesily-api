<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use Carbon\CarbonImmutable;
use App\Consent\Events\ConsentRecorded;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ConsentRecordsSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'consent_records';
    }

    public function title(): string
    {
        return 'Consent records';
    }

    /**
     * Each consent the subject gave in this company — the stored
     * ConsentRecorded IS the record (UC-1-069), oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->where('event_class', ConsentRecorded::class)
            ->where('event_properties->userId', $subjectUserId)
            ->orderBy('id')
            ->get()
            ->map(fn (LesilyStoredEvent $consent): array => [
                'policy_type' => $consent->event_properties['policyType'],
                'version' => $consent->event_properties['version'],
                'ip_address' => $consent->event_properties['ip'],
                'recorded_at' => CarbonImmutable::parse($consent->getAttribute('created_at'))->toISOString(),
            ])
            ->all();
    }
}
