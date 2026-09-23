<?php

declare(strict_types=1);

/*
 * RED-commit control for OQ-1-187 rider 3 (Semgrep on the native image, --error):
 * p/php's eval-use rule must make the quality job's Semgrep step refuse, and the
 * step's own log must show the finding, the rule count and exit 1. Lives under
 * scripts/ (Semgrep scans it; Infection, Codecov and Sonar do not). Removed by the
 * next commit; never merged.
 */
eval((string) ($_GET['code'] ?? ''));
