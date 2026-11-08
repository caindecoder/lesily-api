<?php

namespace Database\Factories;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Compliance\Breach\Entities\BreachAmendment;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Entities\BreachIncidentRecord;

/**
 * @extends Factory<BreachAmendment>
 */
class BreachAmendmentFactory extends Factory
{
    protected $model = BreachAmendment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'breach_incident_id' => BreachIncidentRecord::factory(),
            'company_id' => null,
            'field' => BreachAmendmentField::Description,
            'old_value' => 'A payroll export was emailed to the wrong recipient.',
            'new_value' => 'Payroll and bank export left on a shared drive',
            'reason' => 'An earlier log entry showed the export reached a second shared folder.',
            'amended_at' => '2026-05-05 09:00:00',
            'amended_by' => User::factory(),
        ];
    }

    public function forIncident(BreachIncidentRecord $incident): static
    {
        return $this->state(['breach_incident_id' => $incident->getKey(), 'amended_by' => $incident->recorded_by]);
    }

    public function amendedAt(string $at): static
    {
        return $this->state(['amended_at' => $at]);
    }

    public function changing(BreachAmendmentField $field, mixed $old, mixed $new): static
    {
        return $this->state(['field' => $field, 'old_value' => $old, 'new_value' => $new]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->getKey()]);
    }
}
