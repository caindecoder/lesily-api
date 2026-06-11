<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Consent\Aggregates\Consent;
use App\Companies\Aggregates\Company;
use App\Foundation\Auth\Aggregates\User;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use App\Foundation\Auth\UseCases\Results\RegisterResult;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;

/**
 * The fused owner-signup use case (system-architecture §3.2.1, Pattern A): every
 * aggregate of the registration commits in ONE transaction — a Company without an
 * owner, or a Subscription without a Company, must be impossible.
 */
class RegisterOwnerInteractor
{
    public function execute(RegisterOwnerCommand $command, string $clientIp): RegisterResult
    {
        return DB::transaction(function () use ($command, $clientIp): RegisterResult {
            $userId = (string) Str::uuid7();
            $companyId = (string) Str::uuid7();

            // Persist order follows the FK chain (users → companies → company_user;
            // owner_id is a RESTRICT FK per #02): the owner user first, then the
            // company (whose projection also enrolls the owner pivot), and only
            // then the emergency contact, which projects onto that pivot row.
            // One transaction throughout — atomicity is unchanged.
            $owner = User::retrieve($userId)->createOwner($companyId, $command);
            $owner->persist();

            Company::retrieve($companyId)
                ->create($this->buildCreateCompanyCommand($command, $userId))
                ->persist();

            $owner->setEmergencyContact($companyId, $command)->persist();

            Consent::retrieve((string) Str::uuid7())
                ->record(RecordConsentCommand::validateAndCreate([
                    'userId' => $userId,
                    'companyId' => $companyId,
                    'policyVersions' => config('lesily.policies'),
                    'ip' => $clientIp,
                ]))
                ->persist();

            Subscription::retrieve((string) Str::uuid7())
                ->startTrial(StartTrialCommand::validateAndCreate([
                    'companyId' => $companyId,
                    'trialEndsAt' => CarbonImmutable::now()->addDays(Company::TRIAL_DAYS),
                ]))
                ->persist();

            return new RegisterResult(userId: $userId, companyId: $companyId);
        });
    }

    protected function buildCreateCompanyCommand(RegisterOwnerCommand $command, string $ownerUserId): CreateCompanyCommand
    {
        return CreateCompanyCommand::validateAndCreate([
            'name' => $command->companyName,
            'brNumber' => $command->brNumber,
            'epfNumber' => $command->epfNumber,
            'country' => $command->country,
            'ownerUserId' => $ownerUserId,
        ]);
    }
}
