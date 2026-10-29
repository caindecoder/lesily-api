<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class LinkGoogleAccountTest extends AuthTestCase
{
    protected User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->alice = User::factory()->withEmail(self::LOGIN_EMAIL)->create();
    }

    #[Test]
    public function when_not_signed_in_then_the_link_is_refused_as_unauthenticated(): void
    {
        // Given no session

        // When a Google account link is attempted
        $response = $this->postJson('/api/v1/me/google', ['idToken' => 'a-google-id-token']);

        // Then the Sanctum guard refuses it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_no_idempotency_key_is_sent_then_the_link_is_refused(): void
    {
        // Given a signed-in user
        Sanctum::actingAs(User::factory()->create());

        // When the link carries no Idempotency-Key header
        $response = $this->postJson('/api/v1/me/google', ['idToken' => 'a-google-id-token']);

        // Then the mutation is refused before any work runs
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
    }

    /** @param  array<string, mixed>  $payload */
    #[Test]
    #[DataProvider('provideMalformedIdTokens')]
    public function when_the_id_token_is_missing_or_malformed_then_the_link_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given a signed-in user
        $user = User::factory()->create();

        // When the link carries a missing or malformed Google ID token
        $response = $this->linkGoogle($user, $payload);

        // Then it is rejected with the field's validation message
        $this->assertValidationRejected($response, 'idToken', $expectedMessage);
    }

    #[Test]
    public function when_the_google_token_is_invalid_then_the_link_is_refused_and_nothing_is_linked(): void
    {
        // Given Alice on a Starter company, and a Google token that fails verification
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier(null);

        // When Alice links Google with that token
        $response = $this->linkGoogle($this->alice);

        // Then the link is refused and nothing is linked
        $this->assertLinkRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::GoogleTokenInvalid);
    }

    #[Test]
    public function when_the_google_email_is_not_the_users_email_then_the_link_is_refused(): void
    {
        // Given Alice on a Starter company, holding a Google account for another email
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor('alice@gmail.com'));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the link is refused, naming the email to use, and nothing is linked
        $this->assertLinkRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::GoogleEmailMismatch);
        $response->assertJsonPath('message', 'Use the Google account for your Lesily email address.');
    }

    #[Test]
    public function when_google_has_not_verified_the_email_then_the_link_is_refused(): void
    {
        // Given Alice on a Starter company, holding a Google account for her email that Google has not verified
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL, false));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the link is refused, asking for a verified email, and nothing is linked
        $this->assertLinkRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::GoogleEmailMismatch);
        $response->assertJsonPath('message', 'Google has not verified this email address. Verify it with Google first.');
    }

    #[Test]
    #[DataProvider('provideUsersWithoutGoogleSignIn')]
    public function when_no_company_of_the_user_can_use_google_sign_in_then_the_upgrade_refusal_is_returned(string $memberships): void
    {
        // Given Alice with no live company whose plan grants Google sign-in, holding her own verified Google account
        match ($memberships) {
            'only a free company' => $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Free),
            'an expired trial' => $this->memberOfExpiredTrialCompany($this->alice),
            'removed from her starter company' => $this->aRemovedStarterMembership(),
            'no company' => null,
        };
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the upgrade refusal names Starter and nothing is linked
        $this->assertLinkRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Starter');
    }

    /** @return array<string, array{string}> */
    public static function provideUsersWithoutGoogleSignIn(): array
    {
        return [
            'only a free company' => ['only a free company'],
            'an expired trial' => ['an expired trial'],
            'removed from her starter company' => ['removed from her starter company'],
            'no company' => ['no company'],
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function provideMalformedIdTokens(): array
    {
        return [
            'missing' => [[], 'The id token field is required.'],
            'empty' => [['idToken' => ''], 'The id token field is required.'],
            'not a string' => [['idToken' => 123], 'The id token field must be a string.'],
        ];
    }

    protected function aRemovedStarterMembership(): void
    {
        $this->seedRbacCatalog();
        $this->aRemovedMembershipIn(Company::factory()->onPlan(BillingPlan::Starter)->create(), $this->alice);
    }

    protected function assertLinkRefusedWith(TestResponse $response, int $expectedStatus, ApiErrorCode $code): void
    {
        $this->assertRefusedWith($response, $expectedStatus, $code);
        $this->assertNull($this->alice->fresh()?->google_id, 'A refused link must leave the account unlinked.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $this->alice->getKey())->count());
    }
}
