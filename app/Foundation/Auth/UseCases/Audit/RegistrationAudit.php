<?php

namespace App\Foundation\Auth\UseCases\Audit;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit row of an owner's registration, written in their first company inside the
 * signup transaction.
 */
class RegistrationAudit
{
    public function __construct(
        protected readonly MemberRoleCode $memberRoleCode,
        protected readonly AuditTrailWriter $writer,
    ) {}

    public function registered(User $owner, Company $company, RequestOrigin $origin): void
    {
        $this->writer->write([
            'company_id' => $company->getKey(),
            'stored_event_id' => LesilyStoredEvent::query()
                ->where('aggregate_uuid', $owner->getKey())
                ->where('event_class', UserSignedUpAsOwner::class)
                ->sole()
                ->getKey(),
            'event_class' => class_basename(UserSignedUpAsOwner::class),
            'subject_type' => AuditSubjectType::User,
            'subject_id' => $owner->getKey(),
            'subject_label' => $owner->name,
            ...$this->writer->actorAndOrigin($owner, $this->memberRoleCode->of($owner, $company->getKey()), $origin),
            'metadata' => null,
        ], AuditAction::UserRegistered, 'info');
    }
}
