<?php

// Chunk-1 seeds English notification subjects only; Sinhala/Tamil copy and the
// message bodies are deferred to the notification-template-seeding UC (OQ-1-104).
// The locale MECHANISM (per-recipient locale propagation) is covered by UC-1-087.
return [
    'account_security_alert' => [
        'subject' => 'Security alert for :company',
    ],
    'general_account_notice' => [
        'subject' => 'A notification from :company',
    ],
    'password_changed' => [
        'subject' => 'Your password was changed',
    ],
    'team_welcome' => [
        'subject' => 'Welcome to :company',
    ],
];
