<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * SM-1-05 T2 — a platform admin confirms the requester's identity and moves the
 * transfer Requested → Verified (UC-1-113, slice #11 Task 4). One behavior, one
 * seam, one actor, so the illegal-transition refusals, the input rejections and
 * the successful walk all live here, ordered Sad → Edge → Happy (§2.14 Rule A).
 *
 * The legal-hold refusal is an INTERACTOR guard, not an aggregate one: legal_hold
 * is the Company aggregate's state and this aggregate never reads the database
 * (s149 ruling B1; LD ApproveLeaveAction holds its billing-cancelled guard the
 * same way, leaving only the state guard on the aggregate).
 */
class VerifyOwnershipTransferIdentityTest extends OwnershipTransferAdminTestCase
{
    #[Test]
    public function when_the_verification_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given a transfer awaiting verification
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When a guest attempts to verify it
        $response = $this->verifyTransfer(null, $transfer->getKey());

        // Then the request is refused and the transfer is untouched
        $this->assertUnauthenticated($response);
        $this->assertTransferStillAwaitsVerification($transfer->getKey());
    }

    /**
     * The tenant roles the admin family must turn away with the SAME 404 an
     * unknown id produces. Born green — the platform.admin gate landed with the
     * route in lap 1 — so its teeth were proven by revert-check (drop the
     * middleware, watch all six rows RED, restore) rather than by a watched RED.
     */
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_the_caller_is_not_a_platform_admin_then_the_transfer_is_not_found(string $roleCode): void
    {
        // Given a transfer awaiting verification and a tenant member of its company
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $caller = $this->aCompanyMember($company, $roleCode);

        // When that tenant role attempts to verify it
        $response = $this->verifyTransfer($caller, $transfer->getKey());

        // Then the surface denies it without acknowledging the transfer exists
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertTransferStillAwaitsVerification($transfer->getKey());
    }

    /**
     * An id no transfer carries is refused with the same envelope a denied
     * caller gets. A VALID body is sent deliberately: it isolates the existence
     * check from input validation, so this test cannot pass for the wrong reason
     * once the Command's rules land.
     */
    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an allowlisted admin and an id that belongs to no transfer
        $admin = $this->anAllowlistedAdmin();

        // When they attempt to verify it
        $response = $this->verifyTransfer($admin, (string) Str::uuid7(), $this->verificationBody());

        // Then the surface refuses it indistinguishably from a denied caller
        $this->assertResourceNotFoundNoLeak($response);
    }

    /**
     * Every shape the verification record must refuse. Notes and artefacts are
     * both mandatory AT WRITE TIME (UC-1-113 minimal guarantee: no partial
     * verification record can reach stored_events), and each artefact must carry
     * the full ERD-1-05 triple with a `type` from the locked 7-value set.
     */
    #[Test]
    #[DataProvider('provideInvalidVerificationInputs')]
    public function when_the_verification_input_is_invalid_then_it_is_rejected(array $body, string $invalidField): void
    {
        // Given a transfer awaiting verification
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When an allowlisted admin submits a malformed verification
        $response = $this->verifyTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), $body);

        // Then it is refused on that field and nothing is recorded
        $this->assertValidationRejected($response, $invalidField);
        $this->assertTransferStillAwaitsVerification($transfer->getKey());
    }

    /**
     * T2's only source state is Requested (SM-1-05 §4), so a second verification
     * is refused and the first record stands. The arrangement drives a REAL first
     * verification rather than a factory row: the aggregate replays its stream,
     * not the projection, so a factory-arranged `verified` row would refuse for
     * the wrong reason (empty stream) and prove nothing about this transition.
     */
    #[Test]
    public function when_the_transfer_is_already_verified_then_it_is_not_verified_again(): void
    {
        // Given a transfer this admin has already verified
        $this->arrangeVerifiableTransfer();
        $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());
        $original = $this->transfer->fresh();

        // When a second verification is attempted against it
        $response = $this->verifyTransfer(
            $this->admin,
            $this->transfer->getKey(),
            $this->verificationBody(['verificationNotes' => 'Second reviewer took another look at the same documents.']),
        );

        // Then it is refused and the original verification is untouched
        $this->assertStateGuardRefused($response);
        $this->assertOriginalVerificationIntact($original);
    }

    // Happy — the T2 walk: the record is captured, the transfer moves to
    // Verified, and the projection mirrors it.
    #[Test]
    public function when_a_platform_admin_verifies_a_requested_transfer_then_the_identity_is_verified(): void
    {
        // Given a transfer awaiting verification and the admin reviewing it
        $this->arrangeVerifiableTransfer();

        // When the admin submits the verification record
        $response = $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());

        // Then the transfer is verified and the record is projected verbatim
        $this->assertVerificationAccepted($response);
        $this->assertIdentityVerifiedEventRecorded();
        $this->assertProjectionMirrorsVerification();
        $this->assertDeadlineMovedToTheVerifiedWindow(14);
    }

    private Company $company;

    private User $admin;

    private OwnershipTransferRequest $transfer;

    /**
     * Shared happy-path fixtures as properties (§2.2 Rule 4). The transfer is
     * arranged through the REAL T1 chain, not the factory: the factory writes
     * only the projection, so an aggregate retrieved against it would replay an
     * empty stream and the from-Requested guard would fire — the test would then
     * pass for entirely the wrong reason.
     */
    private function arrangeVerifiableTransfer(): void
    {
        [$this->company, $owner] = $this->aCompanyWithMember('owner');
        $this->admin = $this->anAllowlistedAdmin();
        $this->transfer = $this->aRequestedTransferStream($owner, User::factory()->create());
    }

    /**
     * A transfer whose stream genuinely carries the T1 genesis (and whose
     * projection the T1 projector therefore wrote), so T2 replays real history.
     */
    private function aRequestedTransferStream(User $owner, User $target): OwnershipTransferRequest
    {
        $requestId = (string) Str::uuid7();

        OwnershipTransferRequestAggregate::retrieve($requestId)
            ->request(RequestOwnershipTransferCommand::validateAndCreate([
                'companyId' => $this->company->getKey(),
                'currentOwnerUserId' => $owner->getKey(),
                'targetUserId' => $target->getKey(),
                'reason' => 'Founder is stepping down and handing the company to the operations lead.',
                'requestChannel' => 'app',
            ]))
            ->persist();

        return OwnershipTransferRequest::query()->findOrFail($requestId);
    }

    /**
     * 200 with the admin envelope reporting the new state.
     */
    private function assertVerificationAccepted(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.id', $this->transfer->getKey());
        $response->assertJsonPath('data.status', 'verified');
        $response->assertJsonPath('data.verified_by', $this->admin->getKey());
    }

    /**
     * The transition was refused by a state guard — the shared 409 family the
     * §8.4 registry reserves for illegal state-machine moves.
     */
    private function assertStateGuardRefused(TestResponse $response): void
    {
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The refused second attempt changed nothing: the stream still carries
     * exactly ONE verification event, and the projected record still names the
     * first verifier, time and notes. Without the event-count aspect a guard
     * that recorded a duplicate but left the columns alone would pass.
     */
    private function assertOriginalVerificationIntact(OwnershipTransferRequest $original): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferIdentityVerified::class)
            ->count());

        $row = $this->transfer->fresh();

        $this->assertSame($original->verified_by, $row->verified_by);
        $this->assertSame($original->verification_notes, $row->verification_notes);
        $this->assertTrue($row->verified_at->equalTo($original->verified_at));
    }

    /**
     * Amendment R — the EVENT is the mechanism, not a direct row write. The two
     * are indistinguishable from the read model alone (both leave identical
     * columns), so without this the transition could be reduced to an update()
     * and silently lose its history on a projection replay. Teeth proven by
     * revert-check: swapping the interactor for a direct write keeps every other
     * assertion green and REDs only this one.
     *
     * The payload carries the legal-defensibility trio verbatim, which is why
     * verifiedBy/notes/artefacts are asserted on the STORED event rather than
     * only on the projection.
     */
    private function assertIdentityVerifiedEventRecorded(): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferIdentityVerified::class)
            ->whereForCompany($this->company->getKey())
            ->sole();

        $this->assertSame($this->transfer->getKey(), $stored->event_properties['requestId']);
        $this->assertSame($this->admin->getKey(), $stored->event_properties['verifiedBy']);
        $this->assertSame(self::verificationBody()['verificationNotes'], $stored->event_properties['verificationNotes']);
        $this->assertSame([self::anArtefact()], $stored->event_properties['verificationArtefacts']);
    }

    /**
     * The SLA clock switches windows at T2: the 30-day requested-stage anchor is
     * replaced by verified_at + the configured verified-stage window (OQ-1-006).
     * Asserted against the CONFIGURED number so a hardcoded 14 cannot satisfy it
     * — the reconfiguration test drives that from the other side.
     */
    private function assertDeadlineMovedToTheVerifiedWindow(int $expectedDays): void
    {
        $row = $this->transfer->fresh();

        $this->assertNotNull($row->verified_at);
        $this->assertTrue(
            $row->next_state_transition_at->equalTo($row->verified_at->addDays($expectedDays)),
            "Expected the deadline at verified_at + {$expectedDays}d, got {$row->next_state_transition_at}.",
        );
    }

    /**
     * The read model carries the full verification record — state, actor,
     * timestamp, and both captured fields verbatim.
     */
    private function assertProjectionMirrorsVerification(): void
    {
        $row = $this->transfer->fresh();

        $this->assertSame(OwnershipTransferStatus::Verified, $row->status);
        $this->assertSame($this->admin->getKey(), $row->verified_by);
        $this->assertNotNull($row->verified_at);
        $this->assertSame($this->verificationBody()['verificationNotes'], $row->verification_notes);
        $this->assertSame([self::anArtefact()], $row->verification_artefacts);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidVerificationInputs(): array
    {
        return [
            'notes absent' => [self::verificationBodyWithout('verificationNotes'), 'verificationNotes'],
            'notes empty' => [self::verificationBody(['verificationNotes' => '']), 'verificationNotes'],
            'notes whitespace only' => [self::verificationBody(['verificationNotes' => '   ']), 'verificationNotes'],
            'notes beyond the bound' => [self::verificationBody(['verificationNotes' => str_repeat('a', 1001)]), 'verificationNotes'],
            'artefacts absent' => [self::verificationBodyWithout('verificationArtefacts'), 'verificationArtefacts'],
            'artefacts empty' => [self::verificationBody(['verificationArtefacts' => []]), 'verificationArtefacts'],
            'artefacts not a list' => [self::verificationBody(['verificationArtefacts' => 'SUP-4821']), 'verificationArtefacts'],
            'artefact type absent' => [self::verificationBodyWithArtefact(self::anArtefactWithout('type')), 'verificationArtefacts.0.type'],
            'artefact type outside the locked set' => [self::verificationBodyWithArtefact(self::anArtefact(['type' => 'signed_affidavit'])), 'verificationArtefacts.0.type'],
            'artefact reference absent' => [self::verificationBodyWithArtefact(self::anArtefactWithout('reference')), 'verificationArtefacts.0.reference'],
            'artefact capturedAt not a date' => [self::verificationBodyWithArtefact(self::anArtefact(['capturedAt' => 'last Tuesday'])), 'verificationArtefacts.0.capturedAt'],
        ];
    }

    /**
     * The canonical valid verification payload — camelCase in, per
     * api-conventions §8.1. Static so the DataProvider can break exactly one
     * field of it per row.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function verificationBody(array $overrides = []): array
    {
        return array_merge([
            'verificationNotes' => 'National ID matched the applicant; board resolution authorises the transfer.',
            'verificationArtefacts' => [self::anArtefact()],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function verificationBodyWithout(string $key): array
    {
        $body = self::verificationBody();
        unset($body[$key]);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $artefact
     * @return array<string, mixed>
     */
    private static function verificationBodyWithArtefact(array $artefact): array
    {
        return self::verificationBody(['verificationArtefacts' => [$artefact]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function anArtefact(array $overrides = []): array
    {
        return array_merge([
            'type' => 'id_document',
            'reference' => 'SUP-4821/nic-scan',
            'capturedAt' => '2026-07-24T09:15:00+00:00',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function anArtefactWithout(string $key): array
    {
        $artefact = self::anArtefact();
        unset($artefact[$key]);

        return $artefact;
    }

    /**
     * Nothing was recorded and nothing moved: the stream carries no event at all
     * (the fixture is projected directly, so an empty stream IS the baseline),
     * no access was logged, and the row still sits in Requested. The trio is what
     * makes every refusal test prove a non-event rather than just a status code.
     */
    private function assertTransferStillAwaitsVerification(string $requestId): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->count());
        $this->assertNoAuditRecorded();
        $this->assertSame(
            OwnershipTransferStatus::Requested,
            OwnershipTransferRequest::query()->findOrFail($requestId)->status,
        );
    }
}
