<?php

namespace Database\Factories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Foundation\AccessLog\Enums\AccessEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;

/**
 * @extends Factory<AccessLogEntry>
 */
class AccessLogEntryFactory extends Factory
{
    protected $model = AccessLogEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => null,
            'user_id' => null,
            'event' => AccessEvent::LoginSucceeded,
            'reason' => null,
            'http_method' => 'POST',
            'route' => 'v1/auth/login',
            'status' => 200,
            'error_code' => null,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0 (Test)',
            'request_id' => (string) Str::uuid(),
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->getKey()]);
    }

    public function byUser(User $user): static
    {
        return $this->state(['user_id' => $user->getKey()]);
    }

    /**
     * A row whose user id is stored as given — null, or an id no account holds (the column is a soft reference).
     */
    public function forUserId(?string $userId): static
    {
        return $this->state(['user_id' => $userId]);
    }

    public function ofEvent(AccessEvent $event, ?AccessEventReason $reason = null): static
    {
        return $this->state(['event' => $event, 'reason' => $reason]);
    }

    public function seenAt(DateTimeInterface $seenAt): static
    {
        return $this->state(['first_seen_at' => $seenAt, 'last_seen_at' => $seenAt]);
    }

    /**
     * A refusal repeated within the fold window: first seen at $firstSeenAt, counted $occurrences times.
     */
    public function foldedSince(DateTimeInterface $firstSeenAt, int $occurrences): static
    {
        return $this->state(['first_seen_at' => $firstSeenAt, 'occurrences' => $occurrences]);
    }

    public function fromOrigin(string $ipAddress, string $userAgent): static
    {
        return $this->state(['ip_address' => $ipAddress, 'user_agent' => $userAgent]);
    }
}
