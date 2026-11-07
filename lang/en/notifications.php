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
    'google_account_linked' => [
        'subject' => 'Google account linked to your Lesily login',
        'body' => "A Google account was just linked to your Lesily login, so you can sign in with Google. If you didn't link it, change your password and unlink Google from your profile.",
    ],
    'google_account_unlinked' => [
        'subject' => 'Google account unlinked from your Lesily login',
        'body' => "Google was just unlinked from your Lesily login, and you were signed out on your other devices. Sign in with your email and password; you can link Google again from your profile. If you didn't unlink it, change your password now.",
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
    'breach_company_notice' => [
        'subject' => 'A personal data breach affects :company',
        'body' => 'Lesily found a breach of personal data that :company keeps in Lesily. It was discovered on :discovered. As the data controller, :company should notify the Data Protection Authority by :deadline. What happened: :description Likely consequences: :consequences Measures taken or planned: :measures Personal data affected: :data_types. People affected: :count.',
        'count_not_known' => 'Not yet known',
    ],
    'breach_filing_requested' => [
        'subject' => ':company asked Lesily to file a personal data breach',
        'body' => ':company asked Lesily to file the personal data breach :incident with the Data Protection Authority for it. Please file it for them.',
    ],
    'breach_filed_with_regulator' => [
        'subject' => 'Lesily filed a personal data breach with the regulator for :company',
        'body' => 'As :company asked, Lesily filed its part of the personal data breach :incident with the Data Protection Authority on :filed_at.',
    ],
    'breach_regulator_notice' => [
        'subject' => 'Personal data breach notification for :company',
        'body' => 'Lesily, the data processor, notifies the Data Protection Authority of a breach of personal data on behalf of :company, the data controller, at its written request. It was discovered on :discovered; the notification deadline is :deadline. What happened: :description Likely consequences: :consequences Measures taken or planned: :measures Personal data affected: :data_types. People affected: :count.',
    ],
    'breach_data_types' => [
        'pii' => 'Personal details',
        'payroll' => 'Payroll records',
        'banking' => 'Bank details',
        'nic' => 'National identity card numbers',
        'epf_etf' => 'EPF and ETF records',
        'medical' => 'Medical records',
        'other' => 'Other personal data',
    ],
];
