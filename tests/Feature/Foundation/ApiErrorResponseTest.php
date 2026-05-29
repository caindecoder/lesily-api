<?php

namespace Tests\Feature\Foundation;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public static function provideApiErrors(): array
    {
        return [
            'validation error' => ['error-validation', 422, 'VALIDATION_FAILED'],
            'feature gate' => ['error-feature-gate', 403, 'FEATURE_NOT_AVAILABLE'],
            'resource not found' => ['error-not-found', 404, 'RESOURCE_NOT_FOUND'],
            'internal error' => ['error-internal', 500, 'INTERNAL_ERROR'],
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
