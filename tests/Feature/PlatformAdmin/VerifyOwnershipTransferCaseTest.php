<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class VerifyOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    protected string $notes = 'Death certificate matched the registered Owner';

    /**
     * @var array{type: string, reference: string, capturedAt: string}
     */
    protected array $artefact = [
        'type' => 'id_document',
        'reference' => 'NIC 199012345678 sighted via notarised copy',
        'capturedAt' => '2026-08-09T10:30:00Z',
    ];

    #[Test]
    public function when_no_token_is_presented_then_the_verify_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the verify endpoint is called as a guest
        $response = $this->verifyCase(null, (string) Str::uuid7());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_verify_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given Owner Olive of Contoso and an open case for HER OWN company
        $company = Company::factory()->create();
        $olive = $this->aCompanyMember($company, 'owner');
        $case = $this->anOpenCaseFor($company);

        // When Olive calls the verify endpoint for her company's case
        $response = $this->verifyCase($olive, $case->getKey());

        // Then the admin surface is never acknowledged and nothing is written
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingVerified($case);
    }

    #[Test]
    public function when_the_case_id_is_unknown_then_nothing_is_found_and_no_verification_is_recorded(): void
    {
        // Given an allowlisted admin and no case at all
        $stranger = User::factory()->create();

        // When they verify an id that names no case
        $response = $this->verifyCase(
            $this->admin,
            (string) Str::uuid7(),
            $this->aValidVerificationPayload($stranger),
        );

        // Then nothing is found and no verification evidence is written
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    #[DataProvider('invalidVerificationPayloadProvider')]
    public function when_the_verification_payload_is_invalid_then_the_verification_is_refused(array $overrides, string $field): void
    {
        // Given an open case and a payload carrying this row's defect
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);
        $stranger = User::factory()->create();

        // When the admin submits the verification
        $response = $this->verifyCase(
            $this->admin,
            $case->getKey(),
            $this->aValidVerificationPayload($stranger, $overrides),
        );

        // Then the verification is refused naming the offending field and nothing is written
        $this->assertValidationFailed($response, $field);
        $this->assertNothingVerified($case);
    }

    #[Test]
    #[DataProvider('nonOpenCaseProvider')]
    public function when_the_case_is_not_open_then_the_verification_is_refused_with_the_current_state_named(string $fixture, string $stateName): void
    {
        // Given a case that is no longer open and a live member of its company
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company, 'employee');
        $case = match ($fixture) {
            'verified' => $this->aVerifiedCaseFor($company),
            'closedExecuted' => $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Executed),
            'closedRejected' => $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Rejected),
        };

        // When the admin attempts to verify it
        $response = $this->verifyCase(
            $this->admin,
            $case->getKey(),
            $this->aValidVerificationPayload($member),
        );

        // Then the refusal names the case's current state and nothing new is recorded
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', 'Only an open ownership-transfer case can be verified; this case is '.$stateName.'.');
        $this->assertNothingVerified($case);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonOpenCaseProvider(): array
    {
        return [
            'already verified' => ['verified', 'verified'],
            'closed executed' => ['closedExecuted', 'closed'],
            'closed rejected' => ['closedRejected', 'closed'],
        ];
    }

    #[Test]
    public function when_the_named_incoming_owner_is_not_an_active_member_then_the_verification_is_refused(): void
    {
        // Given an open case for Contoso and a live member of a DIFFERENT
        // company — membership elsewhere must not satisfy the case's company
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);
        $foreignMember = $this->aCompanyMember(Company::factory()->create(), 'employee');

        // When the admin verifies naming them as the incoming owner
        $response = $this->verifyCase(
            $this->admin,
            $case->getKey(),
            $this->aValidVerificationPayload($foreignMember),
        );

        // Then the verification is refused on the target and nothing is written
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'TARGET_NOT_MEMBER');
        $this->assertNothingVerified($case);
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_whole_verification_rolls_back(): void
    {
        // Given an open case, a live member, and an audit write that fails
        // AFTER its row is inserted — only the transaction can take it back
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company, 'employee');
        $case = $this->anOpenCaseFor($company);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin verifies the case
        $response = $this->verifyCase(
            $this->admin,
            $case->getKey(),
            $this->aValidVerificationPayload($member),
        );

        // Then the whole verification rolls back — no event, no stamp, no evidence
        $this->assertReadFailedSafely($response);
        $this->assertNothingVerified($case);
    }

    #[Test]
    public function when_a_verification_is_recorded_then_the_prose_notes_never_reach_the_event_or_the_audit_row(): void
    {
        // Given an open case, a live member, and an artefact entry smuggling
        // an extra prose key alongside the closed metadata shape
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company, 'employee');
        $case = $this->anOpenCaseFor($company);

        // When the admin verifies with notes and the smuggling entry
        $response = $this->verifyCase(
            $this->admin,
            $case->getKey(),
            $this->aValidVerificationPayload($member, [
                'artefacts' => [[...$this->artefact, 'note' => 'smuggled prose that must not survive']],
            ]),
        );

        // Then the notes are readable only on the case row
        $response->assertOk();
        $this->assertNotesStayOnTheRowOnly($case);
    }

    #[Test]
    public function when_the_admin_verifies_an_open_case_then_the_verification_is_recorded_whole(): void
    {
        // Given an open case with no incoming owner yet (UC-1-140 alt-2a) and
        // Alex, a live member of the case's company
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->anOpenCaseFor($company);

        // When the admin verifies the case naming Alex
        $response = $this->verifyCase(
            $this->admin,
            $case->getKey(),
            $this->aValidVerificationPayload($alex),
        );

        // Then the event, the stamp and the audit row landed together, and the
        // wire carries the verification facts without the prose
        $eventId = $this->assertVerificationEventRecorded($case);
        $this->assertCaseRowStampedVerified($case, $alex, $eventId);
        $this->assertVerificationAudited($case, $eventId, $response);
        $this->assertVerifiedCaseOnTheWire($response, $alex);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidVerificationPayloadProvider(): array
    {
        $capturedAt = '2026-08-09T10:30:00Z';

        return [
            'empty notes' => [['notes' => ''], 'notes'],
            'whitespace notes' => [['notes' => '   '], 'notes'],
            'overlong notes' => [['notes' => str_repeat('a', 1001)], 'notes'],
            'missing artefacts' => [['artefacts' => null], 'artefacts'],
            'empty artefacts' => [['artefacts' => []], 'artefacts'],
            'artefact type outside the seven' => [
                ['artefacts' => [['type' => 'hearsay', 'reference' => 'sworn statement', 'capturedAt' => $capturedAt]]],
                'artefacts.0.type',
            ],
            'artefact missing reference' => [
                ['artefacts' => [['type' => 'id_document', 'capturedAt' => $capturedAt]]],
                'artefacts.0.reference',
            ],
            'missing newOwnerUserId' => [['newOwnerUserId' => null], 'newOwnerUserId'],
            'non-uuid newOwnerUserId' => [['newOwnerUserId' => 'not-a-uuid'], 'newOwnerUserId'],
        ];
    }

    /**
     * Call the REAL admin verify endpoint (UC-1-113) as $caller, or as a
     * guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design (cd-foundation-http §3).
     */
    protected function verifyCase(?User $caller, string $caseId, array $payload = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson('/api/v1/admin/ownership-transfer-cases/'.$caseId.'/verify', $payload);
    }

    /**
     * A shape-valid verify body naming $newOwner (§8.1 camelCase request
     * keys, spec §2 artefact shape); rows override or remove keys to arrange
     * their defect.
     */
    protected function aValidVerificationPayload(User $newOwner, array $overrides = []): array
    {
        return [
            'newOwnerUserId' => $newOwner->getKey(),
            'notes' => $this->notes,
            'artefacts' => [$this->artefact],
            ...$overrides,
        ];
    }

    /**
     * Exactly ONE CompanyOwnershipVerificationRecorded event sits on the
     * case's company stream, its payload pinned to the caseId, the verifying
     * admin and the projected artefact metadata; returns the stored-event id
     * so the row stamp and the audit row can be pinned to the same record.
     */
    protected function assertVerificationEventRecorded(OwnershipTransferCase $case): int
    {
        $event = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', 'App\Companies\Events\CompanyOwnershipVerificationRecorded')
            ->sole();

        $properties = $event->event_properties;
        $this->assertSame($case->getKey(), $properties['caseId']);
        $this->assertSame($this->admin->getKey(), $properties['verifiedBy']);
        $this->assertSame([$this->artefact], $properties['artefacts']);

        return (int) $event->id;
    }

    /**
     * The case row carries the whole verification (UC-1-113 postconditions):
     * status verified, the verifying admin + timestamp, the incoming owner,
     * the notes verbatim, the projected artefact metadata, and the
     * verification_event_id pinned to the stored event just asserted.
     */
    protected function assertCaseRowStampedVerified(OwnershipTransferCase $case, User $newOwner, int $verificationEventId): void
    {
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertSame(OwnershipTransferCaseStatus::Verified, $fresh->status);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($this->admin->getKey(), $fresh->verified_by);
        $this->assertSame($newOwner->getKey(), $fresh->new_owner_user_id);
        $this->assertSame($this->notes, $fresh->verification_notes);
        $this->assertSame([$this->artefact], $fresh->verification_artefacts);
        $this->assertSame($verificationEventId, $fresh->verification_event_id);
    }

    /**
     * Exactly ONE audit row evidences the verification, subject-pinned to the
     * case, stored_event_id pinned to the verification event (the module's
     * FIRST non-null producer — the row references the durable record, never
     * the notes), request-context columns IDENTITY-pinned per the sibling
     * standard.
     */
    protected function assertVerificationAudited(OwnershipTransferCase $case, int $storedEventId, TestResponse $response): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'CompanyOwnershipVerificationRecorded',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->getKey(),
            'subject_label' => null,
            'action' => 'case_verified',
            'description' => 'Platform admin recorded identity verification on an ownership-transfer case.',
            'company_id' => $case->company_id,
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'stored_event_id' => $storedEventId,
            'metadata' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $entry = AuditEntry::query()->sole();
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        $this->assertNotNull($entry->user_agent);
    }

    /**
     * The wire carries the verification facts — 200, status verified, the
     * incoming owner and the verified_at/verified_by stamps — while the
     * notes and artefacts stay off it (the T5 detail-read invariant).
     */
    protected function assertVerifiedCaseOnTheWire(TestResponse $response, User $newOwner): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.status', 'verified');
        $response->assertJsonPath('data.new_owner_user_id', $newOwner->getKey());
        $response->assertJsonPath('data.verified_by', $this->admin->getKey());
        $this->assertNotNull($response->json('data.verified_at'));
        $response->assertJsonMissingPath('data.verification_notes');
        $response->assertJsonMissingPath('data.verification_artefacts');
    }

    /**
     * The prose notes live ONLY on the case row (UC-1-113 sc.2): the stored
     * event's payload key-set is exactly the closed metadata set with every
     * artefact entry projected to {type, reference, capturedAt}, the audit
     * row carries the notes text nowhere, and the row holds them verbatim.
     */
    protected function assertNotesStayOnTheRowOnly(OwnershipTransferCase $case): void
    {
        $event = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->sole();
        $properties = $event->event_properties;
        $this->assertEqualsCanonicalizing(['caseId', 'verifiedBy', 'artefacts', 'verifiedAt'], array_keys($properties));
        foreach ($properties['artefacts'] as $entry) {
            $this->assertEqualsCanonicalizing(['type', 'reference', 'capturedAt'], array_keys($entry));
        }

        $auditRow = AuditEntry::query()->sole();
        $this->assertStringNotContainsString($this->notes, (string) json_encode($auditRow->getAttributes()));

        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertSame($this->notes, $fresh->verification_notes);
    }

    /**
     * The refusal left the case exactly as arranged (whatever state that
     * was), recorded no domain event on the case's company stream, and wrote
     * no audit row — the UC-1-113 fail-closed invariant.
     */
    protected function assertNothingVerified(OwnershipTransferCase $case): void
    {
        $this->assertCaseUnchanged($case);

        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertEquals($case->verified_at, $fresh->verified_at);
        $this->assertSame($case->verification_event_id, $fresh->verification_event_id);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->count());

        $this->assertNoAuditRecorded();
    }
}
