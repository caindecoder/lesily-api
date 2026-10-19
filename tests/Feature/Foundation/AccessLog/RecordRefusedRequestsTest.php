<?php

namespace Tests\Feature\Foundation\AccessLog;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class RecordRefusedRequestsTest extends FoundationTestCase
{
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
