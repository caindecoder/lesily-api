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
    public function handle(FlagMissedDsarDeadlinesInteractor $flagMissedDsarDeadlinesInteractor): int
    {
        try {
            $scannedAt = CarbonImmutable::parse($this->option('date'));
        } catch (InvalidFormatException) {
            $this->error('The --date option must be a date, such as 2026-10-31.');

            return self::FAILURE;
        }

        if ($scannedAt->isFuture()) {
            $this->error('The --date option cannot be later than now.');

            return self::FAILURE;
        }

        $flagged = $flagMissedDsarDeadlinesInteractor->execute($scannedAt);

        $this->info("{$flagged} missed deadline(s) flagged.");

        return self::SUCCESS;
    }
}
