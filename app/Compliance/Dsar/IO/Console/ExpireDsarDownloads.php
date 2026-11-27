<?php

namespace App\Compliance\Dsar\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use App\Compliance\Dsar\UseCases\Interactors\ExpireDsarDownloadsInteractor;

#[Signature('dsar:expire-links {--date=}')]
#[Description('Expire DSAR downloads whose seven-day window has closed (defaults to now; --date replays a missed night).')]
class ExpireDsarDownloads extends Command
{
    public function handle(ExpireDsarDownloadsInteractor $expireDsarDownloadsInteractor): int
    {
        try {
            $scannedAt = CarbonImmutable::parse($this->option('date'));
        } catch (InvalidFormatException) {
            $this->error('The --date option must be a date, such as 2026-10-08.');

            return self::FAILURE;
        }

        if ($scannedAt->isFuture()) {
            $this->error('The --date option cannot be later than now.');

            return self::FAILURE;
        }

        $expired = $expireDsarDownloadsInteractor->execute($scannedAt);

        $this->info("{$expired} download(s) expired.");

        return self::SUCCESS;
    }
}
