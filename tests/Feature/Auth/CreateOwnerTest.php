<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use App\Models\User as UserReadModel;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * Owner-genesis at the domain seam (conv-plan §2.14 Rule C): register mints
 * fresh UUIDs, so the genesis re-fire guard is unreachable via HTTP — it
 * protects future callers and replay integrity, and is provable only at
 * this seam.
 */
class CreateOwnerTest extends AuthTestCase
{
    use RefreshDatabase;

    protected string $userId;

    protected string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // The genesis persist fires the welcome + verification reactors; spy them
        // at the boundary so this class stays about the owner genesis (E23).
        Notification::fake();

        $this->userId = (string) Str::uuid();
        $this->companyId = (string) Str::uuid();
    }

    // Sad — an owner can only sign up once: replaying the genesis on an existing
    // user is rejected, and the rejection must leave the original genesis untouched.
    #[Test]
    public function when_a_user_genesis_is_replayed_then_the_second_sign_up_is_rejected(): void
    {
        // Given a user who already signed up as owner
        $command = $this->buildRegisterOwnerCommand();
        User::retrieve($this->userId)->createOwner($this->companyId, $command)->persist();

        // When the same user signs up again
        try {
            User::retrieve($this->userId)->createOwner($this->companyId, $command);
            $this->fail('Replaying the owner genesis must be rejected.');
        } catch (UserAlreadyRegisteredException) {
            // Then it is rejected and the original genesis is untouched
            $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', UserSignedUpAsOwner::class)->count());
            $this->assertSame(1, UserReadModel::query()->count());
        }
    }

    protected function buildRegisterOwnerCommand(): RegisterOwnerCommand
    {
        return RegisterOwnerCommand::validateAndCreate($this->validRegistrationPayload());
    }
}
