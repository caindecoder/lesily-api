<?php

namespace App\Companies\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Validation\ValidationException;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;
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
    public function __construct(
        private RegistrationUniquenessGuard $uniqueness,
    ) {}

    public function execute(CreateCompanyCommand $command, ?string $clientIp, ?string $companyId = null): Company
    {
        // A consent record is evidence and is never minted without the client IP
        // it was given from (UC-1-069).
        if (blank($clientIp)) {
            throw ValidationException::withMessages(['ip' => 'The ip field is required.']);
        }

        $companyId ??= Str::uuid7()->toString();

        try {
            $this->open($command, $clientIp, $companyId);
        } catch (UniqueConstraintViolationException $violation) {
            // A taken BR / EPF is refused by its UNIQUE index: the same field 409 as
            // signup's guard, and an unmapped violation re-raises untouched.
            throw $this->uniqueness->conflictFor($violation) ?? $violation;
        }

        return Company::query()->findOrFail($companyId);
    }

    protected function open(CreateCompanyCommand $command, string $clientIp, string $companyId): void
    {
        DB::transaction(function () use ($command, $clientIp, $companyId): void {
            CompanyAggregate::retrieve($companyId)->create($command)->persist();

            User::retrieve($command->owner->getKey())
                ->setEmergencyContact($companyId, SetEmergencyContactCommand::from([
                    'name' => $command->emergencyContactName,
                    'email' => $command->emergencyContactEmail,
                    'phone' => $command->emergencyContactPhone,
                    'relationship' => $command->emergencyContactRelationship,
                ]))
                ->persist();

            Consent::retrieve(Str::uuid7()->toString())
                ->record(RecordConsentCommand::from([
                    'userId' => $command->owner->getKey(),
                    'companyId' => $companyId,
                    'policyVersions' => config('lesily.policies'),
                    'ip' => $clientIp,
                ]))
                ->persist();

            Subscription::retrieve(Str::uuid7()->toString())
                ->startTrial(StartTrialCommand::from([
                    'company' => Company::query()->findOrFail($companyId),
                    'trialEndsAt' => CarbonImmutable::now()->addDays(CompanyAggregate::TRIAL_DAYS),
                ]))
                ->persist();
        });
    }
}
