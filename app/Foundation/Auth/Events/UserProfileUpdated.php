<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The account profile was edited by its owner (UC-1-015): the full new scalar state
 * (name + locale) plus the changed-field list — the immutable audit-trail postcondition
 * ("Profile updated — fields: [...]") reads straight off this row. users.name and
 * users.locale are PROJECTED columns, so the write MUST be event-sourced to survive a
 * projection replay (a direct save would be reverted). Name history in the store is
 * covered by the standing R-038 in-place-mask anonymization policy.
 */
class UserProfileUpdated extends ShouldBeStored
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public string $userId,
        public string $name,
        public string $locale,
        public array $changedFields,
        public CarbonInterface $updatedAt,
    ) {}
}
