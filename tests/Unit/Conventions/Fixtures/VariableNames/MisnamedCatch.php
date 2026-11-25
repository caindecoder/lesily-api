<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Illuminate\Database\QueryException;

final class MisnamedCatch
{
    public function attempt(callable $write): bool
    {
        try {
            $write();
        } catch (QueryException $e) {
            return false;
        }

        return true;
    }
}
