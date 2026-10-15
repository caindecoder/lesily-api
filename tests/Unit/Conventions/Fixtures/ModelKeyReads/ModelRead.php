<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use App\Models\User;

final class ModelRead
{
    public function keyOf(User $user): string
    {
        return $user->id;
    }
}
