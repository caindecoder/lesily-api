<?php

namespace App\Companies\OwnershipTransfers\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use App\Companies\OwnershipTransfers\UseCases\Interactors\ExpireStaleOwnershipTransfersInteractor;

/**
 * SM-1-05 T8/T9 — the daily scan that ends transfers past their SLA window.
 * A pure boundary: it parses the cutoff and delegates, so delivery stays a thin
 * appendix (E07). Modelled on the built `trials:expire` / `invitations:expire`
 * pair. The `--date=` option comes from `trials:expire` alone — `invitations:expire`
 * takes no options and always runs at `now()` — and it makes the cutoff explicit
 * so a night the scheduler missed can be replayed at the instant it should have
 * used. That cutoff is threaded all the way to the recorded event, so a replay
 * stamps the expiry when it was due rather than when the catch-up ran.
 *
 * No `Command` suffix, deliberately (ruling M5): both built scans and both
 * LeaveDates analogs name theirs `ExpireX`, and the suffix would sit this class
 * one plural `s` away from the spatie/laravel-data `ExpireOwnershipTransferCommand`.
 */
#[Signature('ownership-transfers:expire-stale {--date=}')]
#[Description('Expire ownership transfers past their SLA window (defaults to now; --date overrides for replay).')]
class ExpireOwnershipTransfers extends Command
{
    public function handle(ExpireStaleOwnershipTransfersInteractor $interactor): int
    {
        $expired = $interactor->execute(CarbonImmutable::parse($this->option('date')));

        $this->info("{$expired} transfer(s) expired.");

        return self::SUCCESS;
    }
}
