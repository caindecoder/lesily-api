<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'google_id' => null,
            'google_unlinked_at' => null,
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'last_logged_at' => null,
            'default_company_id' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function locale(string $locale): static
    {
        return $this->state(fn (array $attributes): array => [
            'locale' => $locale,
        ]);
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => $password,
        ]);
    }

    public function withEmail(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => $email,
        ]);
    }

    public function linkedToGoogle(string $googleId = 'google-subject-linked'): static
    {
        return $this->state(fn (array $attributes): array => [
            'google_id' => $googleId,
        ]);
    }

    public function unlinkedFromGoogle(): static
    {
        return $this->state(fn (array $attributes): array => [
            'google_id' => null,
            'google_unlinked_at' => now(),
        ]);
    }

    /**
     * A deterministic display name — for roster ordering/search assertions
     * (UC-1-017) where faker's random names would make expectations flaky.
     */
    public function named(string $name): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
        ]);
    }

    /**
     * A user with 2FA already enabled — secret + hashed recovery codes + a confirmed
     * timestamp, all generated at runtime (no literal secrets, per SonarCloud). Pass
     * $recoveryCodes to hash known plaintext codes (the recovery-path verify tests need
     * a code they can actually submit); null generates 10 random codes; [] leaves none.
     *
     * @param  list<string>|null  $recoveryCodes
     */
    public function withTwoFactor(?array $recoveryCodes = null): static
    {
        $codes = $recoveryCodes ?? array_map(static fn (): string => Str::random(10), range(1, 10));

        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => Str::random(32),
            'two_factor_recovery_codes' => array_map(Hash::make(...), $codes),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A secret on the row while two_factor_confirmed_at stays null — an INTENTIONALLY
     * SYNTHETIC state, not a real enrollment stage: production keeps the two columns in
     * lockstep (enable stages the secret in cache only; confirm and disable each move
     * secret + confirmed_at atomically). It exists purely to pin the derivation contract
     * (two_factor_enabled follows confirmed_at, never secret-presence) against drift
     * toward Fortify's stock secret-presence convention.
     */
    public function withUnconfirmedTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => Str::random(32),
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * A user whose last successful login is stamped — for profile assertions
     * that need a non-null last_logged_at without driving the login seam.
     */
    public function lastLoggedAt(string $when): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_logged_at' => $when,
        ]);
    }
}
