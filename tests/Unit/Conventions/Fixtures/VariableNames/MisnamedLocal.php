<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Compliance\Breach\Entities\BreachIncidentRecord;

final class MisnamedLocal
{
    public function load(): BreachIncidentRecord
    {
        $row = BreachIncidentRecord::query()->sole();

        return $row;
    }
}
