<?php

namespace Tests\Feature\Auth\Google;

use Illuminate\Http\Response;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Notifications\Enums\NotificationType;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Notifications\SystemNotification;

class GoogleAccountLinkedNotificationTest extends AuthTestCase
{
    use FreeTierCompany, InteractsWithQueuedReactors;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->makeFreeTierCompany();
    }

    #[Test]
    public function when_the_link_is_refused_then_no_notice_is_sent(): void
    {
        // Given the employee of Contoso on Starter, holding a Google account for another email
        $this->putOnPlan($this->company, BillingPlan::Starter);
        Notification::fake();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor('alice@gmail.com'));

        // When the employee links that Google account
        $response = $this->linkGoogle($this->member);

        // Then the refusal sends them nothing
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::GoogleEmailMismatch);
        Notification::assertNothingSentTo($this->member);
    }

    #[Test]
    public function when_the_notice_is_queued_then_it_waits_for_the_queue(): void
    {
        // Given the employee of Contoso on Starter, holding their own verified Google account, and the queue faked
        $this->putOnPlan($this->company, BillingPlan::Starter);
        Notification::fake();
        Queue::fake();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor($this->member->email));

        // When the employee links that Google account
        $response = $this->linkGoogle($this->member);

        // Then the notice is deferred, and reaches them when the queue runs
        $this->assertReactorDeferred(UserGoogleOAuthLinked::class, SystemNotification::class);
        $this->runQueuedHandlersFor(UserGoogleOAuthLinked::class);
        $this->assertLinkedNoticeSent($response);
    }

    #[Test]
    public function when_google_is_linked_then_the_user_gets_the_google_account_linked_security_notice(): void
    {
        // Given the employee of Contoso on Starter, holding their own verified Google account
        $this->putOnPlan($this->company, BillingPlan::Starter);
        Notification::fake();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor($this->member->email));

        // When the employee links that Google account
        $response = $this->linkGoogle($this->member);

        // Then they get the Google-account-linked security notice
        $this->assertLinkedNoticeSent($response);
    }

    protected function assertLinkedNoticeSent(TestResponse $response): void
    {
        $response->assertNoContent();

        Notification::assertSentTo(
            $this->member,
            SystemNotification::class,
            fn (SystemNotification $systemNotification): bool => $systemNotification->type === NotificationType::GoogleAccountLinked
                && $systemNotification->companyName === $this->company->name
                && $systemNotification->companyId === null,
        );
    }
}
