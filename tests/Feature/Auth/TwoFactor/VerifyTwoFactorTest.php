<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Auth\Events\User2FARecoveryCodeUsed;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class VerifyTwoFactorTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('provideInvalidVerifyPayloads')]
    public function when_required_fields_are_missing_then_the_request_is_rejected(array $payload, string $invalidField): void
    {
        // Given an invalid verify payload (a required field missing or blank)
        // When it is submitted to the verify endpoint
        $response = $this->postJson('/api/v1/auth/2fa/verify', $payload);

        // Then the request is rejected as a validation error naming the field
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_the_challenge_is_unknown_or_expired_then_verification_is_refused(): void
    {
        // Given no live challenge for the submitted id (never created, or expired)
        // When a verify is attempted against it
        $response = $this->verify('not-a-live-challenge', '123456');

        // Then verification is refused as a missing challenge and no token is issued
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorChallengeNotFound);
        $response->assertStatus(404); // literal pins the 404 contract independent of the enum
        $response->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function when_an_invalid_code_is_submitted_then_verification_is_refused(): void
    {
        // Given a 2FA user with an open challenge and an authenticator that rejects the code
        $user = $this->aTwoFactorUser();
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: false);

        // When a code matching neither the TOTP nor any recovery code is submitted
        $response = $this->verify($challengeId, 'wrong-code');

        // Then verification is refused, no token is issued, and the attempt is counted
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorVerificationFailed);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $this->assertNoTokenMinted($response, $user);
        $this->assertSame(
            LoginChallengeStore::MAX_ATTEMPTS - 1,
            app(LoginChallengeStore::class)->find($challengeId)?->attemptsRemaining,
            'A failed verify must count one attempt against the challenge.',
        );
    }

    #[Test]
    public function when_the_attempt_allowance_is_exhausted_then_the_challenge_is_abandoned(): void
    {
        // Given a 2FA user with an open challenge and an authenticator that always rejects
        $user = $this->aTwoFactorUser();
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: false);

        // When the full attempt allowance is spent on invalid codes
        for ($attempt = 0; $attempt < LoginChallengeStore::MAX_ATTEMPTS; $attempt++) {
            $this->verify($challengeId, 'wrong-code')
                ->assertStatus(ApiErrorCode::TwoFactorVerificationFailed->status());
        }

        // Then the challenge is abandoned — a further attempt is refused as missing, not invalid
        $this->assertRefusedWith($this->verify($challengeId, 'wrong-code'), ApiErrorCode::TwoFactorChallengeNotFound);
    }

    #[Test]
    public function when_a_consumed_recovery_code_is_reused_then_verification_is_refused(): void
    {
        // Given a 2FA user who has already spent a recovery code on a prior verify
        $user = $this->aTwoFactorUser(['ONCE-ONLY', 'KEEP-CODE']);
        $this->fakeTotpVerifier(verifies: false);
        $this->verify($this->openChallenge($user), 'ONCE-ONLY')->assertOk();

        // When the same code is presented again on a fresh challenge
        $response = $this->verify($this->openChallenge($user), 'ONCE-ONLY');

        // Then it is refused — a single-use code cannot authenticate twice
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorVerificationFailed);
        $response->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function when_a_valid_totp_code_is_submitted_then_a_session_token_is_issued(): void
    {
        // Given a 2FA user with an open challenge and an authenticator that accepts the code
        $user = $this->aTwoFactorUser();
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: true);

        // When the current TOTP code is submitted
        $response = $this->verify($challengeId, '123456');

        // Then a working session token is issued and no domain event is recorded (token-only)
        $this->assertSessionTokenIssued($response, $user);
        $this->assertNoEventRecorded();
    }

    // Edge — completing 2FA mints the session, so the device name + "last seen" stamp
    // apply here too (the cap/naming/stamp are enforced at the mint, sessions slice).
    #[Test]
    public function when_a_two_factor_verify_completes_then_the_token_is_named_for_the_device_and_last_logged_at_is_stamped(): void
    {
        // Given a 2FA user with an open challenge and an accepting authenticator (clock frozen)
        $this->travelTo('2026-06-23 00:00:00');
        $user = $this->aTwoFactorUser();
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: true);

        // When they verify, naming their device
        $this->withHeader('User-Agent', 'Mozilla/5.0 (TestAgent)')
            ->postJson('/api/v1/auth/2fa/verify', [
                'challenge_id' => $challengeId,
                'code' => '123456',
                'deviceName' => 'Alice iPhone',
            ])->assertOk();

        // Then the issued token carries the device name and last_logged_at is stamped
        $this->assertTokenNamed($user, 'Alice iPhone');
        $this->assertLastLoggedAtStamped($user);
    }

    // Edge — completing 2FA via a RECOVERY code (the distinct lost-authenticator branch)
    // also names the token + stamps "last seen". Both factors route the mint through the
    // shared issueSession, but the recovery branch deserves its own oracle so a future
    // divergence of the two branches can't regress recovery logins to unnamed tokens.
    #[Test]
    public function when_a_recovery_code_verify_completes_then_the_token_is_named_for_the_device_and_last_logged_at_is_stamped(): void
    {
        // Given a 2FA user holding a known recovery code, an open challenge, a non-matching TOTP (clock frozen)
        $this->travelTo('2026-06-23 00:00:00');
        $user = $this->aTwoFactorUser(['HAPY-CODE', 'KEEP-CODE']);
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: false);

        // When they complete 2FA via the recovery code, naming their device
        $this->withHeader('User-Agent', 'Mozilla/5.0 (TestAgent)')
            ->postJson('/api/v1/auth/2fa/verify', [
                'challenge_id' => $challengeId,
                'code' => 'HAPY-CODE',
                'deviceName' => 'Alice iPhone',
            ])->assertOk();

        // Then the issued token carries the device name and last_logged_at is stamped
        $this->assertTokenNamed($user, 'Alice iPhone');
        $this->assertLastLoggedAtStamped($user);
    }

    #[Test]
    public function when_a_valid_recovery_code_is_used_then_a_session_token_is_issued_and_the_code_is_consumed(): void
    {
        // Given a 2FA user holding known recovery codes, an open challenge, and a TOTP that won't match
        $user = $this->aTwoFactorUser(['HAPY-CODE', 'KEEP-CODE']);
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: false);

        // When one recovery code is submitted
        $response = $this->verify($challengeId, 'HAPY-CODE');

        // Then a token is issued, that code is consumed (single-use), and a marker is recorded
        $this->assertSessionTokenIssued($response, $user);
        $this->assertRecoveryCodeConsumed($user, 'HAPY-CODE', expectedRemaining: 1);
        $this->assertRecoveryCodeUsedRecorded($user, remaining: 1);
    }

    #[Test]
    public function when_no_recovery_codes_remain_then_a_recovery_attempt_is_refused(): void
    {
        // Given a 2FA user who has exhausted every recovery code (none remain)
        $user = $this->aTwoFactorUser([]);
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: false);

        // When they submit a recovery code (the lost-authenticator / support path)
        $response = $this->verify($challengeId, 'ANY-CODE');

        // Then it is refused — there is nothing left to match
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorVerificationFailed);
        $response->assertJsonMissingPath('data.token');
    }

    #[Test]
    public function when_verification_is_attempted_past_the_rate_limit_then_it_is_throttled(): void
    {
        // Given the shared per-IP auth-tier allowance is exhausted against the verify endpoint
        $this->exhaustAuthLimit(10, '/api/v1/auth/2fa/verify');

        // When one more verify is attempted
        $response = $this->postJson('/api/v1/auth/2fa/verify', []);

        // Then it is throttled — the brute-force surface is rate-limited
        $this->assertThrottled($response);
    }

    // Edge — the device cap is enforced at the 2FA-verify mint site too: a Pro member
    // already at three active devices is refused a fourth even with a valid second factor
    // (UC-1-002 alt-5a). The guard runs before the factor is consumed.
    #[Test]
    public function when_a_capped_account_completes_2fa_on_a_4th_device_then_it_is_rejected(): void
    {
        // Given a Pro 2FA member at three active devices, with an open challenge and an
        // accepting authenticator
        $user = $this->aTwoFactorUser();
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: true);

        // When they complete 2FA from a fourth device
        $response = $this->verify($challengeId, '123456');

        // Then it is rejected, no fourth session is minted, and the challenge survives — the
        // guard fires before the factor is consumed, so it stays re-verifiable once a device is freed
        $this->assertDeviceLimitRejected($response, $user, 3);
        $this->assertNotNull(app(LoginChallengeStore::class)->find($challengeId), 'A capped rejection must not consume the challenge.');
    }

    // Edge — a capped rejection at verify must not BURN the second factor: a Pro member at the
    // device limit who submits a VALID recovery code is rejected with both codes intact — the
    // guard fires before consumption, so the one-time code is not spent (UC-1-002 alt-5a).
    #[Test]
    public function when_a_capped_account_submits_a_recovery_code_then_it_is_rejected_without_burning_the_code(): void
    {
        // Given a capped Pro 2FA member, a non-matching TOTP, and a valid recovery code
        $user = $this->aTwoFactorUser(['HAPY-CODE', 'KEEP-CODE']);
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');
        $challengeId = $this->openChallenge($user);
        $this->fakeTotpVerifier(verifies: false); // force the recovery branch

        // When they verify with a valid recovery code while at the device cap
        $response = $this->verify($challengeId, 'HAPY-CODE');

        // Then it is rejected AND both recovery codes survive (the cap fires before consumption)
        $this->assertDeviceLimitRejected($response, $user, 3);
        $this->assertCount(2, $user->refresh()->two_factor_recovery_codes ?? [], 'A capped rejection must not burn a recovery code.');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidVerifyPayloads(): array
    {
        return [
            'missing challenge id' => [['code' => '123456'], 'challenge_id'],
            'missing code' => [['challenge_id' => 'a-challenge-id'], 'code'],
            'blank code' => [['challenge_id' => 'a-challenge-id', 'code' => ''], 'code'],
        ];
    }

    protected function assertNoTokenMinted(TestResponse $response, User $user): void
    {
        $response->assertJsonMissingPath('data.token');
        $this->assertSame(0, $user->tokens()->count(), 'No token may be minted on a failed verify.');
    }

    protected function assertSessionTokenIssued(TestResponse $response, User $user): void
    {
        $response->assertOk();
        $token = (string) $response->json('data.token');
        $this->assertNotEmpty($token, 'A completed 2FA verify must issue a session token.');

        // forgetGuards so the probe re-resolves the new token from scratch — the
        // container-cached guard would otherwise answer from the test's prior state.
        Auth::forgetGuards();
        $probe = $this->withToken($token)->getJson('/api/_test/authenticated-probe');
        $probe->assertOk();
        $probe->assertJsonPath('user_id', $user->id);
    }

    protected function assertNoEventRecorded(): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->count(), 'A TOTP verify is token-only — it records no domain event.');
    }

    protected function assertRecoveryCodeConsumed(User $user, string $usedCode, int $expectedRemaining): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail()->two_factor_recovery_codes ?? [];
        $this->assertCount($expectedRemaining, $stored, 'The consumed code must be removed, leaving the others.');
        foreach ($stored as $hash) {
            $this->assertFalse(Hash::check($usedCode, $hash), 'A consumed recovery code must no longer be stored.');
        }
    }

    protected function assertRecoveryCodeUsedRecorded(User $user, int $remaining): void
    {
        // sole() enforces exactly-one marker (throws otherwise) and is non-null, so the
        // property reads below need no nullsafe (mirrors EnableTwoFactorTest).
        $properties = LesilyStoredEvent::query()
            ->where('event_class', User2FARecoveryCodeUsed::class)
            ->sole()
            ->event_properties;
        $this->assertSame($user->id, $properties['userId'] ?? null, 'The marker must be scoped to the verifying user.');
        $this->assertSame($remaining, $properties['remaining'] ?? null, 'The marker must carry the codes still usable.');
    }
}
