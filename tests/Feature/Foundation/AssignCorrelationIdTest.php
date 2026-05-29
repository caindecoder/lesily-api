<?php

namespace Tests\Feature\Foundation;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class AssignCorrelationIdTest extends FoundationTestCase
{
    #[Test]
    public function when_client_supplies_correlation_id_then_it_is_propagated_unchanged(): void
    {
        // Given
        $suppliedId = '01HZ9P0R7Y4WJT2NS5G8A1KCED';

        // When
        $response = $this->getCorrelationProbe(['Correlation-Id' => $suppliedId]);

        // Then
        $this->assertCorrelationPropagated($response, $suppliedId);
    }

    protected function getCorrelationProbe(array $headers = []): TestResponse
    {
        return $this->getJson('/api/_test/correlation-probe', $headers);
    }

    protected function assertCorrelationPropagated(TestResponse $response, string $expectedId): void
    {
        $response->assertStatus(200);
        $response->assertHeader('Correlation-Id', $expectedId);
        $response->assertJsonPath('downstream_correlation_id', $expectedId);
    }
}
