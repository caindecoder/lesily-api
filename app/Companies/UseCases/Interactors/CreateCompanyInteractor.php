<?php

namespace App\Companies\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Auth\Aggregates\User;
use App\Consent\UseCases\Audit\ConsentAudit;
use App\Companies\UseCases\Audit\CompanyAudit;
use Illuminate\Validation\ValidationException;
use App\Foundation\CountryConfig\Entities\Country;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\Exceptions\UnsupportedCountryException;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Companies\Memberships\UseCases\Audit\MembershipAudit;
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
        private CompanyAudit $audit,
        private ConsentAudit $consentAudit,
        private MembershipAudit $membershipAudit,
    ) {}

    public function execute(CreateCompanyCommand $command, RequestOrigin $origin, ?string $companyId = null): Company
    {
        // A consent record is evidence and is never minted without the client IP
        // it was given from (UC-1-069).
        if (blank($origin->ip)) {
            throw ValidationException::withMessages(['ip' => 'The ip field is required.']);
        }

        $companyId ??= Str::uuid7()->toString();

        try {
            $this->open($command, $origin, $companyId);
        } catch (UniqueConstraintViolationException $violation) {
            // A taken BR / EPF is refused by its UNIQUE index: the same field 409 as
            // signup's guard, and an unmapped violation re-raises untouched.
            throw $this->uniqueness->conflictFor($violation) ?? $violation;
        }

        return Company::query()->findOrFail($companyId);
    }

    protected function open(CreateCompanyCommand $command, RequestOrigin $origin, string $companyId): void
    {
        DB::transaction(function () use ($command, $origin, $companyId): void {
            $this->assertSupportedCountry($command->country);

            CompanyAggregate::retrieve($companyId)->create($command)->persist();
            $company = Company::query()->findOrFail($companyId);

            $contact = SetEmergencyContactCommand::from([
                'company' => $company,
                'setBy' => $command->owner,
                'name' => $command->emergencyContactName,
                'email' => $command->emergencyContactEmail,
                'phone' => $command->emergencyContactPhone,
                'relationship' => $command->emergencyContactRelationship,
            ]);
            $membership = CompanyUser::query()->where(['company_id' => $companyId, 'user_id' => $command->owner->getKey()])->sole();
            $contactChanges = $contact->changesFrom($membership);

            User::retrieve($command->owner->getKey())
                ->setEmergencyContact($companyId, $contact)
                ->persist();

            $consentId = Str::uuid7()->toString();
            Consent::retrieve($consentId)
                ->record(RecordConsentCommand::from([
                    'user' => $command->owner,
                    'company' => $company,
                    'policyVersions' => config('lesily.policies'),
                    'ip' => $origin->ip,
                ]))
                ->persist();

            Subscription::retrieve(Str::uuid7()->toString())
                ->startTrial(StartTrialCommand::from([
                    'company' => $company,
                    'trialEndsAt' => CarbonImmutable::now()->addDays(CompanyAggregate::TRIAL_DAYS),
                ]))
                ->persist();

            $this->audit->created($company, $command->owner, $origin);
            $this->membershipAudit->emergencyContactSet($membership, $contactChanges, $origin);
            $this->consentAudit->recorded($consentId, $command->owner, $origin);
        });
    }

    /**
     * The code must match a stored country exactly — the column's collation
     * ignores case, so `lk` would otherwise find Sri Lanka.
     */
    protected function assertSupportedCountry(string $code): void
    {
        if (Country::query()->where('code', $code)->value('code') !== $code) {
            throw UnsupportedCountryException::notSupported();
        }
    }
}
