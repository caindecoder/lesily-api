<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Policy versions (UC-1-069)
    |--------------------------------------------------------------------------
    |
    | The current ToS / Privacy Policy versions a registrant consents to. Each
    | published revision bumps its version, and every company request is refused
    | until the member accepts it (EnsureCurrentConsent). Consent records are
    | version-stamped and append-only.
    |
    */

    'policies' => [
        'tos' => 'v1.0',
        'privacy' => 'v1.0',
    ],

];
