<?php

namespace App\Compliance\Dsar\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use App\Compliance\Dsar\UseCases\Interactors\ExpireDsarDownloadsInteractor;

#[Signature('dsar:expire-links {--date=}')]
#[Description('Expire DSAR downloads whose seven-day window has closed (defaults to now; --date replays a missed night).')]
class ExpireDsarDownloads extends Command
{
    public function handle(ExpireDsarDownloadsInteractor $interactor): int
    {
        $expired = $interactor->execute(CarbonImmutable::now());

        $this->info("{$expired} download(s) expired.");

        return self::SUCCESS;
    }
}
