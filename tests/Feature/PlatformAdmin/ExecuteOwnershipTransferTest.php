<?php

namespace Tests\Feature\PlatformAdmin;

use stdClass;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * SM-1-05 T4 — a platform admin executes a verified transfer, moving ownership
 * atomically (UC-1-093, slice #11 Task 8). One behavior, one seam, one actor, so
 * the illegal-transition refusals, the live re-validation guards and the happy
 * swap all live here, ordered Sad → Edge → Happy (§2.14 Rule A).
 *
 * The heaviest increment of the slice: five write surfaces commit together or
 * none do (SD-1-02 §2.2), across four $weight-ordered projectors.
 *
 * Two deliberate asymmetries with the siblings, both s159 rulings:
 * - Unlike reject (H4) and withdraw, a legal hold DOES refuse here (P5): §S10b
 *   freezes ownership CHANGES, and this is the one transition that changes one.
 * - Unlike every other transition, execute re-validates live state before the
 *   aggregate call (P3) — the owner and the target could both have moved since
 *   T1, and the aggregate never reads the database.
 */
class ExecuteOwnershipTransferTest extends OwnershipTransferAdminTestCase
{
    /**
     * The refusal left every T4 write surface untouched. Four aspects, because
     * a 409 on its own says only that the HTTP layer answered — it says nothing
     * about whether the aggregate recorded, the owner moved, or the target was
     * promoted on the way to answering.
     *
     * Derives rather than snapshots: the pre-transfer owner IS
     * `current_owner_user_id` on the transfer row, so no BEFORE capture is
     * needed and the helper stays at two parameters (§2.2 Rule 5-extended).
     *
     * Recorded plainly: none of these aspects means anything until something can
     * violate them, and `assertDatabaseCount(..., 0)` against a table nothing
     * writes is the emptiest kind of green. The happy-path row is the positive
     * control for all five — it drives the same five surfaces the other way, and
     * fails if any of them stays put.
     */
    private function assertNoWriteSurvived(
        OwnershipTransferRequest $transfer,
        OwnershipTransferStatus $expected,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame($expected, $current->status);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->count());
        // Stated as "the T4 write did not happen" rather than "the owner is
        // still the recorded one". The two agree everywhere except the row whose
        // premise is that a PRIOR transfer already moved the owner — there the
        // stronger form is false by construction, and asserting it would have
        // made the helper unusable for exactly the case it most needs to cover.
        $this->assertNotSame(
            $transfer->target_user_id,
            Company::query()->whereKey($transfer->company_id)->value('owner_id'),
        );
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $transfer->company_id,
            'user_id' => $transfer->target_user_id,
            'role_id' => $this->seededRole('owner')->getKey(),
        ]);
        $this->assertDatabaseCount('ownership_transfer_pivot_history', 0);
    }

    /**
     * A valid execution body, sent by every row that isolates something OTHER
     * than validation — so an existence or gate assertion can never be satisfied
     * by a validation failure standing in for it (the RejectOwnershipTransferTest
     * idiom, which states the reasoning at its own not-found row).
     *
     * @return array<string, string>
     */
    private static function executionBody(): array
    {
        return ['reason' => 'Founder departure confirmed by signed board resolution.'];
    }

    #[Test]
    public function when_the_execution_is_unauthenticated_then_it_is_refused(): void
    {
        // Given a transfer awaiting execution
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState(
            $company,
            User::factory()->create(),
            OwnershipTransferStatus::Requested,
        );

        // When a guest attempts to execute it
        $response = $this->executeTransfer(null, $transfer->getKey());

        // Then the request is refused and the transfer is untouched
        $this->assertUnauthenticated($response);
        $this->assertSame(
            OwnershipTransferStatus::Requested,
            $this->currentTransfer($transfer)->status,
        );
    }

    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_the_caller_is_not_a_platform_admin_then_the_transfer_is_not_found(string $roleCode): void
    {
        // Given a verified transfer and a tenant member of its own company
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->anAllowlistedAdmin());
        $caller = $this->aCompanyMember($company, $roleCode);

        // When that tenant role attempts to execute it
        $response = $this->executeTransfer($caller, $transfer->getKey(), self::executionBody());

        // Then the admin surface never acknowledges the transfer exists
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(
            OwnershipTransferStatus::Verified,
            $this->currentTransfer($transfer)->status,
        );
    }

    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an allowlisted admin and an id no transfer carries
        $admin = $this->anAllowlistedAdmin();

        // When they attempt to execute it
        $response = $this->executeTransfer($admin, (string) Str::uuid7(), self::executionBody());

        // Then the refusal is byte-identical to the one a denied caller gets
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertDatabaseCount('ownership_transfer_requests', 0);
    }

    /**
     * Every shape the execution record must refuse. The reason is mandatory AT
     * WRITE TIME (UC-1-093 error flow 4a): T4 is the one transition that moves
     * legal authority, so no execution may reach stored_events unexplained.
     *
     * Whitespace-only needs no rule of its own: TrimStrings and
     * ConvertEmptyStringsToNull both sit ahead of validation in the HTTP
     * pipeline, so `'   '` arrives as null and fails `required` — and Laravel's
     * own `required` trims besides. Both rows stay because they are distinct
     * CLIENT shapes; what they jointly pin is that neither reaches the event
     * store, whichever layer happens to stop them.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidExecutionInputs(): array
    {
        return [
            'reason missing' => [[], 'reason'],
            'reason empty' => [['reason' => ''], 'reason'],
            'reason whitespace only' => [['reason' => '   '], 'reason'],
            'reason over the 1000-char bound' => [['reason' => str_repeat('a', 1001)], 'reason'],
        ];
    }

    #[Test]
    #[DataProvider('provideInvalidExecutionInputs')]
    public function when_the_execution_input_is_invalid_then_it_is_refused(array $body, string $invalidField): void
    {
        // Given a verified transfer awaiting execution
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When an allowlisted admin submits a malformed execution
        $response = $this->executeTransfer($admin, $transfer->getKey(), $body);

        // Then it is refused and the transfer never leaves the verified window
        $this->assertValidationRejected($response, $invalidField);
        $this->assertSame(
            OwnershipTransferStatus::Verified,
            $this->currentTransfer($transfer)->status,
        );
    }

    #[Test]
    public function when_the_transfer_is_not_yet_verified_then_it_is_not_executed(): void
    {
        // Given a transfer whose identity check has not happened yet
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aCompanyMember($company));

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Requested);
    }

    #[Test]
    public function when_the_transfer_is_already_rejected_then_it_is_not_executed(): void
    {
        // Given a transfer a platform admin has already rejected
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aRejectedTransferStream($company, $owner, $admin);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Rejected);
    }

    #[Test]
    public function when_the_transfer_is_already_withdrawn_then_it_is_not_executed(): void
    {
        // Given a transfer its own requester has withdrawn
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aCancelledTransferStream($company, $owner);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Cancelled);
    }

    #[Test]
    public function when_the_transfer_has_already_expired_then_it_is_not_executed(): void
    {
        // Given a transfer the daily scan has already expired
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->anExpiredTransferStream($company, $owner);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Expired);
    }

    /**
     * A prior transfer moved ownership elsewhere while this one sat verified.
     * A named fixture helper carrying the column write is the sanctioned shape —
     * §2.13 bans inline overrides in test BODIES, not in fixtures (the
     * `aTransferDueAt` / `placeCompanyUnderLegalHold` precedents).
     */
    private function theOwnerChangesTo(Company $company, User $newOwner): void
    {
        $company->forceFill(['owner_id' => $newOwner->getKey()])->save();
    }

    #[Test]
    public function when_the_owner_has_changed_since_the_request_then_it_is_not_executed(): void
    {
        // Given a verified transfer whose recorded owner is no longer the live one
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->theOwnerChangesTo($company, $this->aCompanyMember($company, 'admin'));

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the live re-validation refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'The company owner has changed since this transfer was requested.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    /**
     * The target was removed from the company between T1 and T4 — their pivot is
     * soft-deleted, which is how this codebase ends a membership (ERD-1-01 D5b:
     * rows survive so a re-invite can undelete them).
     */
    private function theTargetLeavesTheCompany(OwnershipTransferRequest $transfer): void
    {
        CompanyUser::query()
            ->where('company_id', $transfer->company_id)
            ->where('user_id', $transfer->target_user_id)
            ->delete();
    }

    /**
     * The 422 mirrors T1's own refusal of a non-member target (ruling F4,
     * ValidationException → VALIDATION_FAILED), so the two ends of the transfer
     * answer the same question the same way.
     *
     * Worth naming: the error is keyed to `targetUserId`, which at T4 is NOT a
     * request field — execute accepts no target (P3). The key names the fact the
     * TRANSFER recorded, not something the caller can correct by resending. That
     * is a deliberate consistency with T1 rather than an oversight, and it is
     * why the message speaks about the selected user rather than the input.
     */
    #[Test]
    public function when_the_target_is_no_longer_a_member_then_it_is_not_executed(): void
    {
        // Given a verified transfer whose target has since left the company
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->theTargetLeavesTheCompany($transfer);

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the live membership re-check refuses it and nothing was written
        $this->assertValidationRejected($response, 'targetUserId');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    #[Test]
    public function when_the_transfer_is_already_executed_then_it_is_not_executed_again(): void
    {
        // Given a transfer whose ownership swap has already happened
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->anExecutedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);

        // When an allowlisted admin attempts to execute it a second time
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and the FIRST execution stands intact
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->count());
        $this->assertOwnershipMoved($transfer, $owner, $target);
        $this->assertPivotHistoryRecorded($transfer, $owner, $target);
    }

    /**
     * Ownership actually moved, across all three read-model surfaces the swap
     * touches. Four aspects, because any one alone is satisfiable by a partial
     * write — and a partial write is precisely the state SD-1-02 §2.2 exists to
     * make impossible.
     */
    private function assertOwnershipMoved(
        OwnershipTransferRequest $transfer,
        User $outgoingOwner,
        User $incomingOwner,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame(OwnershipTransferStatus::Executed, $current->status);
        // The SLA timer is cleared, not merely ignored: a terminal row left with
        // a live deadline is exactly what the expiry scan would pick up again.
        $this->assertNull($current->next_state_transition_at);
        $this->assertSame(
            $incomingOwner->getKey(),
            Company::query()->whereKey($transfer->company_id)->value('owner_id'),
        );
        $this->assertSame(
            ['admin', 'owner'],
            [
                $this->pivotRoleCodeOf($transfer->company_id, $outgoingOwner),
                $this->pivotRoleCodeOf($transfer->company_id, $incomingOwner),
            ],
        );
    }

    /**
     * The role transition is RECORDED, not merely inferable (ruling P2). The
     * old_role_id assertions are the only ones in this class that can catch a
     * projector reading the prior role from company_user instead of the event
     * payload — by the time it runs, MembershipProjector has already overwritten
     * that row, so such a projector writes old_role_id == new_role_id.
     */
    private function assertPivotHistoryRecorded(
        OwnershipTransferRequest $transfer,
        User $outgoingOwner,
        User $incomingOwner,
    ): void {
        $rows = DB::table('ownership_transfer_pivot_history')
            ->where('request_id', $transfer->getKey())
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                [$this->seededRole('owner')->getKey(), $this->seededRole('admin')->getKey()],
                [$this->seededRole('employee')->getKey(), $this->seededRole('owner')->getKey()],
            ],
            [
                $this->roleTransitionRecordedFor($rows, $outgoingOwner),
                $this->roleTransitionRecordedFor($rows, $incomingOwner),
            ],
        );
        // One transfer, one instant: both parties moved in the same transaction,
        // so a differing pair would mean they did not.
        $this->assertCount(1, $rows->pluck('executed_at')->unique());
    }

    private function pivotRoleCodeOf(string $companyId, User $user): ?string
    {
        return DB::table('company_user')
            ->join('roles', 'roles.id', '=', 'company_user.role_id')
            ->where('company_user.company_id', $companyId)
            ->where('company_user.user_id', $user->getKey())
            ->whereNull('company_user.deleted_at')
            ->value('roles.code');
    }

    /**
     * The recorded old → new role pair for $user, or a null pair when no row was
     * written for them at all — which is a failure the CALLER's assertion
     * reports in full, rather than an error thrown from in here.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array{0: ?string, 1: ?string}
     */
    private function roleTransitionRecordedFor(Collection $rows, User $user): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $rows->firstWhere('user_id', $user->getKey());

        return [$row['old_role_id'] ?? null, $row['new_role_id'] ?? null];
    }

    #[Test]
    public function when_a_platform_admin_executes_a_verified_transfer_then_ownership_moves_atomically(): void
    {
        // Given a verified transfer whose owner and target are both real members
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then every write surface moved together
        $response->assertOk();
        $response->assertJsonPath('data.status', 'executed');
        $this->assertOwnershipMoved($transfer, $owner, $target);
        $this->assertPivotHistoryRecorded($transfer, $owner, $target);
        // Amendment R: the read-model oracle proves the VALUE, never the
        // mechanism. Without this, a direct write to the projected columns
        // passes every assertion above and is silently reverted on replay.
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->count());
    }
}
