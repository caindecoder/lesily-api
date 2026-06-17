<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EnsureIdempotencyKeyTest extends FoundationTestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function when_required_route_is_called_without_an_idempotency_key_then_the_request_is_rejected_as_missing(): void
    {
        // Given an authenticated user calling a route that requires an Idempotency-Key

        // When the request omits the Idempotency-Key header
        $response = $this->sendRequiredMutation(key: null);

        // Then the request is rejected as missing the required key
        $this->assertRejectedAsMissingKey($response);
    }

    protected function sendRequiredMutation(?string $key = null, array $body = [], ?User $user = null): TestResponse
    {
        Sanctum::actingAs($user ?? $this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/_test/idempotent-required', $body, $headers);
    }

    protected function assertRejectedAsMissingKey(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
