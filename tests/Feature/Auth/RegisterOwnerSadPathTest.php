<?php

namespace Tests\Feature\Auth;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RegisterOwnerSadPathTest extends AuthTestCase
{
    use RefreshDatabase;

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
