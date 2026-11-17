<?php

namespace App\Foundation\AccessLog\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;

#[Signature('access-log:prune {--date=}')]
#[Description('Delete access-log rows past their retention (defaults to now; --date replays a missed night).')]
class PruneAccessLog extends Command
{
    public function handle(): int
    {
        try {
            $at = CarbonImmutable::parse($this->option('date'))->utc();
        } catch (InvalidFormatException) {
            $this->error('The --date option must be a date, such as 2026-11-17.');

            return self::FAILURE;
        }

        if ($at->isFuture()) {
            $this->error('The --date option cannot be later than now.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
