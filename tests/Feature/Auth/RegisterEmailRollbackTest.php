<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseMigrations;

/**
 * Proves the verification email is a genuine after-commit side effect: a
 * registration that rolls back mid-transaction must send NO email. This needs a
 * really-committing transaction, so it uses DatabaseMigrations rather than
 * RefreshDatabase (whose wrapping transaction never commits, which would stop
 * after_commit from ever firing). after_commit is enabled on the sync queue here
 * so the queued reactor defers to the register transaction's commit/rollback.
 */
class RegisterEmailRollbackTest extends AuthTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.connections.sync.after_commit' => true]);
        $this->seedRbacCatalog();
        Notification::fake();
    }

    // Harness check — a committed registration DOES send the email, so the
    // rollback assertion below cannot pass vacuously.
    #[Test]
    public function when_registration_commits_then_the_verification_email_is_sent(): void
    {
        // When a valid owner registers
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then the after-commit verification email is sent
        $owner = User::query()->where('email', 'nimal@contoso.lk')->sole();
        Notification::assertSentTo($owner, VerifyEmail::class);
    }

    // The proof — an unsupported country rolls the whole registration back, so the
    // after-commit verification email is never sent.
    #[Test]
    public function when_registration_rolls_back_then_no_verification_email_is_sent(): void
    {
        // Given a signup that throws mid-transaction (no seeded country defaults)
        $payload = [...$this->validRegistrationPayload(), 'country' => 'ZZ'];

        // When the guest submits it
        $this->postJson('/api/v1/auth/register', $payload);

        // Then nothing committed, so no verification email was sent
        Notification::assertNothingSent();
    }
}
