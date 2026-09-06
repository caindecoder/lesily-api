<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Feature\Companies\CreatesTrialCompanies;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Companies\Aggregates\Company as CompanyAggregate;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Foundation\Notifications\Notifications\SystemNotification;
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
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_target_reference_is_missing_then_the_transfer_is_refused_as_invalid(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);

        // When
        $response = $this->transferOwnership($owner, $company, []);

        // Then
        $this->assertValidationRejected($response, 'newOwnerUserId', 'The new owner user id field is required.');
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_target_reference_is_malformed_then_the_transfer_is_refused_as_invalid(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => 'not-a-uuid']);

        // Then — the HTTP command boundary enforces its rules() (amendment AQ:
        // a ::from swap at the controller leaves them dead; this row REDs on it)
        $this->assertValidationRejected($response, 'newOwnerUserId', 'The new owner user id field must be a valid UUID.');
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
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::TargetNotMember);
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
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::TargetNotMember);
        $this->assertNothingTransferred($company, $owner);
        $this->assertInvitationNotConsumed($company, $invitee->email);
    }

    #[Test]
    public function when_the_target_membership_was_removed_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        $removed = User::factory()->create();
        $this->aRemovedMembership($company, $removed);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $removed->getKey()]);

        // Then — a soft-deleted pivot is not membership; the locked lookup's
        // live scope decides (the projector's withTrashed is replay-only)
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::TargetNotMember);
        $this->assertNothingTransferred($company, $owner);
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
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::SelfTransferBlocked);
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
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::StateGuardViolation);
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
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertNothingTransferred($company, $owner);
        $this->assertSame($this->seededRole('employee')->getKey(), $this->pivotOf($company, $target)->role_id);
    }

    /**
     * @param  array{sqlstate: string, code: int, message: string}  $failure
     */
    #[Test]
    #[DataProvider('contentionProvider')]
    public function when_the_transfer_hits_lock_contention_then_it_is_refused_as_a_conflict(
        array $failure,
        int $expectedStatus,
        ApiErrorCode $expected,
    ): void {
        // Given — a write inside the locked transaction that dies the way the
        // driver reports it (a REAL two-connection deadlock stays out per P9)
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        $this->induceProjectorFailureFor(CompanyOwnershipTransferred::class, $this->syntheticQueryException($failure));

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then — contention answers as a conflict; anything else passes
        // through (render only — a detector-matched deadlock skips the
        // client-side rollback a real one gets server-side; the
        // projector-failure row is the atomicity net)
        $this->assertRefusedWith($response, $expectedStatus, $expected);
    }

    #[Test]
    public function when_the_transfer_reads_the_rows_it_will_write_then_it_locks_them_in_ascending_order(): void
    {
        // Given — the target user is minted BEFORE the owner: uuid7 ids are
        // time-ordered, so ascending id order is the REVERSE of the interactor's
        // [owner, target] argument order and only a real sort can produce it
        $target = User::factory()->create();
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        $this->attachMember($company, $target, $this->seededRole('employee'));
        $expectedOrder = collect([$owner->getKey(), $target->getKey()])->sort()->values()->all();

        // When
        $capture = $this->recordedQueriesDuring(function () use ($owner, $company, $target): void {
            $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()])->assertOk();
        });

        // Then
        $this->assertSwapDecidedUnderOrderedLocks($capture, $expectedOrder);
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

    #[Test]
    public function when_the_outgoing_owner_owns_other_companies_then_those_are_untouched(): void
    {
        // Given
        $owner = User::factory()->create();
        $contoso = $this->aTrialCompanyOwnedBy($owner, 'Contoso');
        $acme = $this->aTrialCompanyOwnedBy($owner, 'Acme');
        [$target] = $this->aMembership($contoso, 'employee');

        // When
        $response = $this->transferOwnership($owner, $contoso, ['newOwnerUserId' => $target->getKey()]);

        // Then
        $response->assertOk();
        $this->assertOwnershipMoved($contoso, $owner, $target);
        $this->assertNothingTransferred($acme, $owner);
        $this->assertSame($this->seededRole('owner')->getKey(), $this->pivotOf($acme, $owner)->role_id);
    }

    #[Test]
    public function when_the_new_owner_has_opted_out_of_mail_then_the_ownership_notice_still_arrives(): void
    {
        // Given — ownership is a control-surface change; its notice is critical
        // and cannot be silenced (s170 PAUSE-1 ruling)
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        NotificationPreference::factory()->mailDisabled($target, NotificationType::OwnershipReceived)->create();
        Queue::fake();

        // When
        $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()])->assertOk();

        // Then
        $this->runQueuedHandlersFor(CompanyOwnershipTransferred::class);
        Mail::assertQueued(
            SystemNotificationMail::class,
            fn (SystemNotificationMail $mail): bool => $mail->type === NotificationType::OwnershipReceived
                && $mail->hasTo($target->email),
        );
    }

    #[Test]
    public function when_the_transfer_commits_then_the_new_owner_notification_rides_the_queue(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$target] = $this->aMembership($company, 'employee');
        Notification::fake();
        Queue::fake();

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then — queued, not inline: the swap is committed while zero notices
        // have been sent and the reactor job sits on the queue (the after-commit
        // half is config/queue.php after_commit, pinned app-level elsewhere)
        $response->assertOk();
        $this->assertOwnershipMoved($company, $owner, $target);
        $this->assertReactorDeferred(CompanyOwnershipTransferred::class, SystemNotification::class);
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
     * The swap serialized on its locks (spec §4 + the s169 lock-order rule):
     * inside the interactor's own transaction (depth > ambient), the companies
     * row and BOTH company_user rows were read FOR UPDATE, the two pivot locks
     * were taken in ascending user-id order, and every write followed the
     * locks. The companies lock serializes same-company transfers today; the
     * pivot order is pinned as insurance for writers that skip it.
     * Implementation-coupled by design — invisible to single-connection
     * behavioral rows (E21/P9; T2 depth+position oracle extended).
     *
     * @param  array{ambient: int, queries: array<int, array{sql: string, bindings: array<int, mixed>, depth: int}>}  $capture
     * @param  array<int, string>  $expectedUserIdOrder
     */
    protected function assertSwapDecidedUnderOrderedLocks(array $capture, array $expectedUserIdOrder): void
    {
        $companiesLockIndex = null;
        $pivotLocks = [];
        $firstWriteIndex = null;

        foreach ($capture['queries'] as $index => $query) {
            if ($query['depth'] <= $capture['ambient']) {
                continue;
            }

            $isLockedRead = str_starts_with($query['sql'], 'select') && str_contains($query['sql'], 'for update');

            if ($companiesLockIndex === null && $isLockedRead && str_contains($query['sql'], '`companies`')) {
                $companiesLockIndex = $index;
            }

            if ($isLockedRead && str_contains($query['sql'], '`company_user`')) {
                $pivotLocks[] = ['index' => $index, 'bindings' => $query['bindings']];
            }

            if ($firstWriteIndex === null
                && (str_starts_with($query['sql'], 'update') || str_starts_with($query['sql'], 'insert'))
                && (str_contains($query['sql'], '`companies`') || str_contains($query['sql'], '`company_user`') || str_contains($query['sql'], '`stored_events`'))) {
                $firstWriteIndex = $index;
            }
        }

        $this->assertNotNull($companiesLockIndex, 'Expected a locked companies read inside the transaction.');
        $this->assertCount(2, $pivotLocks, 'Expected BOTH company_user rows read FOR UPDATE inside the transaction.');

        $lockedUserIds = array_map(
            fn (array $lock): ?string => collect($lock['bindings'])->first(fn ($binding): bool => in_array($binding, $expectedUserIdOrder, true)),
            $pivotLocks,
        );
        $this->assertSame($expectedUserIdOrder, $lockedUserIds, 'The pivot rows must be locked in ascending user-id order.');
        $this->assertNotNull($firstWriteIndex, 'Expected an in-transaction write to follow the locks.');
        $this->assertGreaterThan(max($companiesLockIndex, $pivotLocks[1]['index']), $firstWriteIndex, 'Writes must follow all three locks.');
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
            ->where('meta_data->company_id', $company->getKey())
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
