<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachAmended;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class AmendBreachIncidentTest extends BreachIncidentsTestCase
{
    protected const string COMPANY_A = 'company A';

    protected const string COMPANY_A_UPPERCASE = 'company A in upper case';

    protected const string REASON = 'An earlier log entry showed the export reached a second shared folder.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
    }

    #[Test]
    public function when_an_unauthenticated_caller_amends_an_incident_then_it_is_refused_as_unauthenticated(): void
    {
        // Given a recorded incident
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When a caller with no token corrects its description
        $response = $this->amendIncident(null, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then they are refused as unauthenticated and nothing is amended
        $this->assertUnauthenticated($response);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_amends_an_incident_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a recorded incident affecting company A, and company A's owner
        $breachId = $this->aRecordedIncident();
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->snapshotOf($breachId);

        // When the owner corrects its description on the admin surface
        $response = $this->amendIncident($owner, $breachId, $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']));

        // Then the admin surface is not acknowledged and nothing is amended
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingAmended($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id
        $correction = $this->aCorrection(['description' => 'Payroll and bank export left on a shared drive']);

        // When the admin corrects it
        $response = $this->amendIncident($this->admin, $breachId, $correction);

        // Then it is not found and nothing is amended
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachAmended::class)->count());
    }

    #[Test, DataProvider('provideInvalidAmendments')]
    public function when_the_amendment_is_invalid_then_it_is_refused_and_nothing_is_amended(array $changes, string $field, string $message): void
    {
        // Given a recorded incident and a correction with one invalid value
        $breachId = $this->aRecordedIncident();
        $before = $this->snapshotOf($breachId);

        // When the admin sends the correction
        $response = $this->amendIncident($this->admin, $breachId, $this->correctionWith($changes));

        // Then it is refused on that value and nothing is amended
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingAmended($breachId, $before);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidAmendments(): array
    {
        return [
            'no reason' => [['reason' => null], 'reason', 'The reason field is required.'],
            'a reason over 1,000 characters' => [['reason' => str_repeat('a', 1001)], 'reason', 'The reason field must not be greater than 1000 characters.'],
            'a reason that is not text' => [['reason' => ['a list']], 'reason', 'The reason field must be a string.'],
            'no company list' => [['companies' => null], 'companies', 'At least one affected company must be selected.'],
            'no affected company' => [['companies' => []], 'companies', 'At least one affected company must be selected.'],
            'a company entry that is not a company' => [['companies.0' => 'company A'], 'companies.0', 'The companies.0 field must be an array.'],
            'a company without its id' => [['companies.0.companyId' => null], 'companies.0.companyId', 'The companies.0.companyId field is required.'],
            'a company id that is not a uuid' => [['companies.0.companyId' => 'company-a'], 'companies.0.companyId', 'The companies.0.companyId field must be a valid UUID.'],
            'a company that does not exist' => [['companies.0.companyId' => '01980000-0000-7000-8000-000000000000'], 'companies.0.companyId', 'The selected companies.0.companyId is invalid.'],
            'the same company twice' => [['companies.1.companyId' => self::COMPANY_A, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'the same company twice in another case' => [['companies.1.companyId' => self::COMPANY_A_UPPERCASE, 'companies.1.dataTypes' => ['payroll']], 'companies.0.companyId', 'The companies.0.companyId field has a duplicate value.'],
            'a company with no data type' => [['companies.0.dataTypes' => []], 'companies.0.dataTypes', 'The companies.0.dataTypes field is required.'],
            'a data type outside the seven' => [['companies.0.dataTypes' => ['passwords']], 'companies.0.dataTypes.0', 'The selected companies.0.dataTypes.0 is invalid.'],
            'a negative count' => [['companies.0.estimatedCount' => -1], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be at least 0.'],
            'a count beyond the largest the record holds' => [['companies.0.estimatedCount' => 4294967296], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must not be greater than 4294967295.'],
            'a count that is not a number' => [['companies.0.estimatedCount' => 'many'], 'companies.0.estimatedCount', 'The companies.0.estimatedCount field must be an integer.'],
            'an emptied description' => [['description' => null], 'description', 'The description field is required.'],
            'emptied likely consequences' => [['likelyConsequences' => null], 'likelyConsequences', 'The likely consequences field is required.'],
            'emptied measures' => [['measures' => null], 'measures', 'The measures field is required.'],
            'a description over 1,000 characters' => [['description' => str_repeat('a', 1001)], 'description', 'The description field must not be greater than 1000 characters.'],
            'likely consequences over 1,000 characters' => [['likelyConsequences' => str_repeat('a', 1001)], 'likelyConsequences', 'The likely consequences field must not be greater than 1000 characters.'],
            'measures over 1,000 characters' => [['measures' => str_repeat('a', 1001)], 'measures', 'The measures field must not be greater than 1000 characters.'],
            'discovered one second from now' => [['dateDiscovered' => '2026-05-05T09:00:01Z'], 'dateDiscovered', 'The date discovered field must be a date before or equal to now.'],
            'an emptied discovery moment' => [['dateDiscovered' => null], 'dateDiscovered', 'The date discovered field is required.'],
            'a discovery moment without a time' => [['dateDiscovered' => '2026-05-04'], 'dateDiscovered', 'The date discovered field must match the format Y-m-d\TH:i:sp.'],
            'an emptied severity' => [['severity' => null], 'severity', 'The severity field is required.'],
            'a severity outside the four' => [['severity' => 'extreme'], 'severity', 'The selected severity is invalid.'],
        ];
    }

    protected function amendIncident(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/breach-incidents/{$breachId}", $body);
    }

    protected function aCorrection(array $changes): array
    {
        return ['reason' => self::REASON, ...$changes];
    }

    protected function correctionWith(array $changes): array
    {
        $correction = $this->aCorrection(['companies' => [['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 40]]]);

        foreach ($changes as $path => $value) {
            Arr::set($correction, $path, match ($value) {
                self::COMPANY_A => $this->companyA->getKey(),
                self::COMPANY_A_UPPERCASE => strtoupper($this->companyA->getKey()),
                default => $value,
            });
        }

        return $correction;
    }

    protected function assertNothingAmended(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', BreachAmended::class)->count());
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_amended']);
    }
}
