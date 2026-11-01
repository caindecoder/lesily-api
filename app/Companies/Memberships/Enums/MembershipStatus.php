<?php

namespace App\Companies\Memberships\Enums;

/**
 * The roster's status filter (api-1-users): an active member or a pending invitation.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
}
