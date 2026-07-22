<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Exceptions\OpenOwnershipTransferExistsException;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * UC-1-028 — Request Ownership Transfer (slice #11 Task 1, SM-1-05 T1): the
 * current Owner formally requests transfer of company ownership to another
 * member; the request is queued for platform-support verification before any
 * privilege change happens.
 */
class RequestOwnershipTransferTest extends OwnershipTransfersTestCase
{
    #[Test]
    public function when_the_request_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given no authenticated session

        // When an ownership-transfer request is posted
        $response = $this->postTransferRequestAsGuest();

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    #[DataProvider('provideNonOwnerRoles')]
    public function when_the_actor_is_not_the_owner_then_the_request_is_denied(string $roleCode): void
    {
        // Given a company member holding a non-owner seeded role
        $company = Company::factory()->create();
        $actor = $this->aMemberWithRole($company, $roleCode);

        // When they post an ownership-transfer request
        $response = $this->postTransferRequest($actor, $company);

        // Then it is denied for lacking the owner-only request permission
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonOwnerRoles(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    #[DataProvider('provideInvalidRequestInputs')]
    public function when_the_request_input_is_invalid_then_the_request_is_rejected(array $body, string $invalidField): void
    {
        // Given a company owner
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they post a transfer request with invalid input
        $response = $this->postTransferRequest($owner, $company, $body);

        // Then it is refused as shape-invalid on the offending field
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_the_target_user_is_not_a_member_of_the_company_then_the_request_is_rejected(): void
    {
        // Given a company owner and a user who is not a member of the company
        [$company, $owner] = $this->aCompanyWithOwner();
        $outsider = User::factory()->create();

        // When the owner requests a transfer to the outsider
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($outsider));

        // Then it is refused as an invalid target
        $this->assertValidationRejected($response, 'targetUserId');
    }

    #[Test]
    public function when_the_owner_targets_themselves_then_the_request_is_rejected(): void
    {
        // Given a company owner
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they request a transfer to themselves
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($owner));

        // Then it is refused as an invalid target
        $this->assertValidationRejected($response, 'targetUserId');
    }

    #[Test]
    public function when_the_target_lacks_an_emergency_contact_then_the_request_is_rejected(): void
    {
        // Given a company owner and a member who has never set an emergency contact
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');

        // When the owner requests a transfer to that member
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then it is refused until the target sets their emergency contact (§S10b mandate)
        $this->assertRefusedWith($response, ApiErrorCode::EmergencyContactRequired);
    }

    #[Test]
    public function when_an_open_transfer_request_already_exists_then_a_second_request_is_rejected(): void
    {
        // Given a company with an open (requested) transfer already on file
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        OwnershipTransferRequest::factory()->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $owner->getKey(),
            'target_user_id' => $target->getKey(),
        ]);

        // When the owner posts another transfer request
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then it is refused — one transfer at a time per company
        $this->assertRefusedWith($response, ApiErrorCode::StateGuardViolation);
    }

    #[Test]
    public function when_the_request_omits_the_idempotency_key_then_it_is_rejected(): void
    {
        // Given a company owner with an eligible target
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);

        // When the request is posted without an Idempotency-Key
        $response = $this->postTransferRequestWithoutKey($owner, $company, $this->transferBodyFor($target));

        // Then it is refused — the mutation mounts the required idempotency contract
        $this->assertRefusedWith($response, ApiErrorCode::IdempotencyKeyRequired);
    }

    #[Test]
    public function when_a_transfer_is_re_requested_on_the_same_stream_then_the_second_request_is_rejected(): void
    {
        // Given a transfer request already recorded on a stream (Tier-2 seam —
        // the aggregate's own genesis guard, below the HTTP Tier-1 repo read).
        // Real rows: the projector reacts to the persist, so the projection's
        // FK chain must hold even at the seam.
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $command = RequestOwnershipTransferCommand::validateAndCreate([
            'companyId' => $company->getKey(),
            'currentOwnerUserId' => $owner->getKey(),
            'targetUserId' => $target->getKey(),
            'reason' => 'Retiring from the business',
            'requestChannel' => 'app',
        ]);
        $requestId = (string) Str::uuid7();
        OwnershipTransferRequestAggregate::retrieve($requestId)->request($command)->persist();

        // When the genesis fires again on the same stream
        try {
            OwnershipTransferRequestAggregate::retrieve($requestId)->request($command);
            $this->fail('Expected the genesis re-fire to be rejected.');
        } catch (OpenOwnershipTransferExistsException) {
            // Then only the original event remains on the stream
            $this->assertSame(1, LesilyStoredEvent::query()
                ->where('event_class', OwnershipTransferRequested::class)
                ->count());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidRequestInputs(): array
    {
        return [
            'missing target' => [
                ['reason' => 'Retiring from the business', 'requestChannel' => 'app'],
                'targetUserId',
            ],
            'missing reason' => [
                ['targetUserId' => '0193abcd-9999-7000-8000-000000000001', 'requestChannel' => 'app'],
                'reason',
            ],
            'invalid channel' => [
                ['targetUserId' => '0193abcd-9999-7000-8000-000000000001', 'reason' => 'Retiring', 'requestChannel' => 'email'],
                'requestChannel',
            ],
        ];
    }

    #[Test]
    public function when_the_same_request_is_retried_with_the_same_idempotency_key_then_the_original_outcome_is_replayed(): void
    {
        // Given a successfully submitted transfer request
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        $this->pinIdempotencyKey((string) Str::uuid7());
        $first = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));
        $first->assertCreated();

        // When the identical request is retried with the same Idempotency-Key
        $replay = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then the original outcome is replayed without a second side effect
        $replay->assertCreated();
        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, OwnershipTransferRequest::query()->count());
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRequested::class)
            ->count());
    }

    #[Test]
    public function when_the_owner_requests_a_transfer_to_an_eligible_member_then_the_request_is_created(): void
    {
        // Given a company owner, an eligible member (emergency contact set),
        // and a platform support admin on the allowlist
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = $this->aMemberWithRole($company, 'admin');
        $this->withEmergencyContact($company, $target);
        $supportAdmin = $this->aPlatformSupportAdmin();

        // When the owner requests the transfer
        $response = $this->postTransferRequest($owner, $company, $this->transferBodyFor($target));

        // Then the request is created, the genesis event is recorded, the
        // projection row + 30-day timer are written, and the T1 side effects fire
        $requestId = $this->assertTransferCreated($response, $company);
        $this->assertRequestedEventRecorded($company, $owner, $target);
        $this->assertProjectionAndTimer($requestId, $company, $owner, $target);
        $this->assertRequestSideEffects($supportAdmin, $requestId, $owner);
    }

    /**
     * 201 with the owner-facing snake_case envelope; returns the new id.
     */
    private function assertTransferCreated(TestResponse $response, Company $company): string
    {
        $response->assertCreated();
        $response->assertJsonPath('data.status', 'requested');
        $response->assertJsonPath('data.company_id', $company->getKey());
        $response->assertJsonPath('data.request_channel', 'app');

        return $response->json('data.id');
    }

    /**
     * The genesis OwnershipTransferRequested is on the company-scoped stream
     * with the actor + target in its payload (amendment R — the event, not a
     * direct row write, is the mechanism).
     */
    private function assertRequestedEventRecorded(Company $company, User $owner, User $target): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferRequested::class)
            ->whereForCompany($company->getKey())
            ->sole();

        $this->assertSame($owner->getKey(), $stored->event_properties['currentOwnerUserId']);
        $this->assertSame($target->getKey(), $stored->event_properties['targetUserId']);
    }

    /**
     * The projection row mirrors the event and carries the 30-day
     * next_state_transition_at anchor (SM-1-05 T1 / OQ-1-006).
     */
    private function assertProjectionAndTimer(string $requestId, Company $company, User $owner, User $target): void
    {
        $row = OwnershipTransferRequest::query()->findOrFail($requestId);

        $this->assertSame($company->getKey(), $row->company_id);
        $this->assertSame($owner->getKey(), $row->current_owner_user_id);
        $this->assertSame($target->getKey(), $row->target_user_id);
        $this->assertSame(OwnershipTransferStatus::Requested, $row->status);
        $this->assertNotNull($row->next_state_transition_at);
        $this->assertTrue($row->next_state_transition_at->equalTo($row->requested_at->addDays(30)));
    }

    /**
     * The two T1 side effects: the support-queue notification to the platform
     * admin and the append-only audit_trail row attributing the owner.
     */
    private function assertRequestSideEffects(User $supportAdmin, string $requestId, User $owner): void
    {
        Notification::assertSentTo($supportAdmin, SystemNotification::class);

        $entry = AuditEntry::query()->where('subject_id', $requestId)->sole();
        $this->assertSame('transfer_requested', $entry->action);
        $this->assertSame($owner->getKey(), $entry->actor_user_id);
    }
}
