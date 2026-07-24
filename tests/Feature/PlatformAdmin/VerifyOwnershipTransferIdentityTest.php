<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Notifications\SystemNotification;
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
        $original = $this->currentTransfer();

        // When a second verification is attempted against it
        $response = $this->verifyTransfer(
            $this->admin,
            $this->transfer->getKey(),
            $this->verificationBody(['verificationNotes' => 'Second reviewer took another look at the same documents.']),
        );

        // Then it is refused and the original verification is untouched
        $this->assertStateGuardRefused($response, 'Only a transfer awaiting verification can be verified.');
        $this->assertOriginalVerificationIntact($original);
    }

    /**
     * A regulatory hold freezes ownership changes, not merely the expiry timers
     * (slice-#11 D3): verification is refused while it stands.
     *
     * The transfer is arranged as a REAL requested stream, deliberately. Both
     * this refusal and the state guard render the same 409 code, so a
     * factory-arranged row — replaying an empty stream — would trip the state
     * guard and produce an identical response with no legal-hold guard present
     * at all. Only genuine Requested history makes this test fail when the
     * guard is removed.
     *
     * The unheld bystander company is arranged FIRST for a second reason: it is
     * what makes the guard's `whereKey` observable. Without it there is one
     * company in the fixture, so an unscoped read returns the held one anyway
     * and the scope could be deleted with the suite still green — the combined
     * revert-check proved exactly that gap and this line closes it.
     */
    #[Test]
    public function when_the_company_is_under_legal_hold_then_the_transfer_is_not_verified(): void
    {
        // Given an unheld company that predates it, then a verifiable transfer
        // whose own company is under a regulatory hold
        $this->aBystanderTransferAtAnotherCompany();
        $this->arrangeVerifiableTransfer();
        $this->placeCompanyUnderLegalHold();

        // When an allowlisted admin attempts to verify it
        $response = $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());

        // Then it is refused and no verification is recorded
        $this->assertStateGuardRefused($response, 'This company is under a legal hold; ownership changes are frozen until it is lifted.');
        $this->assertNoVerificationRecorded();
    }

    /**
     * The regulatory freeze on the arranged company. A named fixture helper
     * carrying the column write is the sanctioned shape — §2.13 bans inline
     * overrides in test BODIES, not in fixtures (the aTransferDueAt precedent).
     */
    private function placeCompanyUnderLegalHold(): void
    {
        $this->company->forceFill(['legal_hold' => true])->save();
    }

    /**
     * The refusal left the stream carrying only its T1 genesis and the row still
     * awaiting verification — the legal-hold analogue of the
     * assertTransferStillAwaitsVerification trio, for streams that legitimately
     * already hold one event.
     */
    private function assertNoVerificationRecorded(): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferIdentityVerified::class)
            ->count());
        // Exactly the T1 genesis row — the refusal logged nothing (UC error flow
        // 6d: no audit entry). The sad-path trio asserts a zero baseline; here
        // the legitimate baseline is one.
        $this->assertDatabaseCount('audit_trail', 1);

        $row = $this->currentTransfer();

        $this->assertSame(OwnershipTransferStatus::Requested, $row->status);
        $this->assertNull($row->verified_at);
        $this->assertNull($row->verified_by);
    }

    /**
     * The verified-stage window is configuration, not a literal. This exists
     * because the sibling T1 assertion pins a hardcoded 30, so a hardcoded 14
     * here would satisfy every other test in the class — and Infection does not
     * mutate a config default into a literal, so nothing else would catch it
     * (the s148 "MSI 100% ≠ assertion teeth" finding). Teeth proven by
     * revert-check: hardcoding the projector's 14 REDs this test alone.
     */
    #[Test]
    public function when_the_verified_window_is_reconfigured_then_the_new_deadline_is_applied(): void
    {
        // Given a verifiable transfer and a shortened verified-stage window
        $this->arrangeVerifiableTransfer();
        config(['ownership-transfers.verified_ttl_days' => 7]);

        // When the admin verifies it
        $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());

        // Then the deadline follows the configured window
        $this->assertDeadlineMovedToTheVerifiedWindow(7);
    }

    /**
     * Every accepted artefact kind survives the round trip through the JSON
     * column byte-for-byte — the metadata-only contract (OQ-1-045 / D4).
     *
     * The seven type strings are spelled as LITERALS here, never derived from
     * VerificationArtefactType: deriving them would make the test move with the
     * enum, so dropping a case would silently narrow the accepted set on both
     * sides and stay green. Literals make this the regression pin for the locked
     * vocabulary.
     */
    #[Test]
    public function when_multiple_artefacts_are_submitted_then_every_reference_is_persisted_verbatim(): void
    {
        // Given a verifiable transfer and one artefact of every accepted kind
        $this->arrangeVerifiableTransfer();
        $artefacts = self::oneArtefactOfEveryType();

        // When the admin submits them all in a single verification
        $response = $this->verifyTransfer(
            $this->admin,
            $this->transfer->getKey(),
            $this->verificationBody(['verificationArtefacts' => $artefacts]),
        );

        // Then all seven are accepted and stored verbatim, event and projection alike
        $this->assertArtefactsPersistedVerbatim($response, $artefacts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function oneArtefactOfEveryType(): array
    {
        return array_map(
            fn (string $type): array => self::anArtefact([
                'type' => $type,
                'reference' => "SUP-4821/{$type}",
            ]),
            [
                'email_confirmation',
                'id_document',
                'video_call',
                'death_certificate',
                'poa',
                'court_order',
                'other',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $artefacts
     */
    private function assertArtefactsPersistedVerbatim(TestResponse $response, array $artefacts): void
    {
        $response->assertOk();

        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferIdentityVerified::class)
            ->sole();

        $this->assertSame($artefacts, $stored->event_properties['verificationArtefacts']);
        $this->assertSame($artefacts, $this->currentTransfer()->verification_artefacts);
        $response->assertJsonPath('data.verification_artefacts', $artefacts);
    }

    /**
     * The verifier is whoever the token says, never whoever the body claims.
     * `verifiedBy` is the single field this whole use case exists to produce —
     * every stakeholder clause about defending a transfer against a later
     * fraud claim rests on it being auth-derived — yet the only thing enforcing
     * that is an `only()` whitelist and the spread ordering in the controller,
     * neither of which any other test touches. A refactor to `$request->all()`
     * would let one admin attribute a verification to a colleague.
     */
    #[Test]
    public function when_the_body_names_a_different_verifier_then_the_authenticated_admin_is_recorded(): void
    {
        // Given a verifiable transfer and a colleague to impersonate
        $this->arrangeVerifiableTransfer();
        $colleague = User::factory()->create();

        // When the admin submits a body claiming the colleague verified it
        $this->verifyTransfer(
            $this->admin,
            $this->transfer->getKey(),
            $this->verificationBody(['verifiedBy' => $colleague->getKey()]),
        );

        // Then every record of the verifier names the authenticated admin
        $this->assertVerifierIsAlways($this->admin);
    }

    /**
     * The projector and the legal-hold guard must each address ONE row. Both are
     * fluent `whereKey(...)` calls, and Infection generates no method-call
     * removal on a builder chain — so with a single company and a single
     * transfer in the fixture, dropping either scope leaves the suite green
     * while, in production, one admin's verification would flip every transfer
     * on the platform (or one company's hold would freeze every tenant).
     *
     * A second, unrelated tenant is therefore part of the arrangement, not an
     * afterthought: it is the only thing that makes the scopes observable.
     */
    #[Test]
    public function when_a_transfer_is_verified_then_no_other_tenants_transfer_is_touched(): void
    {
        // Given a verifiable transfer and an untouched transfer at another company
        $this->arrangeVerifiableTransfer();
        $bystander = $this->aBystanderTransferAtAnotherCompany();

        // When the admin verifies the first
        $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());

        // Then the other tenant's transfer is exactly as it was
        $this->assertTransferUntouched($bystander);
    }

    /**
     * A hold belongs to ONE company. Without an unheld bystander the guard's
     * scope is unobservable — an unscoped read would find the held row and
     * refuse every tenant's verification identically.
     */
    #[Test]
    public function when_another_company_is_under_legal_hold_then_this_transfer_still_verifies(): void
    {
        // Given a verifiable transfer and a DIFFERENT company under a hold
        $this->arrangeVerifiableTransfer();
        $this->aBystanderTransferAtAnotherCompany()->company()->update(['legal_hold' => true]);

        // When the admin verifies the unheld company's transfer
        $response = $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());

        // Then it succeeds — the hold applied to its own tenant only
        $this->assertVerificationAccepted($response);
    }

    /**
     * verified_at is the T9 SLA anchor AND the legally-significant midpoint
     * timestamp of the chain of custody, but every existing assertion on it is
     * either non-null or RELATIVE — the deadline derives from the same instant,
     * so both sides move together. Shifting the aggregate's clock by five years
     * would leave the whole class green. Freezing time is what pins it.
     */
    #[Test]
    public function when_a_transfer_is_verified_then_the_record_is_stamped_with_the_server_clock(): void
    {
        // Given a verifiable transfer and a frozen clock
        $this->arrangeVerifiableTransfer();
        $frozen = CarbonImmutable::parse('2026-07-24 09:15:00');
        $this->travelTo($frozen);

        // When the admin verifies it
        $this->verifyTransfer($this->admin, $this->transfer->getKey(), $this->verificationBody());

        // Then both the stamp and the deadline sit at absolute instants
        $this->assertStampedAt($frozen);
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
        $this->assertExecutionQueueAlerted();
        $this->assertVerificationAudited();
    }

    private Company $company;

    private User $admin;

    private User $secondAdmin;

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
        // TWO admins on the allowlist, not one. With a single recipient the
        // reactor's ->get()->each(...) fan-out degrades to ->first()?->notify()
        // undetected, and in production only one of several admins would ever
        // learn a transfer is ready to execute.
        $this->admin = $this->anAllowlistedAdmin();
        $this->secondAdmin = User::factory()->create();
        $this->allowlist([$this->admin->getKey(), $this->secondAdmin->getKey()]);
        $this->transfer = $this->aRequestedTransferStream($owner, User::factory()->create());

        // Discard the arrangement's own T1 side effects. The genesis fires
        // SupportQueueNotificationReactor at the SAME allowlisted admin with the
        // SAME SystemNotification class, so without this reset a T2 reactor
        // assertion would pass on the T1 notification alone — green with no
        // queue reactor in the codebase at all.
        Notification::fake();
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
     * A transfer belonging to an entirely different tenant, left alone. Its only
     * job is to be a second row, which is what makes the projector's and the
     * guard's `whereKey(...)` scopes observable at all.
     */
    private function aBystanderTransferAtAnotherCompany(): OwnershipTransferRequest
    {
        [$otherCompany] = $this->aCompanyWithMember('owner');

        return $this->aTransferInState($otherCompany, User::factory()->create(), OwnershipTransferStatus::Requested);
    }

    /**
     * Every surface that records who vouched for the identity names $expected —
     * the event payload (the durable evidence), the projection (what the admin
     * console reads) and the audit row (the access log). A forged body value
     * would have to defeat all three.
     */
    private function assertVerifierIsAlways(User $expected): void
    {
        $stored = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferIdentityVerified::class)
            ->sole();

        $this->assertSame($expected->getKey(), $stored->event_properties['verifiedBy']);
        $this->assertSame($expected->getKey(), $this->currentTransfer()->verified_by);
        $this->assertSame($expected->getKey(), AuditEntry::query()
            ->where('event_class', 'OwnershipTransferIdentityVerified')
            ->sole()
            ->actor_user_id);
    }

    /**
     * The bystander is byte-for-byte as it was: still awaiting verification,
     * never stamped, and still on its own original deadline.
     */
    private function assertTransferUntouched(OwnershipTransferRequest $bystander): void
    {
        $row = OwnershipTransferRequest::query()->whereKey($bystander->getKey())->firstOrFail();

        $this->assertSame(OwnershipTransferStatus::Requested, $row->status);
        $this->assertNull($row->verified_at);
        $this->assertNull($row->verified_by);
        $this->assertNull($row->verification_notes);

        $deadline = $row->next_state_transition_at;
        $originalDeadline = $bystander->next_state_transition_at;
        $this->assertNotNull($deadline);
        $this->assertNotNull($originalDeadline);
        $this->assertTrue($deadline->equalTo($originalDeadline));
    }

    /**
     * Both the stamp and the derived deadline sit at ABSOLUTE instants. The
     * existing deadline helper is relative — it derives both sides from the same
     * recorded value — so only a frozen clock can catch a wrong time source.
     */
    private function assertStampedAt(CarbonImmutable $frozen): void
    {
        $row = $this->currentTransfer();
        $verifiedAt = $row->verified_at;
        $deadline = $row->next_state_transition_at;

        $this->assertNotNull($verifiedAt);
        $this->assertNotNull($deadline);
        $this->assertTrue($verifiedAt->equalTo($frozen), "Expected verified_at at {$frozen}, got {$verifiedAt}.");
        $this->assertTrue($deadline->equalTo($frozen->addDays(14)), "Expected the deadline at {$frozen->addDays(14)}, got {$deadline}.");
    }

    /**
     * The transfer as it stands in the database RIGHT NOW, as a distinct
     * instance every call. Deliberately not `$this->transfer->refresh()`, which
     * mutates the shared property in place: the already-verified test captures a
     * BEFORE snapshot and compares it to the AFTER state, and refresh() would
     * hand it the same object twice, quietly turning that comparison into a
     * tautology. findOrFail also types non-null, which fresh() does not.
     */
    private function currentTransfer(): OwnershipTransferRequest
    {
        return OwnershipTransferRequest::query()->whereKey($this->transfer->getKey())->firstOrFail();
    }

    /**
     * The transition was refused by a state guard — the shared 409 family the
     * §8.4 registry reserves for illegal state-machine moves.
     *
     * The MESSAGE is asserted, not just the code, and that is the whole point:
     * both refusals in this class render the identical envelope, so without a
     * discriminator, throwing the wrong exception from the legal-hold guard
     * would stay green — and the CompanyUnderLegalHoldException render arm in
     * bootstrap/app.php would become dead code in the one file Infection cannot
     * see and coverage does not track. This survives substitution, not merely
     * deletion.
     */
    private function assertStateGuardRefused(TestResponse $response, string $expectedMessage): void
    {
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', $expectedMessage);
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
        // T1 genesis + the ONE accepted verification. A third row would mean the
        // refused attempt was logged as though it had happened — Gherkin sc.5's
        // "the original audit entry remains untouched".
        $this->assertDatabaseCount('audit_trail', 2);

        $row = $this->currentTransfer();
        $verifiedAt = $row->verified_at;
        $originalVerifiedAt = $original->verified_at;

        $this->assertSame($original->verified_by, $row->verified_by);
        $this->assertSame($original->verification_notes, $row->verification_notes);
        $this->assertNotNull($verifiedAt);
        $this->assertNotNull($originalVerifiedAt);
        $this->assertTrue($verifiedAt->equalTo($originalVerifiedAt));
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
        $row = $this->currentTransfer();
        $verifiedAt = $row->verified_at;
        $deadline = $row->next_state_transition_at;

        $this->assertNotNull($verifiedAt);
        $this->assertNotNull($deadline);
        $this->assertTrue(
            $deadline->equalTo($verifiedAt->addDays($expectedDays)),
            "Expected the deadline at verified_at + {$expectedDays}d, got {$deadline}.",
        );
    }

    /**
     * T2's operational signal: the platform-admin queue learns an
     * execution-ready transfer is waiting (SM-1-05 §7 — PlatformAdminQueueReactor
     * is T2's reactor, NOT a requester notification, which is T3/T5/T8/T9's).
     * The tenant side must NOT be told: verification is a platform-side step and
     * the owner's view of it is UC-1-125, not a push.
     */
    private function assertExecutionQueueAlerted(): void
    {
        $carriesTheTenant = fn (SystemNotification $notification): bool => $notification->type === NotificationType::GeneralAccountNotice
            && $notification->companyName === $this->company->name;

        // EVERY allowlisted admin, not just the first, and each carrying the
        // tenant it concerns — without the payload check the whole company
        // lookup in the reactor is content-dead and could return an empty name.
        Notification::assertSentTo($this->admin, SystemNotification::class, $carriesTheTenant);
        Notification::assertSentTo($this->secondAdmin, SystemNotification::class, $carriesTheTenant);
        Notification::assertNotSentTo([$this->transfer->currentOwner, $this->transfer->targetUser], SystemNotification::class);
    }

    /**
     * The append-only audit row for the transition, linked to the stored event.
     * The ACTOR is the point of this row — it is the record of which platform
     * admin vouched for the identity, which is the evidence a disputed transfer
     * turns on.
     */
    private function assertVerificationAudited(): void
    {
        $entry = AuditEntry::query()->where('event_class', 'OwnershipTransferIdentityVerified')->sole();

        $this->assertSame($this->transfer->getKey(), $entry->subject_id);
        $this->assertSame($this->company->getKey(), $entry->company_id);
        $this->assertSame('transfer_identity_verified', $entry->action);
        $this->assertSame($this->admin->getKey(), $entry->actor_user_id);
        $this->assertSame($this->admin->name, $entry->actor_label);
        $this->assertSame('ownership_transfer_request', $entry->subject_type);
        $this->assertNotNull($entry->stored_event_id);
        // Deliberately null: audit_trail rows are TENANT-readable via UC-1-066,
        // and the verifier's verbatim notes are platform-side forensic prose
        // about a person's identity documents. They live on the event, reachable
        // from here through stored_event_id (s149 ruling; the s148 queue-row
        // metadata pin is the precedent).
        $this->assertNull($entry->metadata);
    }

    /**
     * The read model carries the full verification record — state, actor,
     * timestamp, and both captured fields verbatim.
     */
    private function assertProjectionMirrorsVerification(): void
    {
        $row = $this->currentTransfer();

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
            // The metadata-only contract is a DPO commitment, not a shape
            // preference: artefacts persist VERBATIM into stored_events, so an
            // unpoliced extra key is a route for document bytes to land in the
            // immutable event store, which the UC and the event docblock both
            // forbid (OQ-1-045 / D4).
            // The array needs a ceiling for the same reason the object needs
            // closing: it lands in an append-only store, so an unbounded write
            // is an unremediable one.
            'artefacts beyond the bound' => [
                self::verificationBody(['verificationArtefacts' => array_fill(0, 21, self::anArtefact())]),
                'verificationArtefacts',
            ],
            'artefact smuggling an extra key' => [
                self::verificationBodyWithArtefact(self::anArtefact(['scan' => 'JVBERi0xLjcKJc-base64-document-bytes'])),
                'verificationArtefacts.0',
            ],
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
