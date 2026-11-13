<?php

namespace App\Consent\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use App\Consent\UseCases\Audit\ConsentAudit;
use Illuminate\Validation\ValidationException;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Consent\UseCases\Commands\RecordConsentCommand;

class AcceptConsentInteractor
{
    public function __construct(protected readonly ConsentAudit $audit) {}

    public function execute(Company $company, User $user, RequestOrigin $origin): void
    {
        // A consent record is evidence and is never minted without the client IP
        // it was given from (UC-1-069).
        if (blank($origin->ip)) {
            throw ValidationException::withMessages(['ip' => 'The ip field is required.']);
        }

        DB::transaction(function () use ($company, $user, $origin): void {
            $consentId = Str::uuid7()->toString();
            Consent::retrieve($consentId)
                ->record(RecordConsentCommand::from([
                    'user' => $user,
                    'company' => $company,
                    'policyVersions' => config('lesily.policies'),
                    'ip' => $origin->ip,
                ]))
                ->persist();

            $this->audit->recorded($consentId, $user, $origin);
        });
    }
}
