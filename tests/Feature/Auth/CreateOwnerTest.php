<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Models\User as UserReadModel;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Aggregates\User;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
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

    // Happy — genesis: signing up as owner stores the identity event scoped to
    // the company, carrying the hashed password (never plaintext), and projects
    // the users row honouring the model contract (ERD-1-01) — one behavioral
    // walk, write side and read side together (conv-plan sec 2.14 Rule A).
    #[Test]
    public function when_an_owner_signs_up_then_the_genesis_is_stored_and_projected_honouring_the_user_model_contract(): void
    {
        // Given an owner-signup command for a new user
        $command = $this->buildRegisterOwnerCommand();

        // When the owner signs up and the genesis is persisted (the projector runs synchronously)
        User::retrieve($this->userId)->createOwner($this->companyId, $command)->persist();

        // Then the write side stores exactly one genesis event, scoped to the
        // company, recording the owner with a hashed password...
        $this->assertSame(1, LesilyStoredEvent::query()->whereForCompany($this->companyId)->count());

        $genesis = LesilyStoredEvent::query()->whereForCompany($this->companyId)
            ->where('event_class', UserSignedUpAsOwner::class)->sole();

        $this->assertGenesisEventRecordsTheOwner($genesis, $command);
        $this->assertPasswordHashedNeverPlaintext($genesis, $command);

        // ...and the read side projects the users row honouring the model contract
        $row = UserReadModel::findOrFail($this->userId);

        $this->assertOwnerIdentityProjected($row, $command);
        $this->assertOwnerProjectedHonouringModelContract($row);
    }

    protected function assertGenesisEventRecordsTheOwner(EloquentStoredEvent $event, RegisterOwnerCommand $command): void
    {
        $this->assertSame($this->userId, $event->event_properties['userId']);
        $this->assertSame($this->companyId, $event->event_properties['companyId']);
        $this->assertSame($command->name, $event->event_properties['name']);
        $this->assertSame($command->email, $event->event_properties['email']);
        $this->assertSame($command->companyName, $event->event_properties['companyName']);
    }

    protected function assertPasswordHashedNeverPlaintext(EloquentStoredEvent $event, RegisterOwnerCommand $command): void
    {
        $this->assertNotSame($command->password, $event->event_properties['passwordHash']);
        $this->assertTrue(Hash::check($command->password, $event->event_properties['passwordHash']));
    }

    protected function assertOwnerIdentityProjected(UserReadModel $row, RegisterOwnerCommand $command): void
    {
        $this->assertSame($command->name, $row->name);
        $this->assertSame($command->email, $row->email);
        $this->assertTrue(Hash::check($command->password, $row->password));
    }

    protected function assertOwnerProjectedHonouringModelContract(UserReadModel $row): void
    {
        $this->assertSame($this->userId, $row->getKey());
        $this->assertTrue(Str::isUuid($row->getKey()), 'users.id must be a char(36) UUID (ERD-1-01, R-042)');
        // Unverified until the signed link is clicked (UC-1-001 alt-6a); the
        // email_verified_at Carbon-cast pin lives at EmailVerificationTest:80.
        $this->assertNull($row->email_verified_at);
    }

    protected function buildRegisterOwnerCommand(): RegisterOwnerCommand
    {
        return RegisterOwnerCommand::validateAndCreate($this->validRegistrationPayload());
    }
}
