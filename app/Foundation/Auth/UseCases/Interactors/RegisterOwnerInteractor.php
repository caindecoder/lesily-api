<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Aggregates\Company;
use App\Foundation\Auth\Aggregates\User;
use App\Billing\Subscriptions\Aggregates\Subscription;
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
    public function execute(RegisterOwnerCommand $command): RegisterResult
    {
        return DB::transaction(function () use ($command): RegisterResult {
            $userId = (string) Str::uuid7();
            $companyId = (string) Str::uuid7();

            // The owner user persists first: companies.owner_id is a RESTRICT FK
            // to users (#02), so the projector needs the users row in place before
            // the company row lands. Same transaction — atomicity is unchanged.
            User::retrieve($userId)->createOwner($companyId, $command)->persist();

            Company::retrieve($companyId)
                ->create($this->buildCreateCompanyCommand($command, $userId))
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
