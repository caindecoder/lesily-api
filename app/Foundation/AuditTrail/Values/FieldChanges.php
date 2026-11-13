<?php

namespace App\Foundation\AuditTrail\Values;

use Illuminate\Support\Facades\Crypt;

/**
 * The fields an action changed, each with its old and new value — only the
 * fields whose value differs. A field its source stores encrypted keeps both
 * sides encrypted and is marked so the reader knows to decrypt it.
 */
readonly class FieldChanges
{
    /** @param array<string, array<string, mixed>> $changes */
    private function __construct(protected array $changes) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $encrypted
     */
    public static function between(array $old, array $new, array $encrypted = []): self
    {
        $changes = [];

        foreach ($new as $field => $to) {
            $from = $old[$field] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[$field] = in_array($field, $encrypted, true)
                ? ['from' => self::encrypted($from), 'to' => self::encrypted($to), 'encrypted' => true]
                : ['from' => $from, 'to' => $to];
        }

        return new self($changes);
    }

    /**
     * Stored changes as a reader sees them: an encrypted change decrypted and unmarked.
     *
     * @param  ?array<string, array<string, mixed>>  $stored
     * @return ?array<string, array<string, mixed>>
     */
    public static function readable(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        return array_map(static fn (array $change): array => ($change['encrypted'] ?? false) === true
            ? ['from' => self::decrypted($change['from'] ?? null), 'to' => self::decrypted($change['to'] ?? null)]
            : $change, $stored);
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

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->changes;
    }

    protected static function encrypted(mixed $value): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }

    protected static function decrypted(mixed $value): ?string
    {
        return is_string($value) ? Crypt::decryptString($value) : null;
    }
}
