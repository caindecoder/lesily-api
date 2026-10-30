<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\Auth\Events\UserGoogleOAuthUnlinked;
use App\Foundation\Notifications\Enums\NotificationType;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;

class GoogleAccountUnlinkedNotificationTest extends AuthTestCase
{
    use InteractsWithQueuedReactors;

    protected User $alice;

    protected Company $company;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->alice = User::factory()
            ->withEmail(self::LOGIN_EMAIL)
            ->withPassword(self::LOGIN_PASSWORD)
            ->linkedToGoogle(self::GOOGLE_SUBJECT)
            ->create();
        $this->company = Company::factory()->onPlan(BillingPlan::Starter)->create();
        $this->company->users()->attach($this->alice, ['role_id' => Role::factory()->create()->getKey()]);
        $this->token = $this->aSessionFor($this->alice)->plainTextToken;
    }

    #[Test]
    public function when_the_unlink_is_refused_then_no_notice_is_sent(): void
    {
        // Given notifications are captured
        Notification::fake();

        // When Alice unlinks Google with a wrong password
        $response = $this->unlinkGoogle($this->token, ['password' => 'not-my-password']);

        // Then the refusal sends her nothing
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::IncorrectPassword);
        Notification::assertNothingSentTo($this->alice);
    }

    #[Test]
    public function when_the_notice_is_queued_then_it_waits_for_the_queue(): void
    {
        // Given notifications captured and the queue faked
        Notification::fake();
        Queue::fake();

        // When Alice unlinks Google with her password
        $response = $this->unlinkGoogle($this->token);

        // Then the notice is deferred, and reaches her when the queue runs
        $this->assertReactorDeferred(UserGoogleOAuthUnlinked::class, SystemNotification::class);
        $this->runQueuedHandlersFor(UserGoogleOAuthUnlinked::class);
        $this->assertUnlinkedNoticeSent($response);
    }

    #[Test]
    public function when_google_is_unlinked_then_the_user_gets_the_google_account_unlinked_security_notice(): void
    {
        // Given notifications are captured
        Notification::fake();

        // When Alice unlinks Google with her password
        $response = $this->unlinkGoogle($this->token);

        // Then she gets the Google-account-unlinked security notice
        $this->assertUnlinkedNoticeSent($response);
    }

    protected function assertUnlinkedNoticeSent(TestResponse $response): void
    {
        $response->assertNoContent();

        Notification::assertSentTo(
            $this->alice,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::GoogleAccountUnlinked
                && $notification->companyName === $this->company->name
                && $notification->companyId === null,
        );
    }
}
