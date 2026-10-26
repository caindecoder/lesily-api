<?php

namespace App\Consent\Projectors;

use App\Consent\Entities\ConsentRecord;
use App\Consent\Events\ConsentRecorded;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Copies each ConsentRecorded into consent_records (ERD-1-11) — the table's sole
 * writer (conv-plan §2.15). The stored event stays the source; an event already
 * copied is skipped, so a replay adds nothing.
 */
class ConsentRecordProjector extends Projector
{
    public function onConsentRecorded(ConsentRecorded $event): void
    {
        ConsentRecord::query()->createOrFirst(['stored_event_id' => $event->storedEventId()], [
            'user_id' => $event->userId,
            'company_id' => $event->companyId,
            'policy_type' => $event->policyType,
            'version' => $event->version,
            'ip_address' => $event->ip,
            'consented_at' => $event->createdAt(),
        ]);
    }
}
