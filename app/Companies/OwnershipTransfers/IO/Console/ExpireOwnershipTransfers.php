<?php

namespace App\Companies\OwnershipTransfers\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Carbon\Exceptions\InvalidFormatException;
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
 * stamps the expiry when it was due rather than when the catch-up ran. The
 * option is guarded here, at the parsing boundary (Task R1, ruling R8): a
 * malformed cutoff is refused with a clear message instead of an uncaught
 * Carbon throw.
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
        $cutoff = $this->resolveCutoff();

        if ($cutoff === null) {
            return self::FAILURE;
        }

        $outcome = $interactor->execute($cutoff);

        // A night with failures says so and exits non-zero (D1) — a deliberate
        // divergence from the LD analog, which prints the error but always
        // exits 0: the scheduler ignores exit codes, a --date replay is run by
        // a human who should see red, and the exit code is the one structured
        // signal a future runner check (#116) can key on.
        if ($outcome->hasFailures()) {
            $this->error("{$outcome->expired} transfer(s) expired, {$outcome->failed} failed.");

            return self::FAILURE;
        }

        $this->info("{$outcome->expired} transfer(s) expired.");

        return self::SUCCESS;
    }

    /**
     * The R8-guarded cutoff: `now()` when `--date` is omitted, the parsed
     * instant when it is valid and not in the future, and NULL — with the
     * refusal already printed — when the boundary refuses it. Null rather than
     * a throw because the refusal is this boundary's own outcome, not an
     * exceptional state (E07: parsing and its messages stay together).
     */
    private function resolveCutoff(): ?CarbonImmutable
    {
        $raw = $this->option('date');

        try {
            $cutoff = CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            $this->error("'{$raw}' is not a valid date/time; nothing was scanned.");

            return null;
        }

        if ($cutoff->isFuture()) {
            $this->error("'{$raw}' is in the future; a replay cutoff must be in the past. Nothing was scanned.");

            return null;
        }

        return $cutoff;
    }
}
