<?php

return [

    /*
     * The Lesily web application (dashboard) base URL — the target for links emailed to users
     * (password reset, etc.). Production: https://app.lesily.com; local dev sets CLIENT_URL.
     */
    'url' => $clientUrl = env('CLIENT_URL', 'https://app.lesily.com'),

    /*
     * The web app's reset-password page. The forgot-password email links here, appending the
     * broker's single-use token + the email it is keyed to (both needed by the reset submit).
     */
    'password_reset_url' => env('CLIENT_PASSWORD_RESET_URL', "$clientUrl/reset-password"),
];
