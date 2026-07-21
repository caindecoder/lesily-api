<?php

/**
 * CRAP radar (R-053 verdict: radar, not gate) — lists methods whose CRAP score
 * (comp^2 x (1 - cov/100)^3 + comp, Savoia/Evans) meets/exceeds the threshold,
 * as GitHub warning annotations. NEVER fails the build: test-quality gating
 * belongs to the Infection MSI gate (execution != tested, E42-P2); per-method
 * complexity gating belongs to Sonar php:S3776.
 *
 * Usage: php scripts/crap-radar.php [crap4j.xml] [threshold=30]
 */
$file = $argv[1] ?? 'crap4j.xml';
$threshold = (float) ($argv[2] ?? 30);

if (! is_file($file)) {
    echo "crap-radar: no {$file} found — skipping (coverage step may not have emitted it).\n";
    exit(0);
}

$xml = @simplexml_load_file($file);
if ($xml === false || ! isset($xml->methods->method)) {
    // Known php-code-coverage edge: a zero-method run serialises an unparseable
    // crapMethodPercent (sebastianbergmann/php-code-coverage#382).
    echo "crap-radar: {$file} empty or unparseable — skipping.\n";
    exit(0);
}

$offenders = [];
foreach ($xml->methods->method as $method) {
    if ((float) $method->crap >= $threshold) {
        $offenders[] = sprintf(
            '%s::%s crap=%s complexity=%s coverage=%s%%',
            $method->className,
            $method->methodName,
            $method->crap,
            $method->complexity,
            $method->coverage
        );
    }
}

if ($offenders === []) {
    echo "crap-radar: 0 methods at CRAP >= {$threshold}.\n";
    exit(0);
}

echo 'crap-radar: '.count($offenders)." method(s) at CRAP >= {$threshold} (report-only):\n";
foreach ($offenders as $offender) {
    echo "::warning title=CRAP radar::{$offender}\n";
}
exit(0);
