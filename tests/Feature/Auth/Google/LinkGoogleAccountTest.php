<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $response->assertJsonPath('message', 'Google could not confirm this sign-in. Please try again.');
    }

    #[Test]
    public function when_no_google_client_is_configured_then_every_link_is_refused(): void
    {
        // Given Alice on a Starter company, the real verifier, and no Google client id yet
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        config(['services.google.client_id' => '']);

        // When Alice links Google with any token
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
        $this->withoutGoogleSignIn($this->alice, $memberships);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the upgrade refusal names Starter and nothing is linked
        $this->assertLinkRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Starter');
    }

    #[Test]
    public function when_the_google_account_is_linked_to_another_user_then_the_link_is_refused(): void
    {
        // Given Bob linked to a Google account that now carries Alice's email, and Alice on a Starter company
        User::factory()->linkedToGoogle(self::GOOGLE_SUBJECT)->create();
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links that same Google account
        $response = $this->linkGoogle($this->alice);

        // Then the link is refused and nothing is linked to Alice
        $this->assertLinkRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::GoogleAccountInUse);
        $response->assertJsonPath('message', 'This Google account is already linked to another Lesily login.');
    }

    #[Test]
    public function when_the_user_is_already_linked_then_the_link_is_refused_and_the_existing_link_is_kept(): void
    {
        // Given Alice on a Starter company, already linked to her Google account
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));
        $this->linkGoogle($this->alice);

        // When Alice links Google again
        $response = $this->linkGoogle($this->alice);

        // Then the second link is refused and the first one stands, recorded once
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::GoogleAlreadyLinked);
        $response->assertJsonPath('message', 'Your Lesily login is already linked to a Google account.');
        $this->assertAccountLinkedTo(self::GOOGLE_SUBJECT);
    }

    #[Test]
    public function when_too_many_link_attempts_are_made_then_the_request_is_throttled(): void
    {
        // Given Alice spending the shared per-IP auth-tier allowance on invalid Google tokens
        $this->fakeGoogleIdTokenVerifier(null);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->linkGoogle($this->alice);
        }

        // When she tries once more
        $response = $this->linkGoogle($this->alice);

        // Then the over-limit attempt is throttled
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_the_link_trips_another_unique_index_then_the_original_error_is_not_masked(): void
    {
        // Given Alice on a Starter company, and a users projection that collides on an index other than google_id
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));
        $this->induceProjectorFailureFor(UserGoogleOAuthLinked::class, new UniqueConstraintViolationException(
            'mysql',
            'update `users` set `email` = ?',
            ['x'],
            new RuntimeException("Duplicate entry 'x' for key 'users_email_unique'"),
        ));

        // When Alice links her Google account
        $response = $this->linkGoogle($this->alice);

        // Then the unrelated fault surfaces as itself, never as Google-account-in-use
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', ApiErrorCode::InternalError->value);
    }

    #[Test]
    public function when_the_projection_fails_then_the_link_is_rolled_back(): void
    {
        // Given Alice on a Starter company, holding her own verified Google account, and a failing users projection
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));
        $this->induceProjectorFailureFor(UserGoogleOAuthLinked::class);

        // When Alice links her Google account
        $response = $this->linkGoogle($this->alice);

        // Then the failure leaves no stored link and no linked account
        $response->assertInternalServerError();
        $this->assertNull($this->alice->fresh()?->google_id);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', UserGoogleOAuthLinked::class)->count());
    }

    #[Test]
    public function when_google_is_relinked_after_an_unlink_then_the_unlink_marker_is_cleared(): void
    {
        // Given Alice on a Starter company, who linked and then unlinked Google, holding her verified Google account
        $this->freezeTime();
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        UserAggregate::retrieve($this->alice->getKey())->linkGoogleOAuth(self::GOOGLE_SUBJECT)->persist();
        UserAggregate::retrieve($this->alice->getKey())->unlinkGoogleOAuth()->persist();
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links Google again from her profile
        $response = $this->linkGoogle($this->alice);

        // Then she is linked again, the unlink marker is cleared, and the re-link is recorded
        $response->assertNoContent();
        $this->assertSame(self::GOOGLE_SUBJECT, $this->alice->fresh()?->google_id);
        $this->assertNull($this->alice->fresh()?->google_unlinked_at, 'A re-link must turn Google sign-in back on (G12).');
        $this->assertSame(2, LesilyStoredEvent::query()->where('event_class', UserGoogleOAuthLinked::class)->count());
    }

    #[Test]
    #[DataProvider('provideEmailSpellings')]
    public function when_the_google_email_differs_only_in_case_then_the_account_is_linked(string $lesilyEmail, string $googleEmail): void
    {
        // Given Alice on a Starter company, the two emails spelled in different case
        $this->alice->forceFill(['email' => $lesilyEmail])->save();
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor($googleEmail));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then it is linked — email case is not identity
        $this->assertLinkedTo($response, self::GOOGLE_SUBJECT);
    }

    #[Test]
    public function when_one_of_several_companies_can_use_google_sign_in_then_the_account_is_linked(): void
    {
        // Given Alice on a Free company and a Starter one
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Free);
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links her Google account
        $response = $this->linkGoogle($this->alice);

        // Then one company granting Google sign-in is enough
        $this->assertLinkedTo($response, self::GOOGLE_SUBJECT);
    }

    #[Test]
    #[DataProvider('provideGoogleSignInPlans')]
    public function when_the_company_plan_allows_google_sign_in_then_the_account_is_linked(string $plan): void
    {
        // Given Alice on a company whose plan in force grants Google sign-in
        match ($plan) {
            'trial' => $this->memberOfTrialCompany($this->alice),
            default => $this->memberOfCompanyOnPlan($this->alice, BillingPlan::from($plan)),
        };
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links her Google account
        $response = $this->linkGoogle($this->alice);

        // Then it is linked
        $this->assertLinkedTo($response, self::GOOGLE_SUBJECT);
    }

    #[Test]
    public function when_a_verified_google_account_with_the_users_email_is_linked_then_the_link_is_recorded(): void
    {
        // Given Alice on a Starter company, holding her own verified Google account
        $this->memberOfCompanyOnPlan($this->alice, BillingPlan::Starter);
        $this->fakeGoogleIdTokenVerifier($this->aGoogleIdentityFor(self::LOGIN_EMAIL));

        // When Alice links that Google account
        $response = $this->linkGoogle($this->alice);

        // Then the link is recorded as a stored event and projected onto her account
        $this->assertLinkedTo($response, self::GOOGLE_SUBJECT);
    }

    /** @return array<string, array{string, string}> */
    public static function provideEmailSpellings(): array
    {
        return [
            'capitals on the Google side' => [self::LOGIN_EMAIL, 'ALICE@CONTOSO.LK'],
            'capitals on the Lesily side' => ['Alice@Contoso.lk', self::LOGIN_EMAIL],
        ];
    }

    /** @return array<string, array{string}> */
    public static function provideGoogleSignInPlans(): array
    {
        return [
            'starter' => [BillingPlan::Starter->value],
            'pro' => [BillingPlan::Pro->value],
            'enterprise' => [BillingPlan::Enterprise->value],
            'trial' => ['trial'],
        ];
    }

    protected function assertLinkedTo(TestResponse $response, string $googleSubject): void
    {
        $response->assertNoContent();
        $this->assertAccountLinkedTo($googleSubject);
    }

    protected function assertAccountLinkedTo(string $googleSubject): void
    {
        $this->assertSame($googleSubject, $this->alice->fresh()?->google_id);

        $linked = LesilyStoredEvent::query()->where('event_class', UserGoogleOAuthLinked::class)->sole();
        $this->assertSame(
            ['userId' => $this->alice->getKey(), 'googleSubjectId' => $googleSubject],
            Arr::only($linked->event_properties, ['userId', 'googleSubjectId']),
        );
    }

    protected function assertLinkRefusedWith(TestResponse $response, int $expectedStatus, ApiErrorCode $code): void
    {
        $this->assertRefusedWith($response, $expectedStatus, $code);
        $this->assertNull($this->alice->fresh()?->google_id, 'A refused link must leave the account unlinked.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $this->alice->getKey())->count());
    }
}
