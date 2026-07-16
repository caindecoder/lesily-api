<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Admin Allowlist (INTERIM — slice-plans-billing B2)
    |--------------------------------------------------------------------------
    |
    | Comma-separated user UUIDs granted the /api/v1/admin/* surface via the
    | platform.admin middleware, which answers everyone else with the generic
    | 404 (no-info-leak). Raw explode here (the LD impersonate.php shape);
    | EnsurePlatformAdmin trims and filters at the use-site, so padding and
    | blank segments fail closed. Entries must be LOWERCASE canonical UUIDs —
    | membership is a strict compare against the stored key (a hand-typed
    | uppercase entry fails CLOSED: that admin is locked out, nothing leaks).
    | INTERIM binding: the platform-admin slice
    | replaces this with real admin auth — never extend the allowlist to
    | further admin surfaces without that slice's design (slice §9 pointer).
    |
    */

    'admin_user_ids' => explode(',', (string) env('PLATFORM_ADMIN_IDS', '')),

];
