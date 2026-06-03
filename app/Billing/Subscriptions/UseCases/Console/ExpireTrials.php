<?php

namespace App\Billing\Subscriptions\UseCases\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use App\Billing\Subscriptions\UseCases\Interactors\ExpireElapsedTrialsInteractor;

#[Signature('trials:expire {--date=}')]
#[Description('Expire trials whose 30-day window has elapsed (defaults to now; --date overrides for replay).')]
class ExpireTrials extends Command
{
    public function handle(ExpireElapsedTrialsInteractor $interactor): int
    {
        $expired = $interactor->execute(CarbonImmutable::parse($this->option('date')));

        $this->info("{$expired} trial(s) expired.");

        return self::SUCCESS;
    }
}
