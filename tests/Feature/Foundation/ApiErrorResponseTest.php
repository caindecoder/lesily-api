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
}
