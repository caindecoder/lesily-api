<?php

namespace Database\Factories;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use App\Compliance\Breach\Enums\BreachStage;
use App\Compliance\Breach\Enums\BreachSeverity;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;

/**
 * @extends Factory<BreachIncidentRecord>
 */
class BreachIncidentRecordFactory extends Factory
{
    protected $model = BreachIncidentRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $discovered = CarbonImmutable::parse('2026-05-04 14:00:00');

        return [
            'stage' => BreachStage::Recorded,
            'severity' => BreachSeverity::High,
            'description' => 'A payroll export was emailed to the wrong recipient.',
            'likely_consequences' => 'Salary and bank details may be read by one outside person.',
            'measures' => 'The recipient was asked to delete the file.',
            'date_discovered' => $discovered,
            'deadline' => $discovered->addHours(72),
            'recorded_at' => $discovered,
            'recorded_by' => User::factory(),
            'deadline_missed_at' => null,
            'companies_notified_at' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'root_cause' => null,
            'remediation_steps' => null,
            'resolution_summary' => null,
            'false_alarm_at' => null,
            'false_alarm_by' => null,
            'false_alarm_reason' => null,
            'false_alarm_findings' => null,
        ];
    }

    public function identifiedAs(string $id): static
    {
        return $this->state(['id' => $id]);
    }

    public function dueAt(string $deadline): static
    {
        $due = CarbonImmutable::parse($deadline);

        return $this->state([
            'date_discovered' => $due->subHours(72),
            'deadline' => $due,
            'recorded_at' => $due->subHours(72),
        ]);
    }

    public function recordedBy(User $admin): static
    {
        return $this->state(['recorded_by' => $admin->getKey()]);
    }

    public function companiesNotified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage' => BreachStage::CompaniesNotified,
            'companies_notified_at' => CarbonImmutable::parse($attributes['date_discovered'])->addDay(),
        ]);
    }

    /**
     * @param  list<string>  $dataTypes
     */
    public function withCompany(Company $company, array $dataTypes, ?int $estimatedCount): static
    {
        return $this->afterCreating(fn (BreachIncidentRecord $incident) => BreachIncidentCompany::factory()
            ->forIncident($incident, $company)
            ->withFigures($dataTypes, $estimatedCount)
            ->create());
    }
}
