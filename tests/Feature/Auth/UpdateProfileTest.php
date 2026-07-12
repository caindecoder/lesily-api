<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpdateProfileTest extends AuthTestCase
{
    use RefreshDatabase;

    // --- Sad ---

    #[Test]
    public function when_the_locale_is_not_supported_then_the_update_is_rejected(): void
    {
        // Given an authenticated user with an established profile
        $user = User::factory()->named('Alice Perera')->locale('en')->create();

        // When they submit an update naming a locale outside the supported set
        $response = $this->updateProfile($user, ['name' => 'Alice Perera', 'locale' => 'fr']);

        // Then the update is rejected as a validation error and the record is unchanged
        $this->assertUpdateRejectedAndProfileUnchanged($response, 'locale', [
            'id' => $user->id,
            'name' => 'Alice Perera',
            'locale' => 'en',
        ]);
    }

    /**
     * PUT the authenticated user's own profile (UC-1-015) — no X-Company-Context,
     * the me/ surface is account-level (viewProfile precedent).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateProfile(User $actor, array $payload): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->putJson('/api/v1/me', $payload);
    }

    /**
     * The update was refused as a 422 §S21 validation error naming the offending
     * field, and the users row still carries its before-update values (the Minimal
     * Guarantee — goes RED if any write leaks past a rejected update).
     *
     * @param  array<string, mixed>  $before
     */
    protected function assertUpdateRejectedAndProfileUnchanged(
        TestResponse $response,
        string $invalidField,
        array $before,
    ): void {
        $this->assertValidationRejected($response, $invalidField);
        $this->assertDatabaseHas('users', $before);
    }
}
