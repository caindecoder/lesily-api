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
