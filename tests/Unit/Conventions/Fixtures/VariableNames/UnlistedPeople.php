<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Models\User;

final class UnlistedPeople
{
    public function greet(
        User $caller,
        User $currentUser,
        User $authUser,
    ): void {
        //
    }
}
