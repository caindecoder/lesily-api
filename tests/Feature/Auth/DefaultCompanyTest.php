<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UC-1-139 Set Default Company (slice-companies Task 2) — the persisted
 * default-company preference behind PUT/DELETE /api/v1/me/default-company:
 * a plain users.default_company_id write (NOT event-sourced — B2), guarded
 * to live memberships only, collapsed to 422 on any non-live target (no
 * company-id-existence leak), idempotent on both verbs.
 */
class DefaultCompanyTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
    }

    #[Test]
    public function when_setting_default_without_token_then_unauthenticated(): void
    {
        // Given no authenticated session

        // When
        $response = $this->putJson('/api/v1/me/default-company', [
            'companyId' => Str::uuid7()->toString(),
        ]);

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_clearing_default_without_token_then_unauthenticated(): void
    {
        // Given no authenticated session

        // When
        $response = $this->deleteJson('/api/v1/me/default-company');

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test, DataProvider('malformedCompanyIdProvider')]
    public function when_company_id_is_missing_or_malformed_then_set_refused(array $payload): void
    {
        // Given a member with a live membership (the guard must never be reached)
        $user = $this->aRegisteredUser();
        $this->aMembershipFor($user, Company::factory()->named('Home')->create());

        // When
        $response = $this->setDefaultCompany($user, $payload);

        // Then
        $this->assertSetRefusedLeavingDefault($response, $user, null);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedCompanyIdProvider(): array
    {
        return [
            'missing' => [[]],
            'not a string' => [['companyId' => 123]],
            'not a uuid' => [['companyId' => 'not-a-uuid']],
        ];
    }

    #[Test, DataProvider('nonLiveMembershipProvider')]
    public function when_target_company_is_not_a_live_membership_then_set_refused(string $case): void
    {
        // Given a member of Home whose target company is not a live membership
        $user = $this->aRegisteredUser();
        $this->aMembershipFor($user, Company::factory()->named('Home')->create());
        $targetId = match ($case) {
            'not a member' => Company::factory()->named('Other')->create()->getKey(),
            'unknown company id' => Str::uuid7()->toString(),
            'removed membership' => tap(
                Company::factory()->named('Other')->create(),
                fn (Company $other) => $this->aRemovedMembershipIn($other, $user),
            )->getKey(),
        };

        // When
        $response = $this->setDefaultCompany($user, ['companyId' => $targetId]);

        // Then — the same collapsed envelope for every variant (no existence leak)
        $this->assertSetRefusedLeavingDefault($response, $user, null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLiveMembershipProvider(): array
    {
        return [
            'not a member' => ['not a member'],
            'unknown company id' => ['unknown company id'],
            'removed membership' => ['removed membership'],
        ];
    }

    /**
     * The refused set: the §S21 422 VALIDATION_FAILED envelope keyed on companyId
     * (collapsed — no company-id-existence leak) AND the stored default untouched
     * (UC-1-139 Minimal Guarantee).
     */
    protected function assertSetRefusedLeavingDefault(TestResponse $response, User $user, ?string $expectedDefault): void
    {
        $this->assertValidationRejected($response, 'companyId');
        $this->assertSame($expectedDefault, $user->refresh()->default_company_id);
    }
}
