<?php

declare(strict_types=1);

/*
 * RED-2 control for OQ-1-187 rider 3 (Semgrep on the native image, --error):
 * p/php carries php.lang.security.phpinfo-use (pattern `phpinfo(...);`, ERROR)
 * — the first RED planted eval(), which no rule in these packs matches (23 PHP
 * rules ran; p/security-audit has no PHP rule). The quality job's Semgrep step
 * must show 1 finding and exit 1. Removed by the next commit; never merged.
 */
phpinfo();
