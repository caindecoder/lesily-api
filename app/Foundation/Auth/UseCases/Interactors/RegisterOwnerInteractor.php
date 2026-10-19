<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Consent\Aggregates\Consent;
use App\Companies\Aggregates\Company;
use App\Models\User as UserReadModel;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Validation\ValidationException;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use App\Foundation\Auth\UseCases\Results\RegisterResult;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;

/**
 * The fused owner-signup use case (system-architecture §3.2.1, Pattern A): every
 * aggregate of the registration commits in ONE transaction — a Company without an
 * owner, or a Subscription without a Company, must be impossible.
 */
class RegisterOwnerInteractor
{
    public function __construct(
        private RegistrationUniquenessGuard $uniqueness,
        private SessionIssuer $sessionIssuer,
    ) {}

    public function execute(RegisterOwnerCommand $command, ?string $clientIp, string $deviceName): RegisterResult
    {
        // A consent record is evidence and is never minted without the client IP
        // it was given from (UC-1-069); an unresolvable IP is refused up front.
        if (blank($clientIp)) {
            throw ValidationException::withMessages(['ip' => 'The ip field is required.']);
        }

        // Reject a taken email / BR / EPF before opening the transaction, so a
        // conflict never leaves a partial account behind (E04 §13 — do something
        // or handle errors, not both).
        $this->uniqueness->ensureUnique($command);

        [$userId, $companyId] = $this->persistRegistration($command, $clientIp);

        // After the transaction commits, the projectors have written the owner's `users`
        // read-model row. Materialise it and issue the session here — token mint is a
        // credential side-effect, deliberately OUTSIDE the registration's atomic transaction
        // (a failed mint leaves a registered owner who can simply log in), and it belongs in
        // the use case, not the controller (E07 — the relocated findOrFail + createToken).
        $user = UserReadModel::query()->findOrFail($userId);
        $user->last_logged_at = now();
        $user->save();

        return new RegisterResult($user, $companyId, $this->sessionIssuer->issue($user, $deviceName));
    }

    /**
     * Persist the fused owner-signup aggregates atomically and return the generated ids.
     * The DB UNIQUE backstop maps a concurrent duplicate to the field-specific 409; an
     * unmapped violation re-raises untouched.
     *
     * @return array{string, string} [userId, companyId]
     */
    private function persistRegistration(RegisterOwnerCommand $command, string $clientIp): array
    {
        try {
            return DB::transaction(function () use ($command, $clientIp): array {
                $userId = Str::uuid7()->toString();
                $companyId = Str::uuid7()->toString();

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

                Consent::retrieve(Str::uuid7()->toString())
                    ->record(RecordConsentCommand::from([
                        'userId' => $userId,
                        'companyId' => $companyId,
                        'policyVersions' => config('lesily.policies'),
                        'ip' => $clientIp,
                    ]))
                    ->persist();

                Subscription::retrieve(Str::uuid7()->toString())
                    ->startTrial(StartTrialCommand::from([
                        'companyId' => $companyId,
                        'trialEndsAt' => CarbonImmutable::now()->addDays(Company::TRIAL_DAYS),
                    ]))
                    ->persist();

                return [$userId, $companyId];
            });
        } catch (UniqueConstraintViolationException $violation) {
            // A concurrent signup slipped past the pre-tx guard; the DB UNIQUE index
            // is the backstop. Surface the same field-specific conflict — or re-raise
            // an unmapped violation untouched, so a genuine fault is never masked as a 409.
            throw $this->uniqueness->conflictFor($violation) ?? $violation;
        }
    }

    protected function buildCreateCompanyCommand(RegisterOwnerCommand $command, string $ownerUserId): CreateCompanyCommand
    {
        return CreateCompanyCommand::from([
            'name' => $command->companyName,
            'brNumber' => $command->brNumber,
            'epfNumber' => $command->epfNumber,
            'country' => $command->country,
            'ownerUserId' => $ownerUserId,
        ]);
    }
}
