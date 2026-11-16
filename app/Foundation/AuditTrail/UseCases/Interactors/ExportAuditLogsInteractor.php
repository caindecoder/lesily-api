<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use stdClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Queries\AuditLogRows;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogExportFilters;

class ExportAuditLogsInteractor
{
    public function __construct(
        protected AuditLogRows $auditLogRows,
        protected AuditTrailWriter $writer,
        protected MemberRoleCode $memberRoleCode,
    ) {}

    /**
     * @return LazyCollection<int, stdClass>
     */
    public function execute(AuditLogExportFilters $filters, User $actor, RequestOrigin $origin): LazyCollection
    {
        $exportedAt = CarbonImmutable::now();
        $this->recordExport($filters, $actor, $origin);

        return $this->auditLogRows->newestFirst($filters, $exportedAt);
    }

    protected function recordExport(AuditLogExportFilters $filters, User $actor, RequestOrigin $origin): void
    {
        $companyId = $filters->company->getKey();

        $this->writer->write([
            ...$this->writer->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $companyId), $origin),
            'company_id' => $companyId,
            'stored_event_id' => null,
            'event_class' => 'AuditLogsExported',
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $companyId,
            'subject_label' => null,
            'metadata' => [
                'format' => $filters->format->value,
                'from' => $this->auditLogRows->from($filters, $filters->company)->toDateString(),
                'until' => ($filters->within?->getIncludedEndDate() ?? today())->toDateString(),
                ...array_filter(['user' => $filters->user, 'event' => $filters->event, 'ip' => $filters->ip]),
            ],
        ], AuditAction::AuditLogsExported, 'warning');
    }
}
