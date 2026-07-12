<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpdateProfileTest extends AuthTestCase
{
    use RefreshDatabase;

    /**
     * The established profile every scenario starts from — rejected updates must
     * leave the row exactly here (the Minimal Guarantee).
     */
    protected const CURRENT_NAME = 'Alice Perera';

    protected const CURRENT_LOCALE = 'en';

    // --- Sad ---

    #[Test]
    public function when_the_locale_is_not_supported_then_the_update_is_rejected(): void
    {
        // Given an authenticated user with an established profile
        $user = $this->anEstablishedUser();

        // When they submit an update naming a locale outside the supported set
        $response = $this->updateProfile($user, ['name' => self::CURRENT_NAME, 'locale' => 'fr']);

        // Then the update is rejected as a validation error and the record is unchanged
        $this->assertUpdateRejectedAndProfileUnchanged($response, 'locale', $this->unchangedProfileOf($user));
    }

    #[Test]
    #[DataProvider('provideInvalidUpdateProfilePayloads')]
    public function when_the_payload_is_invalid_then_the_update_is_rejected_and_the_record_is_unchanged(
        array $payload,
        string|array $invalidFields,
    ): void {
        // Given an authenticated user with an established profile
        $user = $this->anEstablishedUser();

        // When they submit an update payload that breaks a field rule
        $response = $this->updateProfile($user, $payload);

        // Then the update is rejected naming the offending field(s) and the record is unchanged
        $this->assertUpdateRejectedAndProfileUnchanged($response, $invalidFields, $this->unchangedProfileOf($user));
    }

    /**
     * @return array<string, array{array<string, mixed>, string|array<int, string>}>
     */
    public static function provideInvalidUpdateProfilePayloads(): array
    {
        return [
            'missing name' => [['locale' => self::CURRENT_LOCALE], 'name'],
            'null name' => [['name' => null, 'locale' => self::CURRENT_LOCALE], 'name'],
            'non-string name' => [['name' => 12345, 'locale' => self::CURRENT_LOCALE], 'name'],
            'one-character name' => [['name' => 'A', 'locale' => self::CURRENT_LOCALE], 'name'],
            'name over the length cap' => [['name' => str_repeat('a', 256), 'locale' => self::CURRENT_LOCALE], 'name'],
            'missing locale (the full-PUT pin)' => [['name' => self::CURRENT_NAME], 'locale'],
            'null locale' => [['name' => self::CURRENT_NAME, 'locale' => null], 'locale'],
            'empty locale' => [['name' => self::CURRENT_NAME, 'locale' => ''], 'locale'],
            'non-string locale' => [['name' => self::CURRENT_NAME, 'locale' => 123], 'locale'],
            'empty payload' => [[], ['name', 'locale']],
        ];
    }

    /**
     * Arrange the suite's canonical user: an established profile at
     * CURRENT_NAME / CURRENT_LOCALE.
     */
    protected function anEstablishedUser(): User
    {
        return User::factory()->named(self::CURRENT_NAME)->locale(self::CURRENT_LOCALE)->create();
    }

    /**
     * The users-row shape a rejected or no-op update must leave untouched.
     *
     * @return array<string, string>
     */
    protected function unchangedProfileOf(User $user): array
    {
        return ['id' => $user->id, 'name' => self::CURRENT_NAME, 'locale' => self::CURRENT_LOCALE];
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
     * field(s), and the users row still carries its before-update values (the Minimal
     * Guarantee — goes RED if any write leaks past a rejected update).
     *
     * @param  string|array<int, string>  $invalidFields
     * @param  array<string, mixed>  $before
     */
    protected function assertUpdateRejectedAndProfileUnchanged(
        TestResponse $response,
        string|array $invalidFields,
        array $before,
    ): void {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($invalidFields);
        $this->assertDatabaseHas('users', $before);
    }
}
