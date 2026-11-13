<?php

namespace App\Consent\UseCases\Audit;

use App\Models\User;
use App\Consent\Entities\ConsentRecord;
use App\Consent\Events\ConsentRecorded;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * One audit row per policy a consent recorded, written inside the caller's transaction.
 */
class ConsentAudit
{
    public function __construct(
        protected readonly MemberRoleCode $memberRoleCode,
        protected readonly AuditTrailWriter $writer,
    ) {}

    public function recorded(string $consentId, User $user, RequestOrigin $origin): void
    {
        $records = ConsentRecord::query()
            ->whereIn('stored_event_id', LesilyStoredEvent::query()->where('aggregate_uuid', $consentId)->select('id'))
            ->get();

        foreach ($records as $record) {
            $this->writer->write([
                'company_id' => $record->company_id,
                'stored_event_id' => $record->stored_event_id,
                'event_class' => class_basename(ConsentRecorded::class),
                'subject_type' => AuditSubjectType::Consent,
                'subject_id' => $record->getKey(),
                'subject_label' => null,
                ...$this->writer->actorAndOrigin($user, $this->memberRoleCode->of($user, $record->company_id), $origin),
                'metadata' => ['policy' => $record->policy_type, 'version' => $record->version],
            ], AuditAction::ConsentRecorded, 'info');
        }
    }
}
