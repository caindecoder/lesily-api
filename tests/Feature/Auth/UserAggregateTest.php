<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;

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
