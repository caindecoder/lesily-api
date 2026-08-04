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

];
