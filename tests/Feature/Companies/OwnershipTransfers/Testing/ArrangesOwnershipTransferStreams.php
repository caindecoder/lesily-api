<?php

namespace Tests\Feature\Companies\OwnershipTransfers\Testing;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\RejectOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\WithdrawOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\VerifyOwnershipTransferIdentityCommand;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * Arranges ownership-transfer fixtures whose STREAM is real, for every test
 * class that drives a transition — the tenant withdraw class and the admin
 * verify/reject/queue classes alike.
 *
 * A trait rather than a method on either base, because the consumers sit in
 * different actor families (`Tests\Feature\Companies\OwnershipTransfers` and
 * `Tests\Feature\PlatformAdmin`) and E09 keeps one base per actor+subject pair.
 * LeaveDates does exactly this: `app/Leave/Cancellation/Testing/DeclineLeave.php`
 * is a trait consumed by seven classes across four modules (Rule #15) — Leave
 * Foundation, Retraction and Viewing, plus CompanyProfile Configurations.
 *
 * NONE of these is interchangeable with `aTransferInState()`: the factory writes
 * the PROJECTION only, so an aggregate retrieved against a factory row replays an
 * EMPTY stream and every state guard fires for the wrong reason — the test would
 * pass while proving nothing about the transition (s149 C1).
 */
trait ArrangesOwnershipTransferStreams
{
    /**
     * Supplied by both consuming bases (CompaniesTestCase and
     * PlatformAdminTestCase each define it). Declared here so the dependency is
     * explicit rather than discovered at runtime.
     */
    abstract protected function seededRole(string $code): Role;

    /**
     * A target user who is REALLY a member of $company.
     *
     * The derived helpers below used to hand `aRequestedTransferStream` a bare
     * `User::factory()->create()`, producing a transfer whose target had no
     * `company_user` row — a state production cannot emit, because
     * `RequestOwnershipTransferInteractor` refuses a non-member target with a
     * 422. That is not academic: it made this module's highest-value assertion
     * silently vacuous, because a pivot mutation against such a fixture matches
     * zero rows and every assertion stays green (the E-b/K1 catch, and the third
     * instance of "the mutation must be reachable from the arrangement").
     *
     * Fixed at the helper rather than at the one test that noticed, so the next
     * pivot-touching assertion in ANY consuming class starts out honest.
     */
    private function aTargetMemberOf(Company $company): User
    {
        $target = User::factory()->create();

        $company->users()->attach($target, ['role_id' => $this->seededRole('employee')->getKey()]);

        return $target;
    }

    /**
     * A transfer whose stream genuinely carries the T1 genesis (and whose
     * projection the T1 projector therefore wrote), so a later transition
     * replays real history.
     */
    protected function aRequestedTransferStream(Company $company, User $owner, User $target): OwnershipTransferRequest
    {
        $requestId = (string) Str::uuid7();

        OwnershipTransferRequestAggregate::retrieve($requestId)
            ->request(RequestOwnershipTransferCommand::validateAndCreate([
                'companyId' => $company->getKey(),
                'currentOwnerUserId' => $owner->getKey(),
                'targetUserId' => $target->getKey(),
                'reason' => 'Founder is stepping down and handing the company to the operations lead.',
                'requestChannel' => 'app',
            ]))
            ->persist();

        return OwnershipTransferRequest::query()->findOrFail($requestId);
    }

    /**
     * A transfer whose stream carries a REAL T1 → T2 history: requested, then
     * identity-verified by $verifier through the aggregate. The only way to reach
     * the `Verified` window genuinely — the T5 rejection and the T7 withdrawal
     * both replay this history rather than trusting a projected row.
     *
     * The target user is created inside rather than taken as a fourth parameter,
     * because callers exercising the verified window care who verified it, not
     * who it was for.
     *
     * The original reason given was "§2.2 caps helpers at three parameters",
     * which misreads the rule — that cap governs grouped ASSERTION helpers, not
     * arrangement ones. It is worth correcting rather than deleting, because
     * that misreading is what pushed the target inside the helper, and creating
     * it inside is what let it be created as a non-member.
     */
    protected function aVerifiedTransferStream(Company $company, User $owner, User $verifier): OwnershipTransferRequest
    {
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aTargetMemberOf($company));

        OwnershipTransferRequestAggregate::retrieve($transfer->getKey())
            ->verifyIdentity(VerifyOwnershipTransferIdentityCommand::validateAndCreate([
                'verificationNotes' => 'National ID matched the applicant; board resolution on file.',
                'verificationArtefacts' => [[
                    'type' => 'id_document',
                    'reference' => 'SUP-4821/nic-scan',
                    'capturedAt' => '2026-07-24T09:15:00+00:00',
                ]],
                'verifiedBy' => $verifier->getKey(),
            ]))
            ->persist();

        return $transfer->refresh();
    }

    /**
     * A transfer whose stream carries a REAL T1 → T3 history: requested, then
     * rejected by $admin through the aggregate (LD's
     * `DeclineLeave::getRejectedLeave` arranges its already-declined fixture the
     * same way).
     */
    protected function aRejectedTransferStream(Company $company, User $owner, User $admin): OwnershipTransferRequest
    {
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aTargetMemberOf($company));

        OwnershipTransferRequestAggregate::retrieve($transfer->getKey())
            ->reject(RejectOwnershipTransferCommand::validateAndCreate([
                'reason' => 'Identity documents could not be matched to the applicant.',
                'rejectionReasonCode' => 'identity_failed',
                'rejectedBy' => $admin->getKey(),
            ]))
            ->persist();

        return $transfer->refresh();
    }

    /**
     * A transfer whose stream carries a REAL T1 → T6 history: requested, then
     * withdrawn by its own requester through the aggregate.
     *
     * This is what makes `Cancelled` reachable at all, and it is why the
     * verify-from-Cancelled and reject-from-Cancelled rows were deferred out of
     * s149 and s151 to this task: until `withdraw()` existed, the only way to
     * arrange the state was a factory row, whose empty stream makes every guard
     * refuse for the wrong reason and the test's name a lie (s149 C1/C2).
     */
    protected function aCancelledTransferStream(Company $company, User $owner): OwnershipTransferRequest
    {
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aTargetMemberOf($company));

        OwnershipTransferRequestAggregate::retrieve($transfer->getKey())
            ->withdraw(WithdrawOwnershipTransferCommand::validateAndCreate([
                'reason' => 'Withdrawn while the request was still awaiting verification.',
                'cancelledBy' => $owner->getKey(),
            ]))
            ->persist();

        return $transfer->refresh();
    }

    /**
     * $transfer as it stands in the database RIGHT NOW, as a distinct instance
     * every call. Deliberately not `$transfer->refresh()`, which mutates the
     * caller's instance in place: a test that captures a BEFORE snapshot and
     * compares it to the AFTER state would be handed the same object twice,
     * quietly turning that comparison into a tautology. findOrFail also types
     * non-null, which fresh() does not.
     */
    protected function currentTransfer(OwnershipTransferRequest $transfer): OwnershipTransferRequest
    {
        return OwnershipTransferRequest::query()->whereKey($transfer->getKey())->firstOrFail();
    }

    /**
     * Put $company under a regulatory hold. A named fixture helper carrying the
     * column write is the sanctioned shape — §2.13 bans inline overrides in test
     * BODIES, not in fixtures.
     */
    protected function placeCompanyUnderLegalHold(Company $company): void
    {
        $company->forceFill(['legal_hold' => true])->save();
    }
}
