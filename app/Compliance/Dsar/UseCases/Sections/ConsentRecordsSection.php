<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Consent\Entities\ConsentRecord;

class ConsentRecordsSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'consent_records';
    }

    /**
     * Each consent the subject gave in this company, from the consent store
     * (UC-1-069), oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return ConsentRecord::query()
            ->where('company_id', $companyId)
            ->where('user_id', $subjectUserId)
            ->orderBy('stored_event_id')
            ->get()
            ->map(fn (ConsentRecord $consent): array => [
                'policy_type' => $consent->policy_type,
                'version' => $consent->version,
                'ip_address' => $consent->ip_address,
                'recorded_at' => $consent->consented_at->toISOString(),
            ])
            ->all();
    }
}
