<?php

// The DSAR report's wording (ruling N44). English only for now; the Sinhala
// and Tamil reports add lang/si and lang/ta with the i18n slice (R-052, R-057).
return [
    'report' => [
        'title' => 'Your personal data',
        'company' => 'Company',
        'reference' => 'Request reference',
        'requested' => 'Requested',
        'generated' => 'Generated',
        'guide' => 'This export has two parts. data.json holds all of your data in a machine-readable form. This report shows the same data for reading, with every code explained in words.',
        'contents' => 'Contents',
        'none' => 'None recorded',
    ],
    'fields' => [
        'ownership_transfers' => [
            'transfers' => 'Transfers',
            'cases' => 'Cases',
        ],
        'notifications' => [
            'notifications' => 'Notifications',
            'preferences' => 'Preferences',
        ],
    ],
    'sections' => [
        'profile' => ['title' => 'Your profile'],
        'membership' => ['title' => 'Your membership'],
        'consent_records' => ['title' => 'Consent records'],
        'audit_trail' => ['title' => 'Actions you took'],
        'sign_ins' => ['title' => 'Sign-ins'],
        'invitations' => ['title' => 'Invitations'],
        'ownership_transfers' => ['title' => 'Ownership transfers'],
        'notifications' => ['title' => 'Notifications and preferences'],
    ],
];
