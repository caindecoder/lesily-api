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
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Notifications\Enums\NotificationType;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;

class GoogleAccountLinkedNotificationTest extends AuthTestCase
{
    use InteractsWithQueuedReactors;

    protected User $alice;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->alice = User::factory()->withEmail(self::LOGIN_EMAIL)->create();
        $this->company = Company::factory()->onPlan(BillingPlan::Starter)->create();
        $this->company->users()->attach($this->alice, ['role_id' => Role::factory()->create()->getKey()]);
    }

    #[Test]
    public function when_the_link_is_refused_then_no_notice_is_sent(): void
    {
        // Given Alice holding a Google account for another email
        Notification::fake();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor('alice@gmail.com'));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the refusal sends her nothing
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::GoogleEmailMismatch);
        Notification::assertNothingSentTo($this->alice);
    }

    #[Test]
    public function when_the_notice_is_queued_then_it_waits_for_the_queue(): void
    {
        // Given Alice holding her own verified Google account, and the queue faked
        Notification::fake();
        Queue::fake();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the notice is deferred, and reaches her when the queue runs
        $this->assertReactorDeferred(UserGoogleOAuthLinked::class, SystemNotification::class);
        $this->runQueuedHandlersFor(UserGoogleOAuthLinked::class);
        $this->assertLinkedNoticeSent($response);
    }

    #[Test]
    public function when_google_is_linked_then_the_user_gets_the_google_account_linked_security_notice(): void
    {
        // Given Alice holding her own verified Google account
        Notification::fake();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then she gets the Google-account-linked security notice
        $this->assertLinkedNoticeSent($response);
    }

    protected function assertLinkedNoticeSent(TestResponse $response): void
    {
        $response->assertNoContent();

        Notification::assertSentTo(
            $this->alice,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::GoogleAccountLinked
                && $notification->companyName === $this->company->name
                && $notification->companyId === null,
        );
    }
}
