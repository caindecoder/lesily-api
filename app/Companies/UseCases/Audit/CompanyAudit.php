<?php

namespace App\Companies\UseCases\Audit;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Events\CompanyCreated;
use Illuminate\Database\Eloquent\Builder;
use App\Companies\Enums\ResettableSetting;
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

    public function changed(CompanyChange $change, User $actor, RequestOrigin $origin): void
    {
        if ($change->changes->isEmpty()) {
            return;
        }

        $company = $change->company;

        $this->writer->write([
            ...$this->rowOf($company, $change->eventClass, $change->setting),
            ...$this->writer->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $company->getKey()), $origin),
            'metadata' => ['changes' => $change->changes->toArray()],
        ], $change->action, 'info');
    }

    /**
     * A system row: no actor, address, device or request id — those columns stay null.
     */
    public function trialExpired(Company $company): void
    {
        $this->writer->write([
            ...$this->rowOf($company, CompanyTrialExpired::class),
            'metadata' => null,
        ], AuditAction::TrialExpired, 'info');
    }

    /**
     * @param  class-string  $eventClass
     * @return array<string, mixed>
     */
    protected function rowOf(Company $company, string $eventClass, ?ResettableSetting $setting = null): array
    {
        return [
            'company_id' => $company->getKey(),
            'stored_event_id' => LesilyStoredEvent::query()
                ->where('aggregate_uuid', $company->getKey())
                ->where('event_class', $eventClass)
                ->when($setting, fn (Builder $query, ResettableSetting $setting) => $query->where('event_properties->setting', $setting->value))
                ->latest('id')
                ->firstOrFail()
                ->getKey(),
            'event_class' => class_basename($eventClass),
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $company->getKey(),
            'subject_label' => $company->name,
        ];
    }
}
