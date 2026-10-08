<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\UseCases\Queries\MemberRoleCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\Exceptions\DsarExportNotReadyException;
use App\Compliance\Dsar\Exceptions\DsarDownloadExpiredException;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;

class DownloadDsarExportInteractor
{
    public function __construct(private readonly MemberRoleCode $memberRoleCode) {}

    public function execute(DownloadDsarExportCommand $command, User $actor, AdminRequestContext $context): DsarRequestRecord
    {
        $company = Company::query()->findOrFail($command->companyId);

        $request = DsarRequestRecord::query()->whereCompany($company)->whereSubject($actor)->whereKey($command->requestId)->first()
            ?? throw new ModelNotFoundException;

        if ($request->status !== DsarRequestStatus::Fulfilled) {
            throw new DsarExportNotReadyException;
        }

        if ($request->hasDownloadWindowClosed()) {
            throw new DsarDownloadExpiredException;
        }

        $this->recordDownloaded($request, $actor, $context);

        return $request;
    }

    private function recordDownloaded(DsarRequestRecord $request, User $actor, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarExportDownloaded',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_downloaded',
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
