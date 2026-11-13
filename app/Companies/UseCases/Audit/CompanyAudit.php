<?php

namespace App\Companies\UseCases\Audit;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Events\CompanyCreated;
use App\Companies\Events\CompanyTrialExpired;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows about a company's lifecycle, written inside the caller's transaction.
 */
class CompanyAudit
{
    public function __construct(
        protected readonly MemberRoleCode $memberRoleCode,
        protected readonly AuditTrailWriter $writer,
    ) {}

    public function created(Company $company, User $owner, RequestOrigin $origin): void
    {
        $this->writer->write([
            ...$this->rowOf($company, CompanyCreated::class),
            ...$this->writer->actorAndOrigin($owner, $this->memberRoleCode->of($owner, $company->getKey()), $origin),
            'metadata' => ['name' => $company->name, 'country' => $company->country],
        ], AuditAction::CompanyCreated, 'info');
    }

    public function trialExpired(Company $company): void
    {
        $this->writer->write([
            ...$this->rowOf($company, CompanyTrialExpired::class),
            ...$this->writer->withoutActor(),
            'metadata' => null,
        ], AuditAction::TrialExpired, 'info');
    }

    /**
     * @param  class-string  $eventClass
     * @return array<string, mixed>
     */
    protected function rowOf(Company $company, string $eventClass): array
    {
        return [
            'company_id' => $company->getKey(),
            'stored_event_id' => LesilyStoredEvent::query()
                ->where('aggregate_uuid', $company->getKey())
                ->where('event_class', $eventClass)
                ->sole()
                ->getKey(),
            'event_class' => class_basename($eventClass),
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $company->getKey(),
            'subject_label' => $company->name,
        ];
    }
}
