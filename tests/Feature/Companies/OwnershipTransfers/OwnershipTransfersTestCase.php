<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Companies\CompaniesTestCase;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\WithRolePermissionFixtures;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

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
     * A platform support admin — a user on the `platform.admin_user_ids`
     * allowlist (the interim s136 binding the support-queue notification
     * resolves recipients from).
     */
    protected function aPlatformSupportAdmin(): User
    {
        $admin = User::factory()->create();
        config(['platform.admin_user_ids' => [$admin->getKey()]]);

        return $admin;
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

    private ?string $pinnedIdempotencyKey = null;

    /**
     * Pin the Idempotency-Key the next postTransferRequest() sends — replay
     * tests pin one key across two posts; unpinned posts mint a fresh key.
     */
    protected function pinIdempotencyKey(string $key): void
    {
        $this->pinnedIdempotencyKey = $key;
    }

    /**
     * POST the transfer request as $actor in $company's context, with the
     * pinned Idempotency-Key or a freshly minted one.
     *
     * @param  array<string, mixed>  $body
     */
    protected function postTransferRequest(User $actor, Company $company, array $body = []): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/ownership-transfers', $body, [
            'X-Company-Context' => $company->getKey(),
            'Idempotency-Key' => $this->pinnedIdempotencyKey ?? fake()->uuid(),
        ]);
    }

    /**
     * POST with no session at all — the unauthenticated sad path.
     */
    protected function postTransferRequestAsGuest(): TestResponse
    {
        return $this->postJson('/api/v1/ownership-transfers', [], [
            'Idempotency-Key' => fake()->uuid(),
        ]);
    }

    /**
     * POST without an Idempotency-Key — the required-mount sad path.
     *
     * @param  array<string, mixed>  $body
     */
    protected function postTransferRequestWithoutKey(User $actor, Company $company, array $body): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/ownership-transfers', $body, [
            'X-Company-Context' => $company->getKey(),
        ]);
    }

    /**
     * GET the transfer history (list) as $actor in $company's context, with an
     * optional whitelisted query (`state`/`sort`/`page`/`per_page`).
     *
     * @param  array<string, mixed>  $query
     */
    protected function getTransferHistory(User $actor, Company $company, array $query = []): TestResponse
    {
        Sanctum::actingAs($actor);
        $url = '/api/v1/ownership-transfers'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->getJson($url, ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * GET the transfer history (list) with no session at all — the
     * unauthenticated sad path.
     */
    protected function getTransferHistoryAsGuest(): TestResponse
    {
        return $this->getJson('/api/v1/ownership-transfers');
    }

    /**
     * A persisted transfer row for $company targeting $target in $state (the
     * company's factory owner is the initiator). The write endpoints that
     * PRODUCE the terminal states land in later slice tasks; a read fixture
     * arranges the projection directly.
     */
    protected function aTransferInState(Company $company, User $target, OwnershipTransferStatus $state): OwnershipTransferRequest
    {
        $factory = OwnershipTransferRequest::factory();
        $factory = match ($state) {
            OwnershipTransferStatus::Requested => $factory->requested(),
            OwnershipTransferStatus::Verified => $factory->verified(),
            OwnershipTransferStatus::Executed => $factory->executed(),
            OwnershipTransferStatus::Rejected => $factory->rejected(),
            OwnershipTransferStatus::Cancelled => $factory->cancelled(),
            OwnershipTransferStatus::Expired => $factory->expired(),
        };

        return $factory->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $company->owner_id,
            'target_user_id' => $target->getKey(),
        ]);
    }

    /**
     * A `requested` transfer for $company targeting $target, anchored at a
     * specific $requestedAt (drives newest-first ordering assertions).
     */
    protected function aTransferRequestedAt(Company $company, User $target, CarbonImmutable $requestedAt): OwnershipTransferRequest
    {
        return OwnershipTransferRequest::factory()->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $company->owner_id,
            'target_user_id' => $target->getKey(),
            'requested_at' => $requestedAt,
            'next_state_transition_at' => $requestedAt->addDays(30),
        ]);
    }

    /**
     * The list envelope's `meta.pagination` block equals $expected exactly.
     *
     * @param  array<string, int>  $expected
     */
    protected function assertPaginationMeta(TestResponse $response, array $expected): void
    {
        $this->assertSame($expected, $response->json('meta.pagination'));
    }

    /**
     * The list `data` rows appear in exactly $idsInOrder (order-sensitive).
     *
     * @param  list<string>  $idsInOrder
     */
    protected function assertHistoryOrder(TestResponse $response, array $idsInOrder): void
    {
        $this->assertSame($idsInOrder, $response->collect('data')->pluck('id')->all());
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
