<?php

// English subjects and bodies for every notification type; Sinhala/Tamil copy
// is deferred to the notification-template-seeding UC (OQ-1-104). The locale
// MECHANISM (per-recipient locale propagation) is covered by UC-1-087.
return [
    'account_security_alert' => [
        'subject' => 'Security alert for :company',
        'body' => "There was security activity on your Lesily account in :company. If it wasn't you, change your password now.",
    ],
    'general_account_notice' => [
        'subject' => 'A notification from :company',
        'body' => 'You have a new notice about your account in :company. Sign in to Lesily to read it.',
    ],
    'password_changed' => [
        'subject' => 'Your password was changed',
        'body' => "Your Lesily password was just changed. If you didn't change it, reset your password now.",
    ],
    'team_welcome' => [
        'subject' => 'Welcome to :company',
        'body' => "You've joined :company on Lesily. Sign in to get started.",
    ],
    'ownership_received' => [
        'subject' => 'You are now the owner of :company',
        'body' => 'You are now the owner of :company on Lesily, including its billing.',
    ],
    'dsar_export_ready' => [
        'subject' => 'A personal data export for :company is ready',
        'body' => 'The personal data export requested in :company is ready. The person it is about can download it while signed in to Lesily, for 7 days.',
    ],
    'dsar_export_failed' => [
        'subject' => 'A personal data export for :company could not be prepared',
        'body' => 'The personal data export requested in :company could not be prepared. Please submit a new request.',
    ],
    'dsar_request_withdrawn' => [
        'subject' => 'A personal data export request for :company was withdrawn',
        'body' => 'The personal data export request in :company was withdrawn before it was ready. A new request can be made at any time.',
    ],
    'dsar_deadline_missed' => [
        'subject' => 'A personal data request in :company missed its 30-day deadline',
        'body' => 'The personal data request :request in :company was due on :deadline and is not yet complete. Please follow it up with the company.',
    ],
];
