<?php

namespace App\Companies\Memberships\UseCases\Results;

/**
 * One row's outcome in a bulk invite (UC-1-020): sent, or skipped with the
 * single invite's refusal.
 */
class BulkInviteRowResult
{
    public function __construct(
        public readonly string $email,
        public readonly string $roleCode,
        public readonly ?string $skippedBecause = null,
    ) {}

    public function wasSent(): bool
    {
        return $this->skippedBecause === null;
    }
}
