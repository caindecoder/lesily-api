<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OpenOwnershipTransferCaseTest extends PlatformAdminTestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seedRbacCatalog();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->allowlist([$this->admin->getKey()]);
    }

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_open_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a tenant Owner outside the platform-admin allowlist
        $owner = $this->aCompanyMember($this->company, 'owner');

        // When they call the open-case endpoint with a valid payload
        $response = $this->openCase($owner, $this->validPayload());

        // Then the admin surface is never acknowledged and nothing is written
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoCaseOpened();
    }

    #[Test]
    #[DataProvider('invalidCasePayloadProvider')]
    public function when_the_case_payload_is_invalid_then_the_case_is_refused(array $overrides, string $field): void
    {
        // Given an allowlisted platform admin
        // When they submit a payload carrying this row's defect
        $response = $this->openCase($this->admin, $this->validPayload($overrides));

        // Then the case is refused naming the offending field and nothing is written
        $this->assertValidationFailed($response, $field);
        $this->assertNoCaseOpened();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCasePayloadProvider(): array
    {
        return [
            'missing companyId' => [['companyId' => null], 'companyId'],
            'unknown companyId' => [['companyId' => '01980000-0000-7000-8000-000000000000'], 'companyId'],
            'empty requestedContext' => [['requestedContext' => ''], 'requestedContext'],
            'overlong requestedContext' => [['requestedContext' => str_repeat('a', 1001)], 'requestedContext'],
            'malformed newOwnerUserId' => [['newOwnerUserId' => 'not-a-uuid'], 'newOwnerUserId'],
        ];
    }

    /**
     * Call the REAL admin open-case endpoint (UC-1-140) as $caller, or as a
     * guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design, with no auth.company
     * in its chain (cd-foundation-http §3).
     */
    protected function openCase(?User $caller, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->postJson('/api/v1/admin/ownership-transfer-cases', $body);
    }

    /**
     * A shape-valid open-case body for the shared company; rows override or
     * remove keys to arrange their defect.
     */
    protected function validPayload(array $overrides = []): array
    {
        return [
            'companyId' => $this->company->getKey(),
            'requestedContext' => 'Owner deceased; brother called support claiming next-of-kin, death certificate offered.',
            ...$overrides,
        ];
    }

    /**
     * Nothing was opened — zero case rows and zero audit rows (the UC-1-140
     * refusal invariant: no case without its audit evidence, no evidence
     * without a case).
     */
    protected function assertNoCaseOpened(): void
    {
        $this->assertDatabaseCount('ownership_transfer_cases', 0);
        $this->assertNoAuditRecorded();
    }
}
