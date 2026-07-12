<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\Events\UserProfileUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    #[Test]
    public function when_no_token_is_provided_then_the_request_is_unauthenticated(): void
    {
        // Given a user exists but no authentication token is presented
        $user = $this->anEstablishedUser();

        // When an otherwise-valid update is sent to PUT /api/v1/me without a token
        $response = $this->updateProfileWithoutAuth(['name' => 'Mallory', 'locale' => 'ta']);

        // Then the Sanctum guard rejects it before any work runs and the record is untouched
        $this->assertUnauthenticated($response);
        $this->assertDatabaseHas('users', $this->unchangedProfileOf($user));
    }

    #[Test]
    public function when_targeting_another_users_profile_then_the_route_does_not_exist(): void
    {
        // Given an authenticated user Alice and another user Bob in the system
        $alice = $this->anEstablishedUser();
        $bob = User::factory()->named('Bob Silva')->locale('ta')->create();

        // When Alice attempts an id-addressed profile update against Bob
        $response = $this->updateAnotherUsersProfile($alice, $bob, ['name' => 'Hijacked', 'locale' => 'en']);

        // Then no such route exists (self-only by construction) and Bob is unchanged
        $response->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $bob->id, 'name' => 'Bob Silva', 'locale' => 'ta']);
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

    // --- Edge ---

    #[Test]
    public function when_identical_values_are_submitted_then_no_event_is_recorded(): void
    {
        // Given an authenticated user with an established profile
        $user = $this->anEstablishedUser();

        // When they submit exactly their current name and locale
        $response = $this->updateProfile($user, [
            'name' => self::CURRENT_NAME,
            'locale' => self::CURRENT_LOCALE,
        ]);

        // Then the request succeeds but records nothing — no event, no row change (the B5 guard)
        $response->assertOk();
        $this->assertNoProfileEventRecorded();
        $this->assertDatabaseHas('users', $this->unchangedProfileOf($user));
    }

    #[Test]
    public function when_the_locale_changes_then_notifications_render_in_the_new_language(): void
    {
        // Given a user whose notification language was English
        Mail::fake();
        $user = $this->anEstablishedUser();

        // When they switch their locale to Sinhala and a system notification then goes out
        $this->updateProfile($user, ['name' => self::CURRENT_NAME, 'locale' => 'si'])->assertOk();
        $user->refresh()->notify(new SystemNotification(NotificationType::GeneralAccountNotice, 'Contoso'));

        // Then the mail leg renders via the NEW language (the HasLocalePreference path)
        Mail::assertQueued(
            SystemNotificationMail::class,
            fn (SystemNotificationMail $mail): bool => $mail->locale === 'si',
        );
    }

    // --- Happy ---

    #[Test]
    public function when_valid_name_and_locale_are_submitted_then_the_profile_is_updated(): void
    {
        // Given a fully-populated user (verified, 2FA-confirmed — the envelope pins) with an established profile
        $user = User::factory()
            ->named(self::CURRENT_NAME)
            ->locale(self::CURRENT_LOCALE)
            ->withTwoFactor()
            ->create();

        // When they submit a new name and notification locale
        $response = $this->updateProfile($user, ['name' => 'Alice Fernando', 'locale' => 'si']);

        // Then the profile is updated everywhere and the write was event-sourced
        $this->assertProfileUpdatedEverywhere($response, $user, ['name' => 'Alice Fernando', 'locale' => 'si']);
        $this->assertProfileUpdateWasEventSourced($user, [
            'name' => 'Alice Fernando',
            'locale' => 'si',
            'changedFields' => ['name', 'locale'],
        ]);
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
     * PUT /me with no token at all — the unauthenticated probe for the
     * update-profile suite (viewProfileWithoutAuth precedent).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateProfileWithoutAuth(array $payload): TestResponse
    {
        return $this->putJson('/api/v1/me', $payload);
    }

    /**
     * Probe the id-addressed profile path that must NOT exist (UC-1-015 Sc2) —
     * cross-user writes are impossible by construction, not by authorization.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function updateAnotherUsersProfile(User $actor, User $target, array $payload): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->putJson("/api/v1/users/{$target->id}/profile", $payload);
    }

    /**
     * The update succeeded end-to-end: the response is EXACTLY the nine-field envelope
     * built from the refreshed row (the promoted leak-guard) AND the row now carries the
     * new values — the value pins go RED if the write never lands (the envelope alone
     * would self-consistently echo the old row).
     *
     * @param  array<string, string>  $expected
     */
    protected function assertProfileUpdatedEverywhere(TestResponse $response, User $user, array $expected): void
    {
        $this->assertExactProfileEnvelope($response, $user);
        $this->assertDatabaseHas('users', ['id' => $user->id, ...$expected]);
    }

    /**
     * A no-op submit must leave the event store silent for this stream — the audit
     * trail records applied changes only (slice-profile B5; an unconditional record
     * would fabricate an audit row for a change that never happened).
     */
    protected function assertNoProfileEventRecorded(): void
    {
        $this->assertSame(
            0,
            LesilyStoredEvent::query()->where('event_class', UserProfileUpdated::class)->count(),
            'An identical submit must record no UserProfileUpdated event.',
        );
    }

    /**
     * The write was event-sourced, not a direct read-model save: exactly one
     * UserProfileUpdated was stored carrying the user id, the new name + locale and the
     * changed-field list (the audit-trail postcondition reads off this row). Goes RED if
     * the interactor writes users directly — the behavioural pins cannot catch that
     * (identical read-model state), yet it would be reverted on a projection replay.
     *
     * @param  array<string, mixed>  $expected
     */
    protected function assertProfileUpdateWasEventSourced(User $user, array $expected): void
    {
        $stored = LesilyStoredEvent::query()->where('event_class', UserProfileUpdated::class)->sole();

        $this->assertSame($user->id, $stored->event_properties['userId']);
        $this->assertSame($expected['name'], $stored->event_properties['name']);
        $this->assertSame($expected['locale'], $stored->event_properties['locale']);
        $this->assertSame($expected['changedFields'], $stored->event_properties['changedFields']);
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
