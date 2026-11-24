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
        protected AuditTrailWriter $writer,
        protected MemberRoleCode $memberRoleCode,
    ) {}

    /**
     * @return LazyCollection<int, stdClass>
     */
    public function execute(ExportAuditLogsCommand $command, User $actor, RequestOrigin $origin): LazyCollection
    {
        $exportedAt = CarbonImmutable::now();
        $this->recordExport($command, $actor, $origin);

        return $this->auditLogRows->newestFirst($command, $exportedAt);
    }

    protected function recordExport(ExportAuditLogsCommand $command, User $actor, RequestOrigin $origin): void
    {
        $companyId = $command->company->getKey();

        $this->writer->write([
            ...$this->writer->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $companyId), $origin),
            'company_id' => $companyId,
            'stored_event_id' => null,
            'event_class' => 'AuditLogsExported',
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $companyId,
            'subject_label' => null,
            'metadata' => [
                'format' => $command->format->value,
                'from' => $this->auditLogRows->from($command, $command->company)->toDateString(),
                'until' => ($command->within?->getIncludedEndDate() ?? today())->toDateString(),
                ...array_filter(['user' => $command->user, 'event' => $command->event, 'ip' => $command->ip]),
            ],
        ], AuditAction::AuditLogsExported, 'warning');
    }
}
