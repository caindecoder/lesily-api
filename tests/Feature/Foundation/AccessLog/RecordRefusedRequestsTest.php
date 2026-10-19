<?php

namespace Tests\Feature\Foundation\AccessLog;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

class RecordRefusedRequestsTest extends FoundationTestCase
{
    use WithRolePermissionFixtures;

    protected Company $company;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->onPlan(BillingPlan::Free)->create();
        $this->member = User::factory()->create();
        $this->joinCompany($this->company, $this->member);
    }

    #[Test]
    public function when_a_use_case_noted_the_outcome_then_no_refusal_is_logged_beside_it(): void
    {
        // Given a registered user
        $user = User::factory()->withEmail('alice@contoso.lk')->withPassword('Str0ng-Pass!')->create();

        // When they sign in with the wrong password
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'alice@contoso.lk', 'password' => 'not-my-password']);

        // Then the refusal is the sign-in's own row, and no other
        $response->assertUnauthorized();
        $this->assertAccessLogged(AccessEvent::LoginFailed, AccessEventReason::WrongPassword, $user);
    }

    #[Test]
    public function when_access_to_the_company_is_denied_then_the_refusal_is_a_platform_row(): void
    {
        // Given a company the member does not belong to
        $otherCompany = Company::factory()->onPlan(BillingPlan::Free)->create();

        // When the member asks for it
        $response = $this->attemptGatedAction('company-context-probe', $this->member, $otherCompany);

        // Then it is refused, and the row names the member but no company
        $response->assertForbidden();
        $entry = $this->assertRefusalLogged(AccessEvent::Forbidden, $this->member, null);
        $this->assertAnsweredAs($entry, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    public function when_the_company_is_unknown_then_the_refusal_is_a_platform_row(): void
    {
        // Given (the member names a company id that does not exist)
        Sanctum::actingAs($this->member);

        // When the member asks for it
        $response = $this->getJson('/api/_test/company-context-probe', ['X-Company-Context' => (string) Str::uuid7()]);

        // Then it is refused, and the row names the member but no company
        $response->assertNotFound();
        $this->assertRefusalLogged(AccessEvent::NotFound, $this->member, null);
    }

    #[Test]
    public function when_a_record_is_hidden_inside_the_company_then_the_refusal_names_the_company(): void
    {
        // Given (a member of the company)

        // When the member asks for a record the company keeps out of sight
        $response = $this->attemptGatedAction('company-hidden-record-probe', $this->member, $this->company);

        // Then it is refused, and the row names the member and the company
        $response->assertNotFound();
        $this->assertRefusalLogged(AccessEvent::NotFound, $this->member, $this->company);
    }

    #[Test]
    public function when_a_request_carries_no_valid_token_then_an_unauthenticated_refusal_is_logged(): void
    {
        // Given the clock stands still
        $this->freezeTime();

        // When a request reaches an authenticated route without a token
        $response = $this->getJson('/api/_test/authenticated-probe');

        // Then it is refused, and one unauthenticated row records the request
        $response->assertUnauthorized();
        $entry = $this->assertRefusalLogged(AccessEvent::Unauthenticated, null, null);
        $this->assertAnsweredAs($entry, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
        $this->assertLoggedRequest($response, $entry, 'GET api/_test/authenticated-probe');
        $this->assertSeenOnce($entry);
    }

    #[Test]
    public function when_a_member_lacks_the_feature_then_a_forbidden_refusal_names_the_member_and_the_company(): void
    {
        // Given a member of a company on the Free plan

        // When the member asks for a Pro feature in that company
        $response = $this->attemptGatedAction('two-gate/pro-feature', $this->member, $this->company);

        // Then it is refused, and the row names the member and the company
        $response->assertForbidden();
        $entry = $this->assertRefusalLogged(AccessEvent::Forbidden, $this->member, $this->company);
        $this->assertAnsweredAs($entry, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
    }

    #[Test]
    public function when_a_route_finds_nothing_then_a_not_found_refusal_is_logged(): void
    {
        // Given (an anonymous client)

        // When it asks a route for a record that does not exist
        $response = $this->getJson('/api/_test/error-http-not-found');

        // Then it is refused, and one not-found row records it
        $response->assertNotFound();
        $entry = $this->assertRefusalLogged(AccessEvent::NotFound, null, null);
        $this->assertAnsweredAs($entry, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    public function when_the_rate_limit_is_hit_then_a_rate_limited_refusal_is_logged(): void
    {
        // Given the one request a minute this route allows has been used
        $this->getJson('/api/_test/rate-limit-general-probe');

        // When another request follows
        $response = $this->getJson('/api/_test/rate-limit-general-probe');

        // Then it is refused, and one rate-limited row records it
        $response->assertTooManyRequests();
        $entry = $this->assertRefusalLogged(AccessEvent::RateLimited, null, null);
        $this->assertAnsweredAs($entry, Response::HTTP_TOO_MANY_REQUESTS, ApiErrorCode::RateLimitExceeded);
    }

    protected function assertRefusalLogged(AccessEvent $event, ?User $actor, ?Company $company): AccessLogEntry
    {
        $entry = AccessLogEntry::query()->sole();

        $this->assertSame($event, $entry->event);
        $this->assertNull($entry->reason);
        $this->assertSame($actor?->getKey(), $entry->user_id);
        $this->assertSame($company?->getKey(), $entry->company_id);

        return $entry;
    }
}
