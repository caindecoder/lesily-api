<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Companies\CompaniesTestCase;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

/**
 * Shared fixtures for the ownership-transfer feature tests (§2.10). Callers
 * compose a company whose factory owner holds the SEEDED owner role (the
 * permission gate reads real grants), then drive the transfer endpoints
 * through the owner's company context.
 */
abstract class OwnershipTransfersTestCase extends CompaniesTestCase
{
    use RefreshDatabase;
    use WithRolePermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeded roles carry the real permission grants the gate reads.
        $this->seedRbacCatalog();
        // Error-path render parity (amendment M): APP_DEBUG=true (the local
        // default) masks the prod render path; rejections must render their
        // mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
        Notification::fake();
    }

    /**
     * The factory company's owner, attached under the SEEDED owner role so the
     * permission gate resolves real grants.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithOwner(): array
    {
        $company = Company::factory()->create();
        $owner = User::query()->findOrFail($company->owner_id);
        $this->attachMember($company, $owner, $this->seededRole('owner'));

        return [$company, $owner];
    }

    /**
     * A fresh user attached to $company under the SEEDED $roleCode.
     */
    protected function aMemberWithRole(Company $company, string $roleCode): User
    {
        $member = User::factory()->create();
        $this->attachMember($company, $member, $this->seededRole($roleCode));

        return $member;
    }

    /**
     * Stamp $member's pivot with emergency-contact data (§S10b shape). The
     * production write path for non-owner members is not built yet (UC-1-029
     * wizard rides T4) — direct pivot data is the sanctioned arrangement (F6).
     */
    protected function withEmergencyContact(Company $company, User $member): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $member->getKey())
            ->update([
                'emergency_contact_name' => 'Nadia Perera',
                'emergency_contact_email' => 'nadia.perera@example.com',
                'emergency_contact_phone' => '+94771234567',
                'emergency_contact_relationship' => 'spouse',
            ]);
    }

    /**
     * A well-formed request body targeting $target (channel `app`).
     *
     * @return array<string, string>
     */
    protected function transferBodyFor(User $target): array
    {
        return [
            'targetUserId' => (string) $target->getKey(),
            'reason' => 'Retiring from the business',
            'requestChannel' => 'app',
        ];
    }

    /**
     * POST the transfer request as $actor (null = unauthenticated) in
     * $company's context. A fresh Idempotency-Key is minted unless the caller
     * pins one (replay tests) or drops it (the required-mount sad path).
     *
     * @param  array<string, mixed>  $body
     */
    protected function postTransferRequest(
        ?User $actor,
        ?Company $company,
        array $body = [],
        bool $withKey = true,
        ?string $key = null,
    ): TestResponse {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        $headers = [];

        if ($company !== null) {
            $headers['X-Company-Context'] = $company->getKey();
        }

        if ($withKey) {
            $headers['Idempotency-Key'] = $key ?? fake()->uuid();
        }

        return $this->postJson('/api/v1/ownership-transfers', $body, $headers);
    }

    /**
     * Assert a request was refused with the §S21 envelope for $code (status +
     * error_code). Mirrors MembershipsTestCase's helper (§2.10).
     */
    protected function assertRefusedWith(TestResponse $response, ApiErrorCode $code): void
    {
        $response->assertStatus($code->status());
        $response->assertJsonPath('error_code', $code->value);
    }

    /**
     * The request was refused as shape-invalid — the validation envelope
     * (error_code VALIDATION_FAILED) with an error on $field.
     */
    protected function assertValidationRejected(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($field);
    }
}
