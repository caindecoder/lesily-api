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
 *       collide -> the test errors during DB setup -> counted as a kill.
 *       (Fixed by --threads=1 on the per-PR diff job, and on the full run by
 *       LARAVEL_PARALLEL_TESTING=1 per-thread token DBs — OQ-1-173; this gate
 *       is the backstop either way.)
 *
 *   (A) Agent output rewriting: laravel/pao rewrites PHPUnit's output to JSON in
 *       coding-agent sessions (CLAUDECODE etc.). The JSON matches neither `OK (`
 *       nor `FAILURES!`, so a test that actually PASSED (a surviving mutant) is
 *       read as "not passing" -> counted as a kill. (Fixed by PAO_DISABLE=1
 *       around local Infection; absent in CI, which has no agent env.)
 *
 * This gate reads Infection's JSON report and FAILS if any mutant counted toward
 * MSI (killed / errored / timed-out) shows positive evidence of one of those
 * causes rather than a real assertion. The mechanism-C match is deliberately
 * NARROW: only schema/DDL signatures a concurrent migration produces and neither
 * an application query nor a mutation can (42S01 table-already-exists, a
 * multi-table cache artifact, the framework migrations table, an FK-open race) —
 * so a genuine kill whose output merely contains a SQLSTATE as fixture data (the
 * lock-contention suite asserts on HY000/40001/23000) or a mutation that broke a
 * real single-table reference (`42S02 Table 'x' doesn't exist`) is NOT flagged.
 *
 * It is the permanent replacement for the R-12 interim rule (classify every
 * load-bearing MSI's kill mechanisms by hand). Escaped-mutant detection stays
 * Infection's job. `--self-test` proves the classifier still has teeth (its
 * steady state is always-PASS, so a rotted classifier would otherwise be
 * invisible on every clean report).
 *
 * Usage: php scripts/check-mutation-kills.php [infection.json]
 *        php scripts/check-mutation-kills.php --self-test
 * Exit:  0 = every counted kill assertion-caused (or no report / no kills);
 *        1 = at least one kill has positive false-kill evidence, or a self-test
 *            case regressed.
 */
if (($argv[1] ?? null) === '--self-test') {
    exit(runSelfTest());
}

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

// MSI's numerator counts killed + errored + timed-out mutants (not escaped/
// uncovered/skipped) — a false-kill can land in any of the three, so all three
// are inspected. Escaped mutants legitimately show a passing suite and are
// deliberately NOT scanned.
$counted = [];
foreach (['killed', 'errored', 'timeouted'] as $bucket) {
    if (isset($report[$bucket]) && is_array($report[$bucket])) {
        $counted = array_merge($counted, $report[$bucket]);
    }
}

$offenders = [];
$unverifiable = [];

foreach ($counted as $mutant) {
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
    $count = count($counted);
    echo "check-mutation-kills: {$count} counted kill(s), all assertion-caused (no DDL-race, no passing-suite output).\n";
    exit(0);
}

fwrite(STDERR, 'check-mutation-kills: '.count($offenders)." kill(s) NOT caused by an assertion — the MSI is inflated by infrastructure false-kills:\n");
foreach ($offenders as $offender) {
    fwrite(STDERR, "  - {$offender}\n");
}
fwrite(STDERR, "Fix the topology (the full run needs LARAVEL_PARALLEL_TESTING=1 beside --threads>1 - a mech-C hit there means the token DBs are not engaging; the PR diff job runs --threads=1; local runs go through composer mutate:local with PAO_DISABLE=1), then re-run. See OQ-1-167 + OQ-1-173.\n");
exit(1);

/**
 * The positive false-kill signatures. Returns a human reason, or null when the
 * kill shows a genuine assertion/error failure (including a mutation that made
 * the code fatal, or that broke a real single-table reference — those carry no
 * concurrent-migration or passing-suite marker).
 */
function falseKillReason(string $output): ?string
{
    // Mechanism C — a schema/DDL collision from concurrent migrations against a
    // shared test DB. Keyed ONLY on signatures a concurrent migration produces
    // and that neither an application query nor a mutation can:
    //   - 42S01: a table ALREADY EXISTS (only a concurrent CREATE TABLE);
    //   - `Unknown table 'a,b,...'`: MySQL's multi-table DROP/cache artifact
    //     (a real query, mutated or not, names ONE table);
    //   - the framework `migrations` table erroring (no app query touches it);
    //   - the FK-open race.
    // Deliberately NOT a bare `42S02 Table 'x' doesn't exist`: a mutation that
    // breaks a real table reference produces exactly that and is a GENUINE kill.
    if (preg_match('/SQLSTATE\[42S01\]/', $output) === 1
        || preg_match("/Unknown table '[^']*,/", $output) === 1
        || preg_match('/SQLSTATE\[42S0[12]\][^\n]*\bmigrations\b/', $output) === 1
        || str_contains($output, 'Failed to open the referenced table')) {
        return 'killed by a concurrent-migration DDL error (schema race), not an assertion — un-tokenised parallel-DB race (mechanism C)';
    }

    // Mechanism A — the suite actually PASSED, yet the mutant was recorded
    // killed: only the agent-output-rewrite path produces this, a masked
    // surviving mutant. (Escaped mutants are not scanned, so a legitimate
    // passing suite never reaches here.)
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
    $mutator = is_array($mutant['mutator'] ?? null) ? $mutant['mutator'] : [];
    $name = is_string($mutator['mutatorName'] ?? null) ? $mutator['mutatorName'] : 'unknown mutator';
    $path = is_string($mutator['originalFilePath'] ?? null) ? $mutator['originalFilePath'] : '';
    $line = $mutator['originalStartLine'] ?? null;

    $location = $path !== '' ? basename($path) : 'unknown file';
    if (is_int($line)) {
        $location .= ":{$line}";
    }

    return "{$name} at {$location}";
}

/**
 * Proves the classifier still distinguishes false-kills from genuine kills.
 * Each case: a processOutput string + whether falseKillReason must flag it.
 * Returns a shell exit code.
 */
function runSelfTest(): int
{
    $cases = [
        // [processOutput, mustFlag, label]
        ["SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'roles' already exists", true, 'mech-C 42S01 already-exists'],
        ["SQLSTATE[42S02]: Base table or view not found: 1109 Unknown table 'lesily_test.cache,lesily_test.cache_locks'", true, 'mech-C multi-table cache artifact'],
        // Tokenised DB name (OQ-1-173): asserts the matchers stay DB-name-agnostic.
        ["SQLSTATE[42S02]: Base table or view not found: 1146 Table 'lesily_test_test_3.migrations' doesn't exist", true, 'mech-C migrations table'],
        ['SQLSTATE[HY000]: Failed to open the referenced table \'telescope_entries\'', true, 'mech-C FK-open race'],
        ['{"tool":"phpunit","result":"passed","tests":1,"passed":1}', true, 'mech-A masked survivor (pao json)'],
        ['OK (5 tests, 12 assertions)', true, 'mech-A masked survivor (plain OK)'],
        ['F\n\nThere was 1 failure:\nFailed asserting that false is true.\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.', false, 'genuine assertion kill'],
        // The two false-positive guards this gate had to earn:
        ["1) LockContentionTest::a_lock_wait\nSQLSTATE[HY000]: General error: 1205\nFailed asserting that two strings are equal.\nFAILURES!", false, 'contention-suite kill (SQLSTATE as fixture DATA)'],
        ["SQLSTATE[42S02]: Base table or view not found: 1146 Table 'lesily_test.roles' doesn't exist\nERRORS!", false, 'mutation broke a real single-table reference (genuine kill)'],
    ];

    $failures = 0;
    foreach ($cases as [$output, $mustFlag, $label]) {
        $flagged = falseKillReason($output) !== null;
        if ($flagged !== $mustFlag) {
            $expected = $mustFlag ? 'FLAG' : 'PASS';
            $got = $flagged ? 'FLAGGED' : 'PASSED';
            fwrite(STDERR, "check-mutation-kills --self-test: REGRESSED on [{$label}] — expected {$expected}, got {$got}.\n");
            $failures++;
        }
    }

    if ($failures > 0) {
        fwrite(STDERR, "check-mutation-kills --self-test: {$failures} case(s) regressed — the classifier lost its teeth.\n");

        return 1;
    }

    $total = count($cases);
    echo "check-mutation-kills --self-test: {$total}/{$total} classifier cases correct.\n";

    return 0;
}
