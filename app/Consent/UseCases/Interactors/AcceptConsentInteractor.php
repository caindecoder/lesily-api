<?php

namespace App\Consent\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use Illuminate\Validation\ValidationException;
use App\Consent\UseCases\Commands\RecordConsentCommand;

class AcceptConsentInteractor
{
    public function execute(Company $company, User $user, ?string $clientIp): void
    {
        // A consent record is evidence and is never minted without the client IP
        // it was given from (UC-1-069).
        if (blank($clientIp)) {
            throw ValidationException::withMessages(['ip' => 'The ip field is required.']);
        }

        DB::transaction(function () use ($company, $user, $clientIp): void {
            Consent::retrieve(Str::uuid7()->toString())
                ->record(RecordConsentCommand::from([
                    'user' => $user,
                    'company' => $company,
                    'policyVersions' => config('lesily.policies'),
                    'ip' => $clientIp,
                ]))
                ->persist();
        });
    }
}
