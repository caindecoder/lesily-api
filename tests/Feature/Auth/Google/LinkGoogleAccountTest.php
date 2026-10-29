<?php

namespace Tests\Feature\Auth\Google;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
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

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function provideMalformedIdTokens(): array
    {
        return [
            'missing' => [[], 'The id token field is required.'],
            'empty' => [['idToken' => ''], 'The id token field is required.'],
            'not a string' => [['idToken' => 123], 'The id token field must be a string.'],
        ];
    }

    protected function assertLinkRefusedWith(TestResponse $response, int $expectedStatus, ApiErrorCode $code): void
    {
        $this->assertRefusedWith($response, $expectedStatus, $code);
        $this->assertNull($this->alice->fresh()?->google_id, 'A refused link must leave the account unlinked.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $this->alice->getKey())->count());
    }
}
