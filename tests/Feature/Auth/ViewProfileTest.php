<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class ViewProfileTest extends AuthTestCase
{
    #[Test]
    public function when_no_token_provided_then_request_is_unauthenticated(): void
    {
        // Given no authentication token is provided

        // When a request is sent to GET /api/v1/me
        $response = $this->viewProfileWithoutAuth();

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_another_user_exists_then_only_own_profile_is_returned(): void
    {
        // Given an authenticated user Alice and another user Bob in the system
        $alice = User::factory()->named('Alice')->create();
        $bob = User::factory()->named('Bob')->create();

        // When Alice requests GET /api/v1/me
        $response = $this->viewProfile($alice);

        // Then the response contains only Alice's data and no field references Bob
        $this->assertOnlyOwnProfile($response, $alice, $bob);
    }

    #[Test]
    #[DataProvider('provideAccountStateVariations')]
    public function when_account_state_varies_then_derived_fields_reflect_it(
        string $state,
        string $field,
        mixed $expected,
    ): void {
        // Given a user whose account is in the given state
        $user = match ($state) {
            'unverified email' => User::factory()->unverified()->create(),
            '2fa enrolled but unconfirmed' => User::factory()->withUnconfirmedTwoFactor()->create(),
            'sinhala locale' => User::factory()->locale('si')->create(),
            default => User::factory()->create(),
        };

        // When the user requests GET /api/v1/me
        $response = $this->viewProfile($user);

        // Then the derived profile field reflects that state
        $this->assertProfileFieldEquals($response, $field, $expected);
    }

    /**
     * @return array<string, array{string, string, mixed}>
     */
    public static function provideAccountStateVariations(): array
    {
        return [
            'unverified email is not flagged verified' => ['unverified email', 'email_verified', false],
            '2FA never enrolled is not enabled' => ['plain', 'two_factor_enabled', false],
            '2FA enrolled but unconfirmed is not enabled' => ['2fa enrolled but unconfirmed', 'two_factor_enabled', false],
            'no default company yields null' => ['plain', 'default_company_id', null],
            'never logged in yields null' => ['plain', 'last_logged_at', null],
            'non-english locale passes through untranslated' => ['sinhala locale', 'locale', 'si'],
        ];
    }

    #[Test]
    public function when_default_company_membership_was_removed_then_default_company_is_absent(): void
    {
        // Given a user whose default company membership was later removed
        $this->seedRbacCatalog();
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $this->aMembershipFor($user, $company);
        $this->setDefaultCompany($user, ['companyId' => $company->getKey()])->assertNoContent();
        $this->removeMembership($company, $user);

        // When the user requests GET /api/v1/me
        $response = $this->viewProfile($user);

        // Then the stale default company is not advertised
        $this->assertProfileFieldEquals($response, 'default_company_id', null);
    }

    #[Test]
    public function when_authenticated_user_views_profile_then_full_profile_is_returned(): void
    {
        // Given a fully-populated user: verified email, 2FA confirmed, a live default company, a stamped last login
        $this->seedRbacCatalog();
        $user = User::factory()
            ->named('Alice Perera')
            ->locale('en')
            ->withTwoFactor()
            ->lastLoggedAt('2026-07-10 09:30:00')
            ->create();
        $company = Company::factory()->create();
        $this->aMembershipFor($user, $company);
        $this->setDefaultCompany($user, ['companyId' => $company->getKey()])->assertNoContent();

        // When the user requests GET /api/v1/me
        $response = $this->viewProfile($user);

        // Then the response is exactly the nine-field profile envelope — nothing more (the leak-guard)
        $this->assertExactProfileEnvelope($response, $user);
    }

    protected function assertOnlyOwnProfile(TestResponse $response, User $owner, User $other): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.id', $owner->getKey());
        $response->assertJsonPath('data.email', $owner->email);
        $this->assertStringNotContainsString($other->getKey(), (string) $response->getContent());
        $this->assertStringNotContainsString($other->email, (string) $response->getContent());
    }

    /**
     * The field must be PRESENT with exactly the expected value — assertJsonPath
     * alone would false-pass a null expectation against an absent key.
     */
    protected function assertProfileFieldEquals(TestResponse $response, string $field, mixed $expected): void
    {
        $response->assertOk();

        /** @var array<string, mixed> $data */
        $data = $response->json('data');
        $this->assertArrayHasKey($field, $data);
        $this->assertSame($expected, $data[$field]);
    }
}
