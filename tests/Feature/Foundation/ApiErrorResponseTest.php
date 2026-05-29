<?php

namespace Tests\Feature\Foundation;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class ApiErrorResponseTest extends FoundationTestCase
{
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

    protected function getErrorProbe(string $name): TestResponse
    {
        return $this->getJson("/api/_test/{$name}");
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
}
