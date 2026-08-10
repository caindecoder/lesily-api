<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class VerifyOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    protected string $notes = 'Death certificate matched the registered Owner';

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
            'artefacts' => [
                [
                    'type' => 'id_document',
                    'reference' => 'NIC 199012345678 sighted via notarised copy',
                    'capturedAt' => '2026-08-09T10:30:00Z',
                ],
            ],
            ...$overrides,
        ];
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
