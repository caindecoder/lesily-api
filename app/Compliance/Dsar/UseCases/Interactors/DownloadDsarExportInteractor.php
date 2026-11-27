<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\UseCases\Responses\DsarExportFile;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Compliance\Dsar\Exceptions\DsarDownloadExpiredException;
use App\Compliance\Dsar\Exceptions\DsarExportFileMissingException;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;

class DownloadDsarExportInteractor
{
    public function __construct(private readonly MemberRoleCode $memberRoleCode) {}

    public function execute(DownloadDsarExportCommand $downloadDsarExportCommand, User $actor, RequestOrigin $requestOrigin): DsarExportFile
    {
        $dsarRequest = DsarRequestRecord::query()->whereCompany($downloadDsarExportCommand->company)->whereSubject($actor)->whereKey($downloadDsarExportCommand->dsarRequest->getKey())->first()
            ?? throw new ModelNotFoundException;

        $fulfilledAt = $dsarRequest->fulfilledAt();

        if (! $dsarRequest->isDownloadWindowOpen()) {
            throw new DsarDownloadExpiredException;
        }

        $path = $dsarRequest->exportFilePath();

        if (! Storage::disk('dsar')->exists($path)) {
            throw new DsarExportFileMissingException($dsarRequest->getKey());
        }

        $this->recordDownloaded($dsarRequest, $actor, $requestOrigin);

        return new DsarExportFile($path, $fulfilledAt);
    }

    protected function recordDownloaded(DsarRequestRecord $dsarRequest, User $actor, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarExportDownloaded',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $dsarRequest->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarDownloaded,
            'description' => 'DSAR export downloaded — by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($actor, $dsarRequest->company_id),
            'actor_label' => $actor->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
