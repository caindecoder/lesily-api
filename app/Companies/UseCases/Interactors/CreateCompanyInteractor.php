<?php

namespace App\Companies\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use App\Foundation\Auth\Aggregates\User;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Companies\Memberships\UseCases\Commands\SetEmergencyContactCommand;

/**
 * Opens a company for its owner (UC-1-031): the company with its settings and owner
 * row, the owner's emergency contact for it, the owner's consent for it and its own
 * trial, in one transaction. Owner signup calls it inside its own transaction with the
 * company id it already put on the owner's genesis; POST /companies lets it mint one
 * (Pattern B, coordination-patterns §4).
 */
class CreateCompanyInteractor
{
    public function execute(CreateCompanyCommand $command, string $clientIp, ?string $companyId = null): Company
    {
        $companyId ??= Str::uuid7()->toString();

        DB::transaction(function () use ($command, $clientIp, $companyId): void {
            CompanyAggregate::retrieve($companyId)->create($command)->persist();

            User::retrieve($command->ownerUserId)
                ->setEmergencyContact($companyId, SetEmergencyContactCommand::from([
                    'name' => $command->emergencyContactName,
                    'email' => $command->emergencyContactEmail,
                    'phone' => $command->emergencyContactPhone,
                    'relationship' => $command->emergencyContactRelationship,
                ]))
                ->persist();

            Consent::retrieve(Str::uuid7()->toString())
                ->record(RecordConsentCommand::from([
                    'userId' => $command->ownerUserId,
                    'companyId' => $companyId,
                    'policyVersions' => config('lesily.policies'),
                    'ip' => $clientIp,
                ]))
                ->persist();

            Subscription::retrieve(Str::uuid7()->toString())
                ->startTrial(StartTrialCommand::from([
                    'companyId' => $companyId,
                    'trialEndsAt' => CarbonImmutable::now()->addDays(CompanyAggregate::TRIAL_DAYS),
                ]))
                ->persist();
        });

        return Company::query()->findOrFail($companyId);
    }
}
