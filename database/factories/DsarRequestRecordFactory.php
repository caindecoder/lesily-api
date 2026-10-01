<?php

namespace Database\Factories;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DsarRequestRecord>
 */
class DsarRequestRecordFactory extends Factory
{
    protected $model = DsarRequestRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requestedAt = CarbonImmutable::now();
        $subject = User::factory();

        return [
            'company_id' => Company::factory(),
            'subject_user_id' => $subject,
            'requested_by' => fn (array $attributes) => $attributes['subject_user_id'],
            'requested_by_role' => 'employee',
            'request_type' => 'access',
            'status' => DsarRequestStatus::InProgress,
            'requested_at' => $requestedAt,
            'sla_deadline' => $requestedAt->addDays(30),
            'completed_at' => null,
            'file_path' => null,
            'download_expires_at' => null,
            'created_by' => fn (array $attributes) => $attributes['requested_by'],
        ];
    }

    public function forSubject(Company $company, User $subject): static
    {
        return $this->state([
            'company_id' => $company->getKey(),
            'subject_user_id' => $subject->getKey(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state([
            'status' => DsarRequestStatus::Fulfilled,
            'completed_at' => CarbonImmutable::now(),
            'file_path' => 'dsar/'.fake()->uuid().'.zip',
            'download_expires_at' => CarbonImmutable::now()->addDays(7),
        ]);
    }
}
