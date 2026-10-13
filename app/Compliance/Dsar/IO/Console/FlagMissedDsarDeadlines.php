<?php

namespace App\Compliance\Dsar\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use App\Compliance\Dsar\UseCases\Interactors\FlagMissedDsarDeadlinesInteractor;

#[Signature('dsar:flag-missed-deadlines')]
#[Description('Flag DSAR requests still open past their 30-day deadline (defaults to now; --date replays a missed night).')]
class FlagMissedDsarDeadlines extends Command
{
    public function handle(FlagMissedDsarDeadlinesInteractor $interactor): int
    {
        $flagged = $interactor->execute(CarbonImmutable::now());

        $this->info("{$flagged} missed deadline(s) flagged.");

        return self::SUCCESS;
    }
}
