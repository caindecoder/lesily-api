<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use App\Models\User;

final class ModelKeyWrite
{
    public function assign(User $user, string $id): void
    {
        $user->id = $id;
    }
}
