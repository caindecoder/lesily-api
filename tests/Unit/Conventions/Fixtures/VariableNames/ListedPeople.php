<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Models\User;

final class ListedPeople
{
    public function meet(
        User $user,
        User $actor,
        User $admin,
        User $platformAdmin,
        User $owner,
        User $newOwner,
        User $member,
        User $subject,
        User $invitee,
        User $inviter,
        User $recipient,
        User $requester,
        User $viewer,
        User $revokedBy,
    ): void {
        //
    }
}
