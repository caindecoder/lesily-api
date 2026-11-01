<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\User as UserReadModel;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Validation\ValidationException;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Foundation\Auth\UseCases\Results\RegisterResult;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;
use App\Companies\UseCases\Interactors\CreateCompanyInteractor;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;

/**
 * The fused owner-signup use case (system-architecture §3.2.1): the owner and their
 * first company commit in ONE transaction — a Company without an owner, or a
 * Subscription without a Company, must be impossible. The company is opened by
 * CreateCompanyInteractor inside this transaction (Pattern B).
 */
class RegisterOwnerInteractor
{
    public function __construct(
        private RegistrationUniquenessGuard $uniqueness,
        private SessionIssuer $sessionIssuer,
        private CreateCompanyInteractor $createCompany,
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
     * Persist the owner and their first company atomically and return the generated
     * ids. A concurrent duplicate email is mapped here to its 409 (a BR / EPF one is
     * mapped inside CreateCompanyInteractor); an unmapped violation re-raises untouched.
     *
     * @return array{string, string} [userId, companyId]
     */
    protected function persistRegistration(RegisterOwnerCommand $command, string $clientIp): array
    {
        try {
            return DB::transaction(function () use ($command, $clientIp): array {
                $userId = Str::uuid7()->toString();
                $companyId = Str::uuid7()->toString();

                // The owner first — companies.owner_id is a RESTRICT FK to users (#02) —
                // and the company opened with the id the owner's genesis already names.
                User::retrieve($userId)->createOwner($companyId, $command)->persist();

                $this->createCompany->execute($this->buildCreateCompanyCommand($command, $userId), $clientIp, $companyId);

                return [$userId, $companyId];
            });
        } catch (UniqueConstraintViolationException $violation) {
            // A concurrent signup slipped past the pre-tx guard on email; the DB UNIQUE
            // index is the backstop. Surface the same conflict — or re-raise an unmapped
            // violation untouched, so a genuine fault is never masked as a 409.
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
            'owner' => UserReadModel::query()->findOrFail($ownerUserId),
            'emergencyContactName' => $command->emergencyContactName,
            'emergencyContactEmail' => $command->emergencyContactEmail,
            'emergencyContactPhone' => $command->emergencyContactPhone,
            'emergencyContactRelationship' => $command->emergencyContactRelationship,
        ]);
    }
}
