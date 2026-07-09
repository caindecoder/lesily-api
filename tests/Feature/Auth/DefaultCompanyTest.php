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

    #[Test]
    public function when_clearing_with_no_default_set_then_clear_still_succeeds(): void
    {
        // Given a user with no default company set
        $user = $this->aRegisteredUser();

        // When
        $response = $this->clearDefaultCompany($user);

        // Then — idempotent: 204 even though nothing was set (UC-1-139 alt-1a)
        $this->assertDefaultStored($response, $user, null);
    }

    #[Test]
    public function when_setting_the_same_default_again_then_replace_is_idempotent(): void
    {
        // Given a member whose default is already Home (set through the endpoint)
        $user = $this->aRegisteredUser();
        $home = Company::factory()->named('Home')->create();
        $this->aMembershipFor($user, $home);
        $this->setDefaultCompany($user, ['companyId' => $home->getKey()]);

        // When the same default is set again
        $response = $this->setDefaultCompany($user, ['companyId' => $home->getKey()]);

        // Then — the idempotent replace: same 204, same stored value
        $this->assertDefaultStored($response, $user, $home->getKey());
    }

    #[Test]
    public function when_setting_a_new_default_then_it_replaces_the_previous_one(): void
    {
        // Given a member of Acme and Beta whose default is Acme
        $user = $this->aRegisteredUser();
        $acme = Company::factory()->named('Acme')->create();
        $beta = Company::factory()->named('Beta')->create();
        $this->aMembershipFor($user, $acme);
        $this->aMembershipFor($user, $beta);
        $this->setDefaultCompany($user, ['companyId' => $acme->getKey()]);

        // When Beta is set as the default
        $response = $this->setDefaultCompany($user, ['companyId' => $beta->getKey()]);

        // Then — one default per user by construction: Beta replaced Acme
        $this->assertDefaultStored($response, $user, $beta->getKey());
    }

    /**
     * The successful write oracle: the 204 AND the stored users.default_company_id
     * value (set target or null after clear).
     */
    protected function assertDefaultStored(TestResponse $response, User $user, ?string $companyId): void
    {
        $response->assertNoContent();
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'default_company_id' => $companyId,
        ]);
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
