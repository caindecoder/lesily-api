<?php

namespace App\Foundation\Auth\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Membership-scoped attribute event on the User identity stream (slice C1) —
 * the Owner-mandatory emergency contact (UC-1-029, S10b: all four fields),
 * each stored encrypted (Crypt::encryptString). Projected onto the company_user
 * pivot columns.
 */
class UserMembershipEmergencyContactSet extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public string $encryptedName,
        public string $encryptedEmail,
        public string $encryptedPhone,
        public string $encryptedRelationship,
    ) {}
}
