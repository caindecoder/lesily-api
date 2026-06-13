<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RegisterOwnerSadPathTest extends AuthTestCase
{
    use RefreshDatabase;

    private const EXISTING_EMAIL = 'existing@contoso.lk';

    private const EXISTING_BR = 'PV-99999999';

    private const EXISTING_EPF = 'A-9999999';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        $this->travelTo('2026-06-08 00:00:00');
    }

    // Sad (validation) — a malformed payload is rejected before any aggregate runs.
    #[Test]
    #[DataProvider('invalidPayloadProvider')]
    public function when_the_registration_payload_is_invalid_then_it_is_rejected(array $override, string $invalidField): void
    {
        // Given an otherwise-valid signup with one invalid field
        $payload = [...$this->validPayload(), ...$override];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then registration is rejected and the offending field is reported
        $this->assertRejectedValidation($response, $invalidField);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'weak password' => [['password' => 'short'], 'password'],
            'terms not accepted' => [['acceptedTos' => false], 'acceptedTos'],
            'missing name' => [['name' => ''], 'name'],
            'missing email' => [['email' => ''], 'email'],
        ];
    }

    protected function assertRejectedValidation(TestResponse $response, string $invalidField): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($invalidField);
    }

    // Sad (conflict) — a globally-unique identifier already in use is rejected
    // with 409 (well-formed but conflicts with current state — api-conventions §6),
    // and nothing new is persisted.
    #[Test]
    #[DataProvider('duplicateFieldProvider')]
    public function when_a_registration_field_is_already_registered_then_it_conflicts(array $override, string $expectedCode): void
    {
        // Given an account already holds these identifiers
        $this->givenAnExistingOwnerAccount();
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest registers colliding on one of them
        $response = $this->postJson('/api/v1/auth/register', [...$this->validPayload(), ...$override]);

        // Then the registration conflicts and creates nothing new
        $this->assertConflict($response, $expectedCode);
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function duplicateFieldProvider(): array
    {
        return [
            'email' => [['email' => self::EXISTING_EMAIL], 'EMAIL_REGISTERED'],
            'business registration number' => [['brNumber' => self::EXISTING_BR], 'BR_NUMBER_REGISTERED'],
            'employer provident fund number' => [['epfNumber' => self::EXISTING_EPF], 'EPF_NUMBER_REGISTERED'],
        ];
    }

    protected function givenAnExistingOwnerAccount(): void
    {
        User::factory()->create(['email' => self::EXISTING_EMAIL]);
        Company::factory()->create([
            'br_number' => self::EXISTING_BR,
            'epf_number' => self::EXISTING_EPF,
        ]);
    }

    protected function assertConflict(TestResponse $response, string $expectedCode): void
    {
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', $expectedCode);
    }

    protected function assertNoNewAccounts(int $usersBefore, int $companiesBefore): void
    {
        $this->assertSame($usersBefore, User::query()->count(), 'No user may be created on a conflict.');
        $this->assertSame($companiesBefore, Company::query()->count(), 'No company may be created on a conflict.');
    }

    // Edge (atomic rollback) — an unsupported country passes shape validation but
    // throws mid-transaction (after the owner is written), proving the whole
    // registration rolls back as one unit.
    #[Test]
    public function when_an_unsupported_country_is_submitted_then_nothing_is_persisted(): void
    {
        // Given a signup for a country with no seeded defaults
        $payload = [...$this->validPayload(), 'country' => 'ZZ'];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then the request is rejected and the half-built account is rolled back
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'UNSUPPORTED_COUNTRY');
        $this->assertNothingPersisted();
    }

    protected function assertNothingPersisted(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('stored_events', 0);
    }

    // Edge (rate limit) — the public register endpoint runs under the auth-tier
    // per-IP limit (10/min) so credential-stuffing and signup abuse are throttled.
    #[Test]
    public function when_registration_attempts_exceed_the_auth_rate_limit_then_they_are_throttled(): void
    {
        // Given the per-IP auth quota for the minute is exhausted
        $this->exhaustAuthLimit(10);

        // When another registration is attempted
        $response = $this->postJson('/api/v1/auth/register', []);

        // Then it is throttled with retry guidance
        $this->assertThrottled($response);
    }

    protected function exhaustAuthLimit(int $allowed): void
    {
        // Each request up to the limit must pass the throttle (and only then be
        // rejected by validation) — this pins the allowance at exactly $allowed,
        // so an off-by-one on the limit throttles one of these instead.
        for ($i = 0; $i < $allowed; $i++) {
            $this->postJson('/api/v1/auth/register', [])->assertStatus(422);
        }
    }

    protected function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $this->assertGreaterThan(0, (int) $response->json('retry_after'), 'A throttled response must carry retry guidance.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validPayload(): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@contoso.lk',
            'password' => 'S3cure-Pass!',
            'acceptedTos' => true,
            'companyName' => 'Contoso',
            'brNumber' => 'PV-12345678',
            'epfNumber' => 'A-1234567',
            'country' => 'LK',
            'emergencyContactName' => 'Kamala Perera',
            'emergencyContactEmail' => 'kamala@contoso.lk',
            'emergencyContactPhone' => '+94771234567',
            'emergencyContactRelationship' => 'spouse',
        ];
    }
}
