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

    #[Test]
    public function when_response_is_an_error_then_correlation_id_is_still_present(): void
    {
        // Given (a route that returns a non-2xx response)

        // When
        $response = $this->getCorrelationErrorProbe();

        // Then
        $this->assertErrorResponseCarriesCorrelationId($response);
    }

    #[Test]
    public function when_a_request_without_a_token_is_refused_then_the_refusal_carries_a_correlation_id(): void
    {
        // Given (no token is sent to an authenticated route)

        // When
        $response = $this->getJson('/api/_test/authenticated-probe');

        // Then
        $response->assertUnauthorized();
        $this->assertRefusalCorrelated($response);
    }

    #[Test]
    public function when_a_request_is_refused_by_the_rate_limit_then_the_refusal_carries_its_own_correlation_id(): void
    {
        // Given the one request a minute this route allows has been used
        $allowedId = $this->getJson('/api/_test/rate-limit-general-probe')->headers->get('Correlation-Id');

        // When another request follows
        $response = $this->getJson('/api/_test/rate-limit-general-probe');

        // Then the refusal carries an id of its own, not the earlier request's
        $response->assertTooManyRequests();
        $this->assertNotSame($allowedId, $this->assertRefusalCorrelated($response));
    }

    #[Test]
    public function when_health_endpoint_polled_then_correlation_id_is_not_assigned(): void
    {
        // Given (Betterstack polling the health endpoint, which opts out)

        // When
        $response = $this->getHealth();

        // Then
        $this->assertNoCorrelationIdAssigned($response);
    }

    protected function getCorrelationProbe(array $headers = []): TestResponse
    {
        return $this->getJson('/api/_test/correlation-probe', $headers);
    }

    protected function getCorrelationErrorProbe(): TestResponse
    {
        return $this->getJson('/api/_test/correlation-error-probe');
    }

    protected function getHealth(): TestResponse
    {
        return $this->getJson('/api/health');
    }

    protected function assertCorrelationPropagated(TestResponse $response, string $expectedId): void
    {
        $response->assertOk();
        $response->assertHeader('Correlation-Id', $expectedId);
        $response->assertJsonPath('downstream_correlation_id', $expectedId);
    }

    protected function assertCorrelationMinted(TestResponse $response): void
    {
        $response->assertOk();

        $mintedId = $response->headers->get('Correlation-Id');
        $this->assertTrue(Str::isUuid($mintedId), 'Correlation-Id is not a valid UUID.');
        $this->assertSame('7', $mintedId[14], 'Correlation-Id is not a UUIDv7.');
        $response->assertJsonPath('downstream_correlation_id', $mintedId);
    }

    protected function assertErrorResponseCarriesCorrelationId(TestResponse $response): void
    {
        $response->assertNotFound();
        $this->assertNotEmpty($response->headers->get('Correlation-Id'));
    }

    protected function assertRefusalCorrelated(TestResponse $response): string
    {
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $response->assertJsonPath('correlation_id', $correlationId);

        return $correlationId;
    }

    protected function assertNoCorrelationIdAssigned(TestResponse $response): void
    {
        $this->assertFalse($response->headers->has('Correlation-Id'));
    }
}
