<?php

/**
 * Mutation kill-verdict integrity gate (OQ-1-167). Infection decides "killed vs
 * survived" by scraping PHPUnit's console output for `OK (` / `FAILURES!`
 * (Infection\...\PhpUnitAdapter::testsPass). Two things can make it record a
 * KILL that no assertion earned — a false green that inflates MSI and masks
 * real escaped mutants:
 *
 *   (C) Un-tokenised parallel DBs: Infection's threads set TEST_TOKEN but NOT
 *       LARAVEL_PARALLEL_TESTING, so Laravel never switches DB and every thread
 *       migrates the same test database. Concurrent RefreshDatabase migrations
 *       collide -> SQLSTATE -> the test errors before it can assert -> counted
 *       as a kill. (Fixed by --threads=1 on the mutation jobs.)
 *
 *   (A) Agent output rewriting: laravel/pao rewrites PHPUnit's output to JSON in
 *       coding-agent sessions (CLAUDECODE etc.). The JSON matches neither `OK (`
 *       nor `FAILURES!`, so a test that actually PASSED (a surviving mutant) is
 *       read as "not passing" -> counted as a kill. (Fixed by PAO_DISABLE=1
 *       around local Infection; absent in CI, which has no agent env.)
 *
 * This gate reads Infection's JSON report and FAILS if any killed mutant was
 * killed by positive evidence of one of those causes rather than by an
 * assertion: a SQLSTATE in the kill output (infra error), or output showing the
 * suite actually PASSED (`"result":"passed"` / `OK (...tests`). It is the
 * permanent replacement for the R-12 interim rule (classify every load-bearing
 * MSI's kill mechanisms by hand). Escaped-mutant detection stays Infection's job.
 *
 * Usage: php scripts/check-mutation-kills.php [infection.json]
 * Exit:  0 = every kill assertion-caused (or no report / no kills);
 *        1 = at least one kill has positive false-kill evidence.
 */
$file = $argv[1] ?? 'infection.json';

if (! is_file($file)) {
    // Infection may have been skipped legitimately (no changed app/ files, empty
    // suite, or the no-mutations guard) — those steps exit 0 before writing a
    // report. Nothing to validate; do not invent a failure.
    echo "check-mutation-kills: no {$file} found — skipping (Infection did not emit a report).\n";
    exit(0);
}

$report = json_decode((string) file_get_contents($file), true);

if (! is_array($report) || ! array_key_exists('killed', $report)) {
    fwrite(STDERR, "check-mutation-kills: {$file} is not a readable Infection JSON report.\n");
    exit(1);
}

$killed = is_array($report['killed']) ? $report['killed'] : [];

$offenders = [];
$unverifiable = [];

foreach ($killed as $mutant) {
    if (! is_array($mutant)) {
        continue;
    }

    $output = is_string($mutant['processOutput'] ?? null) ? $mutant['processOutput'] : '';
    $label = describeMutant($mutant);

    if ($output === '') {
        // A kill we cannot inspect is not proof of a kill — surface it, but do
        // not fail the build on absence of evidence (only on positive evidence).
        $unverifiable[] = $label;

        continue;
    }

    if ($reason = falseKillReason($output)) {
        $offenders[] = "{$label} — {$reason}";
    }
}

foreach ($unverifiable as $label) {
    echo "::warning title=Mutation kill-verdict::{$label} — killed with no captured output; could not verify an assertion caused it.\n";
}

if ($offenders === []) {
    $count = count($killed);
    echo "check-mutation-kills: {$count} kill(s), all assertion-caused (no SQLSTATE, no passing-suite output).\n";
    exit(0);
}

fwrite(STDERR, 'check-mutation-kills: '.count($offenders)." kill(s) NOT caused by an assertion — the MSI is inflated by infrastructure false-kills:\n");
foreach ($offenders as $offender) {
    fwrite(STDERR, "  - {$offender}\n");
}
fwrite(STDERR, "Fix the topology (mutation jobs run --threads=1; run local Infection with PAO_DISABLE=1), then re-run. See OQ-1-167.\n");
exit(1);

/**
 * The positive false-kill signatures. Returns a human reason, or null when the
 * kill shows a genuine assertion/error failure (including a mutation that made
 * the code fatal — that is a real kill and carries no passing/SQLSTATE marker).
 */
function falseKillReason(string $output): ?string
{
    // Mechanism C is a schema/DDL collision from concurrent migrations against a
    // shared test DB: 42S01 (table already exists) / 42S02 (table/view missing),
    // or the FK-open race. These object-level errors mean the test errored during
    // DB setup — not that an assertion caught the mutation. Deliberately NARROW:
    // NOT a bare `SQLSTATE` match, because the lock-contention suite asserts on
    // HY000/40001/23000 messages as DATA (tests/Concerns/InducesDatabaseFailures),
    // and a mutation failing one of those is a genuine assertion kill.
    if (preg_match('/SQLSTATE\[42S0[12]\]/', $output) === 1
        || str_contains($output, 'Failed to open the referenced table')) {
        return 'killed by a schema/DDL migration-race error (SQLSTATE 42S0x / FK-open), not an assertion — un-tokenised parallel-DB race (mechanism C)';
    }

    // The suite actually PASSED, yet the mutant was recorded killed: only the
    // agent-output-rewrite path (mechanism A) produces this — a masked survivor.
    if (str_contains($output, '"result":"passed"')) {
        return 'killed while the suite reported PASSED — a masked surviving mutant (agent output rewrite, mechanism A)';
    }

    if (preg_match('/\bOK \(\d+ test/', $output) === 1) {
        return 'killed while PHPUnit reported OK — a masked surviving mutant (mechanism A)';
    }

    return null;
}

/**
 * @param  array<string, mixed>  $mutant
 */
function describeMutant(array $mutant): string
{
    $mutator = $mutant['mutator'] ?? [];
    $name = is_string($mutator['mutatorName'] ?? null) ? $mutator['mutatorName'] : 'unknown mutator';
    $path = is_string($mutator['originalFilePath'] ?? null) ? $mutator['originalFilePath'] : '';
    $line = $mutator['originalStartLine'] ?? null;

    $location = $path !== '' ? basename($path) : 'unknown file';
    if (is_int($line)) {
        $location .= ":{$line}";
    }

    return "{$name} at {$location}";
}
