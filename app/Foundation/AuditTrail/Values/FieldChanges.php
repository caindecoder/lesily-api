<?php

namespace App\Foundation\AuditTrail\Values;

/**
 * The fields an action changed, each with its old and new value — only the
 * fields whose value differs.
 */
readonly class FieldChanges
{
    /** @param array<string, array{from: mixed, to: mixed}> $changes */
    private function __construct(protected array $changes) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function between(array $old, array $new): self
    {
        $changes = [];

        foreach ($new as $field => $to) {
            $from = $old[$field] ?? null;

            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return new self($changes);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->changes);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    public function toArray(): array
    {
        return $this->changes;
    }
}
