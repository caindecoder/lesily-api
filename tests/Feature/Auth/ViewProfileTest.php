<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewProfileTest extends AuthTestCase
{
    use RefreshDatabase;

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
        ];
    }

    protected function assertOnlyOwnProfile(TestResponse $response, User $owner, User $other): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.id', $owner->id);
        $response->assertJsonPath('data.email', $owner->email);
        $this->assertStringNotContainsString($other->id, (string) $response->getContent());
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
