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
            'remember_token' => Str::random(10),
            'locale' => 'en',
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
}
