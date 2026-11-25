<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Models\User;
use Illuminate\Support\Collection;

final class UnlistedPeople
{
    /**
     * @param  Collection<int, User>  $staff
     */
    public function greet(
        User $caller,
        User $currentUser,
        User $authUser,
        Collection $staff,
    ): void {
        //
    }
}
