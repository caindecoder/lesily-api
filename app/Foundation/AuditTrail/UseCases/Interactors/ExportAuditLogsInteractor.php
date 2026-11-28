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
use App\Foundation\AuditTrail\UseCases\Commands\ExportAuditLogsCommand;

class ExportAuditLogsInteractor
{
    public function __construct(
        protected AuditLogRows $auditLogRows,
        protected AuditTrailWriter $auditTrailWriter,
        protected MemberRoleCode $memberRoleCode,
    ) {}

    /**
     * @return LazyCollection<int, stdClass>
     */
    public function execute(ExportAuditLogsCommand $exportAuditLogsCommand, User $actor, RequestOrigin $requestOrigin): LazyCollection
    {
        $exportedAt = CarbonImmutable::now();
        $this->recordExport($exportAuditLogsCommand, $actor, $requestOrigin);

        return $this->auditLogRows->newestFirst($exportAuditLogsCommand, $exportedAt);
    }

    protected function recordExport(ExportAuditLogsCommand $exportAuditLogsCommand, User $actor, RequestOrigin $requestOrigin): void
    {
        $companyId = $exportAuditLogsCommand->company->getKey();

        $this->auditTrailWriter->write([
            ...$this->auditTrailWriter->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $companyId), $requestOrigin),
            'company_id' => $companyId,
            'stored_event_id' => null,
            'event_class' => 'AuditLogsExported',
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $companyId,
            'subject_label' => null,
            'metadata' => [
                'format' => $exportAuditLogsCommand->format->value,
                'from' => $this->auditLogRows->from($exportAuditLogsCommand, $exportAuditLogsCommand->company)->toDateString(),
                'until' => ($exportAuditLogsCommand->within?->getIncludedEndDate() ?? today())->toDateString(),
                ...array_filter(['user' => $exportAuditLogsCommand->user, 'event' => $exportAuditLogsCommand->event, 'ip' => $exportAuditLogsCommand->ip]),
            ],
        ], AuditAction::AuditLogsExported, 'warning');
    }
}
