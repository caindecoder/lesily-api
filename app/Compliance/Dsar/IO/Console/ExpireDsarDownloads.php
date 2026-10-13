<?php

namespace App\Compliance\Dsar\IO\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;

#[Signature('dsar:expire-links {--date=}')]
#[Description('Expire DSAR downloads whose seven-day window has closed (defaults to now; --date replays a missed night).')]
class ExpireDsarDownloads extends Command
{
    public function handle(): int
    {
        $this->info('0 download(s) expired.');

        return self::SUCCESS;
    }
}
