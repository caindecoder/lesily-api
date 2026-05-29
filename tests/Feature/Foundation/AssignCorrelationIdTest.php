<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Str;
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

    #[Test]
    public function when_no_correlation_id_supplied_then_a_uuid7_is_minted(): void
    {
        // Given (no Correlation-Id header is sent)

        // When
        $response = $this->getCorrelationProbe();

        // Then
        $this->assertCorrelationMinted($response);
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

    protected function assertCorrelationMinted(TestResponse $response): void
    {
        $response->assertStatus(200);

        $mintedId = $response->headers->get('Correlation-Id');
        $this->assertTrue(Str::isUuid($mintedId), 'Correlation-Id is not a valid UUID.');
        $this->assertSame('7', $mintedId[14], 'Correlation-Id is not a UUIDv7.');
        $response->assertJsonPath('downstream_correlation_id', $mintedId);
    }
}
