<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Auth\Events\User2FAEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class UserAggregateTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The genesis persist fires the welcome + verification reactors; spy them
        // at the boundary so this aggregate test stays about the re-fire guard.
        Notification::fake();
    }

    // Sad — an owner can only sign up once: replaying the genesis on an existing user is rejected.
    #[Test]
    public function when_a_user_genesis_is_replayed_then_the_second_sign_up_is_rejected(): void
    {
        // Given a user who already signed up as owner
        $userId = (string) Str::uuid();
        $companyId = (string) Str::uuid();
        $command = $this->buildRegisterOwnerCommand();
        User::retrieve($userId)->createOwner($companyId, $command)->persist();

        // When the same user signs up again, Then it is rejected
        $this->expectException(UserAlreadyRegisteredException::class);

        User::retrieve($userId)->createOwner($companyId, $command);
    }

    // Sad — 2FA confirm is idempotent on the aggregate: re-confirming records no second marker.
    #[Test]
    public function when_two_factor_is_already_confirmed_then_re_confirming_records_no_further_change(): void
    {
        // Given a user who has already confirmed 2FA
        $userId = (string) Str::uuid();
        User::retrieve($userId)->confirmTwoFactor()->persist();

        // When 2FA is confirmed again
        User::retrieve($userId)->confirmTwoFactor()->persist();

        // Then only one enable marker was ever recorded
        $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', User2FAEnabled::class)->count());
    }

    // Sad — disabling resets the enrollment flag, so a later re-enable is recorded rather
    // than swallowed by confirmTwoFactor's already-enabled guard (proves applyUser2FADisabled).
    #[Test]
    public function when_two_factor_is_re_enabled_after_being_disabled_then_a_new_enable_marker_is_recorded(): void
    {
        // Given a user who enabled then disabled 2FA
        $userId = (string) Str::uuid();
        User::retrieve($userId)->confirmTwoFactor()->persist();
        User::retrieve($userId)->disableTwoFactor()->persist();

        // When they enable 2FA again
        User::retrieve($userId)->confirmTwoFactor()->persist();

        // Then a second enable marker is recorded — the disable reset the flag
        $this->assertSame(2, LesilyStoredEvent::query()->where('event_class', User2FAEnabled::class)->count());
    }

    protected function buildRegisterOwnerCommand(): RegisterOwnerCommand
    {
        return RegisterOwnerCommand::validateAndCreate([
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
        ]);
    }
}
