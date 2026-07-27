<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\OwnershipTransfers\Enums\TransitionSource;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;

/**
 * SM-1-05 T6 + T7 (UC-1-114 pre-verification, UC-1-115 post-verification) — the
 * original requester withdraws their own transfer, from `Requested` or from
 * `Verified`. One endpoint, one Command, one aggregate method; the aggregate
 * picks the window from its own state, exactly as `reject()` does for T3/T5.
 *
 * ONE class for both windows (§2.14): same seam, same actor, same endpoint — the
 * T6-vs-T7 distinction is scenario-level, not a split trigger. Same reasoning
 * that kept both reject windows together at s151; LeaveDates likewise splits its
 * cancel tests by ACTOR (Subordinate / Admin / Approver), never by transition.
 */
class WithdrawOwnershipTransferTest extends OwnershipTransfersTestCase
{
    private const string WITHDRAWAL_REASON = 'Decided to keep ownership after all.';

    #[Test]
    public function when_the_withdrawal_is_unauthenticated_then_it_is_refused(): void
    {
        // Given an open transfer belonging to a company
        [$company] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When it is withdrawn with no session at all
        $response = $this->withdrawTransferAsGuest((string) $transfer->getKey());

        // Then the request never reaches the use case
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    /**
     * The UC's whole "Denied Roles" line in one row. The designated TARGET user
     * is refused here too: they hold an ordinary member role, and only `owner`
     * carries `ownership-transfer.withdraw` (matrix Module 8 after the J1
     * collapse). Withdrawal is the requester's authority, never the target's.
     */
    #[Test]
    #[DataProvider('provideRolesWithoutWithdrawPermission')]
    public function when_the_caller_lacks_the_withdraw_permission_then_the_transfer_is_not_withdrawn(string $roleCode): void
    {
        // Given an open transfer and a member holding some other role
        [$company] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);
        $caller = $this->aMemberWithRole($company, $roleCode);

        // When that member tries to withdraw it
        $response = $this->withdrawTransfer([$company, $caller], (string) $transfer->getKey());

        // Then the permission gate refuses and the transfer is untouched
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an owner with no transfer under the requested id
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they withdraw an id that names nothing
        $response = $this->withdrawTransfer([$company, $owner], (string) Str::uuid7());

        // Then the absence is reported as a plain not-found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    /**
     * An owner may hold `ownership-transfer.withdraw` and still not reach
     * another tenant's transfer: the id is existence-hidden, so a foreign
     * transfer and an absent one are byte-identical on the wire.
     */
    #[Test]
    public function when_the_transfer_belongs_to_another_company_then_it_is_not_found(): void
    {
        // Given an owner, and an open transfer at a different company
        [$company, $owner] = $this->aCompanyWithOwner();
        $foreign = $this->aBystanderTransferAtAnotherCompany();

        // When the owner withdraws the foreign transfer through their own context
        $response = $this->withdrawTransfer([$company, $owner], (string) $foreign->getKey());

        // Then it is refused as absent, and the foreign row is untouched
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertTransferStillOpen((string) $foreign->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * Holding the permission is not the same as being the requester (ruling J2).
     * The guard sits in the interactor and reads the PROJECTION before
     * `retrieve()`, so a factory row exercises it honestly — this is the one row
     * in the class that needs no real stream, because no stream is ever replayed
     * on this path.
     *
     * The arrangement is a state production cannot currently emit: an open
     * transfer's `current_owner_user_id` is always the company's owner, since
     * only the owner may request one and `users.change-role` refuses to mint a
     * second owner. The guard is defence for the day that stops being true —
     * self-service transfer (OQ-1-D18) is the named candidate — and it is a
     * WATCHED RED here, not a born-green pin.
     */
    #[Test]
    public function when_the_caller_is_not_the_original_requester_then_the_withdrawal_is_refused(): void
    {
        // Given an open transfer this owner did not raise
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferRaisedBySomeoneElse($company);

        // When the owner tries to withdraw it
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey());

        // Then identity, not role, is what refuses them
        $this->assertRefusedWith($response, ApiErrorCode::TransferRequesterMismatch);
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * The only shape a withdrawal can get wrong. `reason` is optional and
     * `cancelledBy` is auth-resolved, so there is no required-field matrix here —
     * a DataProvider would pad the row count without adding evidence, which is
     * precisely what s152 found when it re-checked reject's seven-row provider
     * and could only give two of them teeth.
     */
    #[Test]
    public function when_the_withdrawal_reason_exceeds_the_bound_then_it_is_refused(): void
    {
        // Given an open transfer this owner raised
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'employee');
        $transfer = $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When they withdraw it with prose one character past the contract
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => str_repeat('a', 1001),
        ]);

        // Then the boundary refuses it and the transfer stands
        $this->assertValidationRejected($response, 'reason');
        $this->assertTransferStillOpen((string) $transfer->getKey(), OwnershipTransferStatus::Requested);
    }

    /**
     * An open transfer at $company whose recorded requester is a third user.
     * A named fixture helper carrying the column override is the sanctioned
     * shape — §2.13 bans inline overrides in test BODIES, not in fixtures
     * (the `aTransferDueAt` precedent).
     */
    private function aTransferRaisedBySomeoneElse(Company $company): OwnershipTransferRequest
    {
        return OwnershipTransferRequest::factory()->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => User::factory()->create()->getKey(),
            'target_user_id' => User::factory()->create()->getKey(),
        ]);
    }

    /**
     * The degenerate case for an aggregate command: the transition fired twice.
     * Arranged through a REAL prior T6, so the guard refuses because the state
     * genuinely IS terminal — a factory row would replay an empty stream and
     * refuse for an unrelated reason (s149 C1).
     */
    #[Test]
    public function when_the_transfer_is_already_withdrawn_then_it_is_not_withdrawn_again(): void
    {
        // Given a transfer its requester has already withdrawn
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aCancelledTransferStream($company, $owner);
        $original = $this->currentTransfer($transfer);

        // When they withdraw it a second time
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the state guard refuses and the first withdrawal is untouched
        $this->assertStateGuardRefused($response, 'Only an open transfer can be withdrawn.');
        $this->assertOriginalWithdrawalIntact($original);
    }

    /**
     * The first withdrawal's record survives the second attempt byte for byte —
     * a refusal that quietly overwrote the original reason or actor would still
     * return 409 and pass a status-only assertion.
     */
    private function assertOriginalWithdrawalIntact(OwnershipTransferRequest $original): void
    {
        $current = $this->currentTransfer($original);
        $this->assertSame($original->cancellation_reason, $current->cancellation_reason);
        $this->assertSame($original->cancelled_by, $current->cancelled_by);
        $this->assertEquals($original->cancelled_at, $current->cancelled_at);
        $this->assertDatabaseCount('stored_events', 2);
    }

    /**
     * SM-1-05 T6 (UC-1-114) — the pre-verification window, end to end.
     *
     * Arranged through a REAL T1 stream: the aggregate must replay genuine
     * history for the `Requested` arm of `withdraw()`'s match to be the thing
     * under test. A factory row would replay an empty stream and the guard would
     * refuse for the wrong reason (s149 C1).
     */
    #[Test]
    public function when_an_owner_withdraws_a_requested_transfer_then_it_is_cancelled_before_verification(): void
    {
        // Given a transfer whose stream carries the real T1 genesis
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, User::factory()->create());

        // When the requester withdraws it before any verification has happened
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the pre-verification window is recorded end to end
        $this->assertWithdrawalRecorded($response, $owner, TransitionSource::PreVerification);
    }

    /**
     * SM-1-05 T7 (UC-1-115) — the post-verification window.
     *
     * The asymmetry with T6 is the point: admin verification work HAS happened,
     * so the prior T2 record must survive the withdrawal intact, and the timer
     * being cleared here is a DIFFERENT value (`verified_at + 14d`, not
     * `requested_at + 30d`). At s153 the reject twin turned out to have asserted
     * only one of its two windows' clears, so both are pinned here.
     */
    #[Test]
    public function when_an_owner_withdraws_a_verified_transfer_then_it_is_cancelled_after_verification(): void
    {
        // Given a transfer a platform admin has already identity-verified
        [$company, $owner] = $this->aCompanyWithOwner();
        $verifier = User::factory()->create();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $verifier);

        // When the requester withdraws it before it can be executed
        $response = $this->withdrawTransfer([$company, $owner], (string) $transfer->getKey(), [
            'reason' => self::WITHDRAWAL_REASON,
        ]);

        // Then the post-verification window is recorded, verification intact
        $this->assertWithdrawalRecorded($response, $owner, TransitionSource::PostVerification);
        $this->assertVerificationRecordPreserved($verifier);
    }

    /**
     * The T2 evidence outlives the withdrawal. UC-1-115's legal-defensibility
     * chain reads "verifier did their job; the requester later abandoned it", so
     * the verifier's attribution and notes must still be readable on the
     * cancelled row — and the T2 event itself is still in the stream.
     */
    private function assertVerificationRecordPreserved(User $verifier): void
    {
        $transfer = OwnershipTransferRequest::query()->sole();
        $this->assertSame($verifier->getKey(), $transfer->verified_by);
        $this->assertNotNull($transfer->verified_at);
        $this->assertDatabaseHas('stored_events', [
            'event_class' => OwnershipTransferIdentityVerified::class,
        ]);
    }

    /**
     * The whole write: the response, the stored event, the projected row and the
     * SLA timer that a terminal state must not keep.
     *
     * The stored-event assertion is amendment R: a direct read-model write would
     * leave identical columns behind and satisfy every response-level check, so
     * only the event lookup can tell the event-sourced write apart from one that
     * a projection replay would silently revert.
     */
    private function assertWithdrawalRecorded(TestResponse $response, User $requester, TransitionSource $expected): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.status', OwnershipTransferStatus::Cancelled->value);

        $this->assertDatabaseHas('stored_events', [
            'event_class' => OwnershipTransferCancelled::class,
        ]);

        $transfer = OwnershipTransferRequest::query()->sole();
        $this->assertSame(OwnershipTransferStatus::Cancelled, $transfer->status);
        $this->assertSame($expected->value, $transfer->cancellation_source);
        $this->assertSame(self::WITHDRAWAL_REASON, $transfer->cancellation_reason);
        $this->assertSame($requester->getKey(), $transfer->cancelled_by);
        $this->assertNotNull($transfer->cancelled_at);
        $this->assertNull($transfer->next_state_transition_at);
    }

    /**
     * Every seeded company role except `owner`.
     *
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutWithdrawPermission(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    /**
     * The transfer is still in $expected, and no terminal columns were written —
     * a refusal that leaves the row alone is the assertion, not merely the
     * status code.
     */
    private function assertTransferStillOpen(string $requestId, OwnershipTransferStatus $expected): void
    {
        $this->assertDatabaseHas('ownership_transfer_requests', [
            'id' => $requestId,
            'status' => $expected->value,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_source' => null,
        ]);
    }
}
