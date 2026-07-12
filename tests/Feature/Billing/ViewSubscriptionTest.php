<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Sad — the read is billing.view-gated: a member whose seeded role lacks the
    // grant is refused without any billing field in the body (UC-1-041 error 1a;
    // 2026-05-24 matrix ❌ rows).
    #[Test]
    #[DataProvider('provideDeniedRoles')]
    public function when_role_lacks_billing_view_then_subscription_is_refused(string $roleCode): void
    {
        // Given a company member whose role lacks billing.view
        [$company, $member] = $this->aCompanyWithMember($roleCode);

        // When they request the subscription state
        $response = $this->viewSubscriptionAs($member, $company);

        // Then the request is refused naming the missing grant
        $this->assertRoleInsufficient($response, 'billing.view');
    }

    // Sad — the company-scope gate runs ahead of the permission gate: an owner of
    // Company A probing under Company B's context is refused as a non-member,
    // regardless of their own role's grants (UC-1-041 tenant isolation, sad side).
    #[Test]
    public function when_company_context_is_foreign_then_access_is_denied(): void
    {
        // Given an owner of Company A and a foreign Company B
        [, $owner] = $this->aCompanyWithMember('owner');
        [$foreignCompany] = $this->aCompanyWithMember('owner');

        // When they request the subscription state under Company B's context
        $response = $this->viewSubscriptionAs($owner, $foreignCompany);

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }
}
