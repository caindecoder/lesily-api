<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * UC-1-099 — Receive API Error Response (Global Format) (chunk-1 Tier-1 #2).
 *
 * Design refs:
 * - UC body: Documentation/06-design/chunk-1/use-cases/uc-infrastructure-security.md (UC-1-099)
 * - CD: Documentation/06-design/chunk-1/class-diagram/shared/cd-foundation-http.md (FoundationHttpServiceProvider global error handler)
 * - API: Documentation/06-design/chunk-1/api-design/shared/api-1-infrastructure.md (§S21 error envelope + §4.2 error_code enum)
 */
class ApiErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_test/validation-probe', function (Request $request) {
            $request->validate(['name' => 'required|string']);

            return response()->json(['ok' => true]);
        });

        Route::get('/_test/inaccessible-resource', function () {
            abort(404);
        });
    }

    #[Test]
    public function when_validation_fails_then_validation_error_returned_with_field_list(): void
    {
        // Given any authenticated route that runs validation on its payload
        // When the request submits an invalid payload
        $response = $this->sendInvalidPayloadToValidatedEndpoint();

        // Then the response shape is the global error envelope with validation details
        $this->assertValidationErrorEnvelope($response);
    }

    #[Test]
    public function when_resource_inaccessible_then_not_found_hides_existence(): void
    {
        // Given a request targets a resource that does not exist OR is cross-tenant
        // When the endpoint returns 404 instead of 403 (tenant-isolation hides existence)
        $response = $this->sendRequestToInaccessibleResource();

        // Then the response envelope is RESOURCE_NOT_FOUND with no existence-leaking detail
        $this->assertResourceNotFoundEnvelope($response);
    }

    protected function sendInvalidPayloadToValidatedEndpoint(): TestResponse
    {
        return $this->postJson('/_test/validation-probe', []);
    }

    protected function assertValidationErrorEnvelope(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonStructure([
            'errors' => ['name'],
            'correlation_id',
        ]);
    }

    protected function sendRequestToInaccessibleResource(): TestResponse
    {
        return $this->getJson('/_test/inaccessible-resource');
    }

    protected function assertResourceNotFoundEnvelope(TestResponse $response): void
    {
        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonStructure(['correlation_id']);
    }
}
