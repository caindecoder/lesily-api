<?php

namespace App\Consent\Aggregates;

use App\Consent\Events\ConsentRecorded;
use App\Foundation\EventStore\AggregateRoot;
use App\Consent\UseCases\Commands\RecordConsentCommand;

/**
 * Append-only consent stream (UC-1-069): no lifecycle, no guards — every consent
 * (initial or re-consent on a version bump) appends a fresh ConsentRecorded.
 * Records are never updated or deleted (PDPA minimal guarantee).
 */
class Consent extends AggregateRoot
{
    public function record(RecordConsentCommand $command): static
    {
        $this->forCompany($command->companyId);

        foreach ($command->policyVersions as $policyType => $version) {
            $this->recordThat(new ConsentRecorded(
                userId: $command->userId,
                companyId: $command->companyId,
                policyType: $policyType,
                version: $version,
                ip: $command->ip,
            ));
        }

        return $this;
    }
}
