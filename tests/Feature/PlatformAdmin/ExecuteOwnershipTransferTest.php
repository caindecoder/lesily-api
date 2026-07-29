<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
     * The fifth aspect — zero `ownership_transfer_pivot_history` rows — lands
     * with the migration that creates that table, and these rows are re-run
     * against it then.
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
        $this->assertSame(
            $transfer->current_owner_user_id,
            Company::query()->whereKey($transfer->company_id)->value('owner_id'),
        );
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $transfer->company_id,
            'user_id' => $transfer->target_user_id,
            'role_id' => $this->seededRole('owner')->getKey(),
        ]);
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
            OwnershipTransferRequest::query()->findOrFail($transfer->getKey())->status,
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
}
