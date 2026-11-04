<?php

namespace Tests\Feature\Compliance\Breach;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class RecordBreachIncidentTest extends BreachIncidentsTestCase
{
    protected const string COMPANY_A = 'company A';

    #[Test]
    public function when_an_unauthenticated_caller_records_a_breach_then_it_is_refused_as_unauthenticated(): void
    {
        // Given no one is signed in

        // When a breach is recorded
        $response = $this->recordBreach(null, $this->validPayload());

        // Then the caller is refused as unauthenticated and nothing is recorded
        $this->assertUnauthenticated($response);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_a_company_owner_records_a_breach_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given the owner of an affected company, who is not a platform admin
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner records a breach
        $response = $this->recordBreach($owner, $this->validPayload());

        // Then the admin surface answers as if it did not exist and nothing is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_the_idempotency_key_is_missing_then_no_breach_is_recorded(): void
    {
        // Given the admin sends no Idempotency-Key
        $this->idempotencyKey = null;

        // When the admin records a breach
        $response = $this->recordBreach($this->admin, $this->validPayload());

        // Then the request is refused for the missing key and nothing is recorded
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
        $this->assertNothingRecorded();
    }

    #[Test, DataProvider('invalidBreachProvider')]
    public function when_the_breach_details_are_invalid_then_the_breach_is_refused_and_nothing_is_recorded(array $changes, string $field, string $message): void
    {
        // Given the clock reads 2026-05-10 09:00 UTC and one detail of the breach is invalid
        $this->travelTo(CarbonImmutable::parse('2026-05-10T09:00:00Z'));

        // When the admin records the breach
        $response = $this->recordBreach($this->admin, $this->payloadWith($changes));

        // Then the breach is refused on that detail and nothing is recorded
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingRecorded();
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidBreachProvider(): array
    {
        return [
            'no affected company' => [['companies' => []], 'companies', 'At least one affected company must be selected.'],
            'a company that does not exist' => [['companies.0.companyId' => '01980000-0000-7000-8000-000000000000'], 'companies.0.companyId', 'The selected companies.0.companyId is invalid.'],
            'the same company twice' => [['companies.1.companyId' => self::COMPANY_A, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'no description' => [['description' => null], 'description', 'The description field is required.'],
            'no likely consequences' => [['likelyConsequences' => null], 'likelyConsequences', 'The likely consequences field is required.'],
            'no measures' => [['measures' => null], 'measures', 'The measures field is required.'],
            'a description over 1,000 characters' => [['description' => str_repeat('a', 1001)], 'description', 'The description field must not be greater than 1000 characters.'],
            'likely consequences over 1,000 characters' => [['likelyConsequences' => str_repeat('a', 1001)], 'likelyConsequences', 'The likely consequences field must not be greater than 1000 characters.'],
            'measures over 1,000 characters' => [['measures' => str_repeat('a', 1001)], 'measures', 'The measures field must not be greater than 1000 characters.'],
            'discovered one second from now' => [['dateDiscovered' => '2026-05-10T09:00:01Z'], 'dateDiscovered', 'The date discovered field must be a date before or equal to now.'],
            'a discovery moment without a time' => [['dateDiscovered' => '2026-05-04'], 'dateDiscovered', 'The date discovered field must match the format Y-m-d\TH:i:sp.'],
            'a severity outside the four' => [['severity' => 'extreme'], 'severity', 'The selected severity is invalid.'],
            'a company with no data type' => [['companies.0.dataTypes' => []], 'companies.0.dataTypes', 'The companies.0.dataTypes field is required.'],
            'a data type outside the seven' => [['companies.0.dataTypes' => ['passwords']], 'companies.0.dataTypes.0', 'The selected companies.0.dataTypes.0 is invalid.'],
            'a negative count' => [['companies.0.estimatedCount' => -1], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be at least 0.'],
            'a count that is not a number' => [['companies.0.estimatedCount' => 'many'], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be an integer.'],
        ];
    }

    protected function payloadWith(array $changes): array
    {
        $payload = $this->validPayload();

        foreach ($changes as $path => $value) {
            Arr::set($payload, $path, $value === self::COMPANY_A ? $this->companyA->getKey() : $value);
        }

        return $payload;
    }
}
