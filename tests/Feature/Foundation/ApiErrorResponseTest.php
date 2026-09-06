<?php

namespace Tests\Feature\Foundation;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ApiErrorResponseTest extends FoundationTestCase
{
    #[Test]
    #[DataProvider('provideApiErrors')]
    public function when_an_api_error_occurs_then_the_error_is_machine_readable_and_traceable(
        string $probe,
        int $expectedStatus,
        string $expectedErrorCode,
    ): void {
        // Given
        config(['app.debug' => false]);

        // When
        $response = $this->getErrorProbe($probe);

        // Then
        $this->assertGlobalErrorContract($response, $expectedStatus, $expectedErrorCode);
    }

    #[Test]
    public function when_validation_fails_then_field_level_errors_are_returned(): void
    {
        // Given (a route whose request fails validation)

        // When
        $response = $this->getErrorProbe('error-validation');

        // Then
        $this->assertFieldValidationErrors($response, 'name', 'The name field is required.');
    }

    #[Test]
    public function when_a_gated_feature_is_requested_then_an_upgrade_prompt_is_included(): void
    {
        // Given (a route that gates a Pro-only feature)

        // When
        $response = $this->getErrorProbe('error-feature-gate');

        // Then
        $this->assertUpgradePromptIncluded($response, 'Pro', '/settings/billing/upgrade');
    }

    #[Test]
    public function when_an_internal_error_occurs_in_production_then_no_internal_details_are_exposed(): void
    {
        // Given
        config(['app.debug' => false]);

        // When
        $response = $this->getErrorProbe('error-internal');

        // Then
        $this->assertInternalErrorIsOpaque($response);
    }

    #[Test]
    public function when_a_client_supplies_a_correlation_id_then_the_error_carries_the_same_id(): void
    {
        // Given
        $suppliedId = '01HZ9P0R7Y4WJT2NS5G8A1KCED';

        // When
        $response = $this->getJson('/api/_test/error-not-found', ['Correlation-Id' => $suppliedId]);

        // Then
        $this->assertErrorCarriesCorrelationId($response, $suppliedId);
    }

    #[Test]
    public function when_a_non_validation_error_occurs_then_the_errors_field_is_an_empty_object(): void
    {
        // When
        $response = $this->getErrorProbe('error-feature-gate');

        // Then
        $response->assertStatus(403);
        $response->assertSee('"errors":{}', false);
    }

    #[Test]
    public function when_debug_is_enabled_then_an_internal_error_is_not_masked(): void
    {
        // Given
        config(['app.debug' => true]);

        // When
        $response = $this->getErrorProbe('error-internal');

        // Then
        $response->assertStatus(500);
        $response->assertSee('boom', false);
    }

    #[Test]
    public function when_an_unmapped_http_exception_carries_status_headers_then_they_are_preserved(): void
    {
        // Given (a method-not-allowed route, whose exception sets an Allow header)
        config(['app.debug' => false]);

        // When
        $response = $this->getErrorProbe('error-method-not-allowed');

        // Then the spec-mandated header survives the envelope rewrite (RFC 7231)
        $response->assertStatus(405);
        $response->assertHeader('Allow', 'POST');
    }

    #[Test]
    public function when_an_unmapped_http_exception_has_an_internal_message_then_it_is_not_leaked(): void
    {
        // Given (an HttpException whose message holds developer-facing detail)
        config(['app.debug' => false]);

        // When
        $response = $this->getErrorProbe('error-http-detail');

        // Then the envelope shows the opaque generic message, never the raw detail
        $response->assertStatus(410);
        $response->assertJsonPath('message', 'The request could not be completed.');
        $response->assertDontSee('sensitive internal detail', false);
    }

    #[Test]
    public function when_the_generic_http_error_code_is_used_without_an_explicit_status_then_it_defaults_to_server_error(): void
    {
        // The HttpException fallback supplies the real status via buildWithStatus();
        // used directly through the code->status() path the generic code is a 500.
        $this->assertSame(500, ApiErrorCode::HttpError->status());
    }

    public static function provideApiErrors(): array
    {
        return [
            'validation error' => ['error-validation', Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED'],
            'feature gate' => ['error-feature-gate', Response::HTTP_FORBIDDEN, 'FEATURE_NOT_AVAILABLE'],
            'resource not found' => ['error-not-found', Response::HTTP_NOT_FOUND, 'RESOURCE_NOT_FOUND'],
            'http not found' => ['error-http-not-found', Response::HTTP_NOT_FOUND, 'RESOURCE_NOT_FOUND'],
            'unmapped http exception preserves method-not-allowed status' => ['error-method-not-allowed', Response::HTTP_METHOD_NOT_ALLOWED, 'HTTP_ERROR'],
            'unmapped http exception preserves gone status' => ['error-gone', Response::HTTP_GONE, 'HTTP_ERROR'],
            'internal error' => ['error-internal', Response::HTTP_INTERNAL_SERVER_ERROR, 'INTERNAL_ERROR'],
        ];
    }

    protected function getErrorProbe(string $name): TestResponse
    {
        return $this->getJson("/api/_test/{$name}");
    }

    protected function assertGlobalErrorContract(TestResponse $response, int $status, string $errorCode): void
    {
        $response->assertStatus($status);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', $errorCode);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $this->assertCorrelationIdMatchesHeader($response);
        $response->assertJsonMissingPath('trace');
    }

    protected function assertCorrelationIdMatchesHeader(TestResponse $response): void
    {
        $correlationId = $response->json('correlation_id');
        $this->assertNotEmpty($correlationId);
        $this->assertSame($correlationId, $response->headers->get('Correlation-Id'));
    }

    protected function assertErrorCarriesCorrelationId(TestResponse $response, string $expectedId): void
    {
        $response->assertJsonPath('correlation_id', $expectedId);
        $this->assertSame($expectedId, $response->headers->get('Correlation-Id'));
    }

    protected function assertFieldValidationErrors(TestResponse $response, string $field, string $message): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$field => $message]);
    }

    protected function assertUpgradePromptIncluded(TestResponse $response, string $upgradeTo, string $upgradeUrl): void
    {
        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', $upgradeTo);
        $response->assertJsonPath('upgrade_url', $upgradeUrl);
    }

    protected function assertInternalErrorIsOpaque(TestResponse $response): void
    {
        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $response->assertJsonPath('message', 'An unexpected error occurred.');
        $response->assertDontSee('boom', false);
        $response->assertDontSee('.php', false);
    }
}
