<?php

namespace Tests\Unit\Conventions\Fixtures\InfectionIgnores;

final class IgnoreTargets
{
    private ?self $next = null;

    /** @var array<int, int> */
    private array $memo = [];

    public function constructs(mixed $value): array
    {
        // a comment: no construct starts on this line
        $this->memo[0] ??= 1;
        $this->next?->reset();
        $this->reset();

        return [
            'int' => (int) $value,
            'string' => (string) $value,
        ];
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
