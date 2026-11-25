<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Models\User;
use App\Companies\Entities\Company;

final class VariadicParameters
{
    public function notify(Company ...$company): int
    {
        return count($company);
    }

    public function invite(User ...$member): int
    {
        return count($member);
    }
}
