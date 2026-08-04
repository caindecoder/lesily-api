<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SLA windows (SM-1-05 timers, OQ-1-006 lock)
    |--------------------------------------------------------------------------
    |
    | A Requested transfer expires after 30 days without verification (T8).
    | Config-driven per the slice-#11 spec §5; the verified-stage 14-day window
    | joins when the verify task (UC-1-113) lands.
    |
    */

    'request_ttl_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Tenant read presentation (UC-1-125/126)
    |--------------------------------------------------------------------------
    |
    | expected_completion_at is a default SLA hint surfaced to the requesting
    | owner ("within N business days") until real queue-depth metrics exist
    | (api-1-ownership-transfer §7 OQ #4). The support channel is echoed on an
    | open (requested) transfer so the owner can follow up between touchpoints.
    |
    */

    'completion_business_days' => 5,

    'support_email' => env('OWNERSHIP_TRANSFER_SUPPORT_EMAIL', 'support@lesily.com'),

];
