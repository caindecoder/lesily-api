<?php

namespace Database\Factories;

use App\Models\User;
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
}
