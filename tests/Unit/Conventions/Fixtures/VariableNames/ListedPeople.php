<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Models\User;
use Illuminate\Support\Collection;

final class ListedPeople
{
    /**
     * @param  Collection<int, User>  $platformAdmins
     * @param  Collection<int, User>  $otherUsers
     */
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
        User $otherUser,
        User $revokedBy,
        Collection $platformAdmins,
        Collection $otherUsers,
    ): void {
        //
    }
}
