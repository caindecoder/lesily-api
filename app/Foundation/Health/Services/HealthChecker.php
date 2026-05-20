<?php

namespace App\Foundation\Health\Services;

class HealthChecker
{
    private const DEFAULT_SUBSYSTEMS = [
        'app' => 'up',
        'database' => 'up',
        'redis' => 'up',
        'queue' => 'up',
    ];

    /** @var array<string, string> */
    private array $subsystems;

    /**
     * @param  array<string, string>  $overrides  Per-subsystem state overrides merged on top
     *                                            of the default-all-up baseline. Tests pass
     *                                            specific subsystems marked 'down'; production
     *                                            binding leaves it empty for the all-up default.
     */
    public function __construct(array $overrides = [])
    {
        $this->subsystems = array_merge(self::DEFAULT_SUBSYSTEMS, $overrides);
    }

    /** @return array<string, string> */
    public function subsystems(): array
    {
        return $this->subsystems;
    }

    public function isHealthy(): bool
    {
        return array_filter($this->subsystems, fn (string $status): bool => $status !== 'up') === [];
    }
}
