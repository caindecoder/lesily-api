<?php

namespace App\Compliance\Dsar\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use App\Compliance\Dsar\UseCases\Interactors\FlagMissedDsarDeadlinesInteractor;

#[Signature('dsar:flag-missed-deadlines {--date=}')]
#[Description('Flag DSAR requests still open past their 30-day deadline (defaults to now; --date replays a missed night).')]
class FlagMissedDsarDeadlines extends Command
{
    public function handle(FlagMissedDsarDeadlinesInteractor $interactor): int
    {
        try {
            $cutoff = CarbonImmutable::parse($this->option('date'));
        } catch (InvalidFormatException) {
            $this->error('The --date option must be a date, such as 2026-10-31.');

            return self::FAILURE;
        }

        if ($cutoff->isFuture()) {
            $this->error('The --date option cannot be later than now.');

            return self::FAILURE;
        }

        $flagged = $interactor->execute($cutoff);

        $this->info("{$flagged} missed deadline(s) flagged.");

        return self::SUCCESS;
    }
}
