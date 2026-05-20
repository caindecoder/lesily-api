<?php

declare(strict_types=1);

namespace App\Foundation\Health\Services;

final class HealthChecker
{
    /** @param  array<string, string>  $subsystems */
    public function __construct(private array $subsystems = []) {}

    /** @return array<string, string> */
    public function subsystems(): array
    {
        return $this->subsystems;
    }

    public function isHealthy(): bool
    {
        return ! in_array('down', $this->subsystems, strict: true);
    }
}
