<?php

namespace Tests\Feature\Companies\Memberships;

use PDOException;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Feature\Companies\CreatesTrialCompanies;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\Aggregates\Company as CompanyAggregate;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * Owner hands company ownership to an existing teammate in one in-app action
 * (UC-1-028, slice-11R spec §4/§6): the column and both pivot roles move
 * together under the interactor's row locks, or not at all.
 */
class TransferCompanyOwnershipTest extends MembershipsTestCase
{
    use CreatesTrialCompanies;
    use InteractsWithQueuedReactors;

    #[Test]
    public function when_a_non_owner_member_attempts_the_transfer_then_it_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$admin] = $this->aMembership($company, 'admin');
        [$target] = $this->aMembership($company, 'employee');

        // When
        $response = $this->transferOwnership($admin, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_target_is_not_a_member_of_the_company_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        $outsider = User::factory()->create();

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $outsider->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::TargetNotMember);
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_target_holds_only_a_pending_invitation_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        $invitee = User::factory()->create();
        $this->aPendingInvitation($company, $owner, $invitee->email);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $invitee->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::TargetNotMember);
        $this->assertNothingTransferred($company, $owner);
        $this->assertInvitationNotConsumed($company, $invitee->email);
    }

    #[Test]
    public function when_the_owner_targets_themselves_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $owner->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::SelfTransferBlocked);
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_company_owner_changed_before_the_locked_revalidation_then_the_transfer_is_refused_as_stale(): void
    {
        // Given — the split-brain a concurrent transfer commit leaves: the column
        // no longer names the actor while their pivot still reads owner, so the
        // permission gate passes and the request reaches the in-tx guard (P9).
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        $usurper = User::factory()->create();
        $company->update(['owner_id' => $usurper->getKey()]);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::StateGuardViolation);
        $this->assertNothingTransferred($company, $usurper);
    }

    #[Test]
    public function when_the_projector_fails_mid_transfer_then_nothing_is_persisted(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        $this->induceProjectorFailureFor(CompanyOwnershipTransferred::class);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::InternalError);
        $this->assertNothingTransferred($company, $owner);
        $this->assertSame($this->seededRole('employee')->getKey(), $this->pivotOf($company, $target)->role_id);
    }

    #[Test]
    public function when_the_transfer_hits_lock_contention_then_it_is_refused_as_a_conflict(): void
    {
        // Given — a deadlock raised inside the locked transaction (a REAL
        // two-connection deadlock stays out per P9; the shape is what MySQL
        // 1213 surfaces as through the query layer)
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        $deadlock = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        $this->induceProjectorFailureFor(
            CompanyOwnershipTransferred::class,
            new QueryException('mysql', 'update `company_user` set `role_id` = ?', [], $deadlock),
        );

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then — the render classification only: a REAL 1213 is rolled back by
        // MySQL server-side before Laravel ever sees it, which a synthetic
        // cannot model; the atomicity net is the projector-failure row.
        $this->assertRefusedWith($response, ApiErrorCode::StateGuardViolation);
    }

    #[Test]
    public function when_the_owner_transfers_ownership_to_an_active_teammate_then_ownership_moves_atomically(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        Queue::fake();

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then
        $response->assertOk();
        $this->assertSame([
            'company_id' => $company->getKey(),
            'owner' => ['id' => $target->getKey(), 'name' => $target->name, 'email' => $target->email],
        ], $response->json('data'));
        $this->assertOwnershipMoved($company, $owner, $target);
        $this->assertTransferRecorded($company, $owner, $target);
        $this->runQueuedHandlersFor(CompanyOwnershipTransferred::class);
        $this->assertOnlyNewOwnerNotified($company, $target, $owner);
    }

    /**
     * A genesis company owned by $owner: real Company stream, owner pivot and
     * companies.owner_id both written by the production genesis projector.
     */
    protected function aTrialCompanyOwnedBy(User $owner, string $name = 'Contoso'): Company
    {
        $companyId = (string) Str::uuid7();

        CompanyAggregate::retrieve($companyId)
            ->create($this->buildCreateCompanyCommand($companyId, $name, $owner->getKey()))
            ->persist();

        return Company::query()->findOrFail($companyId);
    }

    /**
     * Transfer $company's ownership as $actor. Param route — the URL is
     * authoritative (R8), no X-Company-Context header.
     *
     * @param  array<string, mixed>  $body
     */
    protected function transferOwnership(User $actor, Company $company, array $body): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->patchJson("/api/v1/companies/{$company->getKey()}/owner", $body);
    }

    /**
     * A refused transfer changed nothing: companies.owner_id still names
     * $expectedOwner and this company's aggregate recorded no transfer event.
     */
    protected function assertNothingTransferred(Company $company, User $expectedOwner): void
    {
        $this->assertSame($expectedOwner->getKey(), $company->fresh()?->owner_id);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where('aggregate_uuid', $company->getKey())
            ->count());
    }

    /**
     * The membership pivot for ($company, $user), soft-delete state included.
     */
    protected function pivotOf(Company $company, User $user): CompanyUser
    {
        return CompanyUser::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->sole();
    }

    /**
     * The dual representation moved together: companies.owner_id names $to,
     * $to's pivot role is owner, and $from remains a LIVE admin — demoted,
     * never roleless, never removed (UC-1-028 postconditions).
     */
    protected function assertOwnershipMoved(Company $company, User $from, User $to): void
    {
        $this->assertSame($to->getKey(), $company->fresh()?->owner_id);
        $this->assertSame($this->seededRole('owner')->getKey(), $this->pivotOf($company, $to)->role_id);

        $outgoing = $this->pivotOf($company, $from);
        $this->assertSame($this->seededRole('admin')->getKey(), $outgoing->role_id);
        $this->assertNull($outgoing->deleted_at);
    }

    /**
     * Exactly ONE CompanyOwnershipTransferred was recorded on this company's
     * stream with the payload verbatim — source self_service, no case (amendment
     * R: a direct read-model write must fail this assertion).
     */
    protected function assertTransferRecorded(Company $company, User $from, User $to): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_properties->previousOwnerUserId', $from->getKey())
            ->where('event_properties->newOwnerUserId', $to->getKey())
            ->where('event_properties->transferredBy', $from->getKey())
            ->where('event_properties->source', 'self_service')
            ->whereNull('event_properties->caseId')
            ->count());
    }

    /**
     * Exactly the new owner learned about the transfer (D4 absence-as-behavior,
     * per recipient): one ownership_received in-app row for $to carrying the
     * company name, the queued mail leg addressed to $to, and NOTHING for $from
     * — they performed the action.
     */
    protected function assertOnlyNewOwnerNotified(Company $company, User $to, User $from): void
    {
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $to->getKey(),
            'data->notification_type' => 'ownership_received',
            'data->company_name' => $company->name,
        ]);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $from->getKey())->count());
        Mail::assertQueued(SystemNotificationMail::class, fn (SystemNotificationMail $mail): bool => $mail->hasTo($to->email));
    }
}
