<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewSubscriptionTest extends BillingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        config(['app.debug' => false]);
    }

    // Sad — the read is token-gated: a guest gets the §S21 UNAUTHENTICATED envelope.
    #[Test]
    public function when_guest_requests_subscription_then_unauthenticated(): void
    {
        // Given no authenticated user

        // When the subscription is requested without a token
        $response = $this->viewSubscriptionAsGuest();

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }
}
