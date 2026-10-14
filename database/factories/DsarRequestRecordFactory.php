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
            'sla_breached_at' => null,
            'completed_at' => null,
            'file_path' => null,
            'download_expires_at' => null,
            'download_expired_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'created_by' => fn (array $attributes) => $attributes['requested_by'],
            'updated_by' => null,
        ];
    }

    public function forSubject(Company $company, User $subject): static
    {
        return $this->state([
            'company_id' => $company->getKey(),
            'subject_user_id' => $subject->getKey(),
        ]);
    }

    public function requestedBy(User $requester, string $roleCode): static
    {
        return $this->state([
            'requested_by' => $requester->getKey(),
            'requested_by_role' => $roleCode,
        ]);
    }

    public function requestedDaysAgo(int $days): static
    {
        $requestedAt = CarbonImmutable::now()->subDays($days);

        return $this->state([
            'requested_at' => $requestedAt,
            'sla_deadline' => $requestedAt->addDays(30),
        ]);
    }

    public function identifiedAs(string $id): static
    {
        return $this->state(['id' => $id]);
    }

    public function dueAt(string $deadline): static
    {
        $slaDeadline = CarbonImmutable::parse($deadline);

        return $this->state([
            'requested_at' => $slaDeadline->subDays(30),
            'sla_deadline' => $slaDeadline,
        ]);
    }

    public function downloadExpired(): static
    {
        return $this->state([
            'download_expires_at' => CarbonImmutable::now(),
            'download_expired_at' => CarbonImmutable::now(),
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
