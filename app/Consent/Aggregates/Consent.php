<?php

namespace App\Consent\Aggregates;

use App\Consent\Events\ConsentRecorded;
use App\Foundation\EventStore\AggregateRoot;

/**
 * Append-only consent stream (UC-1-069): no lifecycle, no guards — every consent
 * (initial or re-consent on a version bump) appends a fresh ConsentRecorded.
 * Records are never updated or deleted (PDPA minimal guarantee).
 */
class Consent extends AggregateRoot
{
    /**
     * @param  array<string, string>  $policyVersions  policyType => version
     */
    public function record(string $userId, string $companyId, array $policyVersions, string $ip): static
    {
        $this->forCompany($companyId);

        foreach ($policyVersions as $policyType => $version) {
            $this->recordThat(new ConsentRecorded(
                userId: $userId,
                companyId: $companyId,
                policyType: $policyType,
                version: $version,
                ip: $ip,
            ));
        }

        return $this;
    }
}
