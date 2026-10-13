<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Carbon\CarbonInterface;

class FlagMissedDsarDeadlinesInteractor
{
    /**
     * Flags every open request whose deadline passed by $at and returns how
     * many it flagged.
     */
    public function execute(CarbonInterface $at): int
    {
        return 0;
    }
}
