<?php

namespace App\Compliance\Breach\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use App\Compliance\Breach\UseCases\Interactors\FlagMissedBreachDeadlinesInteractor;

#[Signature('breaches:flag-missed-deadlines {--date=}')]
#[Description('Flag breach incidents still recorded past their 72-hour deadline (defaults to now; --date replays a missed night).')]
class FlagMissedBreachDeadlines extends Command
{
    public function handle(FlagMissedBreachDeadlinesInteractor $interactor): int
    {
        try {
            $cutoff = CarbonImmutable::parse($this->option('date'))->utc();
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
