<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Storage;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Dsar\UseCases\Queries\MemberRoleCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\UseCases\Responses\DsarExportFile;
use App\Compliance\Dsar\Exceptions\DsarDownloadExpiredException;
use App\Compliance\Dsar\Exceptions\DsarExportFileMissingException;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;

class DownloadDsarExportInteractor
{
    public function __construct(private readonly MemberRoleCode $memberRoleCode) {}

    public function execute(DownloadDsarExportCommand $command, User $actor, RequestOrigin $context): DsarExportFile
    {
        $company = Company::query()->findOrFail($command->companyId);

        $request = DsarRequestRecord::query()->whereCompany($company)->whereSubject($actor)->whereKey($command->requestId)->first()
            ?? throw new ModelNotFoundException;

        $fulfilledAt = $request->fulfilledAt();

        if (! $request->isDownloadWindowOpen()) {
            throw new DsarDownloadExpiredException;
        }

        $path = $request->exportFilePath();

        if (! Storage::disk('dsar')->exists($path)) {
            throw new DsarExportFileMissingException($request->getKey());
        }

        $this->recordDownloaded($request, $actor, $context);

        return new DsarExportFile($path, $fulfilledAt);
    }

    protected function recordDownloaded(DsarRequestRecord $request, User $actor, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarExportDownloaded',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarDownloaded,
            'description' => 'DSAR export downloaded — by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($actor, $request->company_id),
            'actor_label' => $actor->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
