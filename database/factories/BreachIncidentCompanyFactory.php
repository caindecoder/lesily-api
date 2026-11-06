<?php

namespace Database\Factories;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;

/**
 * @extends Factory<BreachIncidentCompany>
 */
class BreachIncidentCompanyFactory extends Factory
{
    protected $model = BreachIncidentCompany::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'breach_incident_id' => BreachIncidentRecord::factory(),
            'company_id' => Company::factory(),
            'data_types' => ['pii'],
            'estimated_count' => 10,
            'added_at' => fn (array $attributes) => BreachIncidentRecord::query()->whereKey($attributes['breach_incident_id'])->firstOrFail()->recorded_at,
            'added_by' => fn (array $attributes) => BreachIncidentRecord::query()->whereKey($attributes['breach_incident_id'])->firstOrFail()->recorded_by,
            'added_reason' => null,
            'notified_at' => null,
            'filing_requested_at' => null,
            'filing_requested_by' => null,
            'filed_at' => null,
            'filed_by' => null,
            'filed_body_hash' => null,
        ];
    }

    public function forIncident(BreachIncidentRecord $incident, Company $company): static
    {
        return $this->state([
            'breach_incident_id' => $incident->getKey(),
            'company_id' => $company->getKey(),
            'added_at' => $incident->recorded_at,
            'added_by' => $incident->recorded_by,
        ]);
    }

    /**
     * @param  list<string>  $dataTypes
     */
    public function withFigures(array $dataTypes, ?int $estimatedCount): static
    {
        return $this->state(['data_types' => $dataTypes, 'estimated_count' => $estimatedCount]);
    }

    public function notified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'notified_at' => CarbonImmutable::parse($attributes['added_at'])->addDay(),
        ]);
    }

    public function notifiedAt(CarbonImmutable $at): static
    {
        return $this->state(['notified_at' => $at]);
    }

    public function filed(string $ownerId, string $adminId): static
    {
        return $this->filingRequested($ownerId)->state(fn (array $attributes): array => [
            'filed_at' => CarbonImmutable::parse($attributes['added_at'])->addDays(3),
            'filed_by' => $adminId,
            'filed_body_hash' => hash('sha256', 'the regulator notice'),
        ]);
    }

    public function filingRequested(string $ownerId): static
    {
        return $this->notified()->state(fn (array $attributes): array => [
            'filing_requested_at' => CarbonImmutable::parse($attributes['added_at'])->addDays(2),
            'filing_requested_by' => $ownerId,
        ]);
    }
}
