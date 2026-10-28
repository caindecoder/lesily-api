<?php

// The processing register's (UC-1-073) and the privacy notice's (UC-1-074)
// wording. English only for now; the Sinhala and Tamil registers add lang/si
// and lang/ta. The wording awaits legal review.
$keptUntilAccountClosure = 'Kept while the company account is open; after it closes, deleted on the account-closure schedule, with financial records kept as the law requires';

return [
    'modules' => [
        'accounts' => 'Accounts',
        'companies' => 'Companies',
        'billing' => 'Billing',
        'audit' => 'Audit and privacy',
    ],

    'activities' => [
        'accounts' => [
            'sign_in' => [
                'name' => 'Sign-in and account',
                'purpose' => 'Sign members in and keep their accounts secure',
                'retention' => 'While the account is open; after it closes, deleted on the account-closure schedule',
            ],
            'security_records' => [
                'name' => 'Sign-in and refusal records',
                'purpose' => 'Protect accounts and the service against misuse',
                'retention' => '1 year on Free and Starter, 3 years on Pro, 7 years on Enterprise',
            ],
        ],
        'companies' => [
            'account' => [
                'name' => 'Company account',
                'purpose' => "Run the company's account, its members and their roles",
                'retention' => $keptUntilAccountClosure,
            ],
            'emergency_contact' => [
                'name' => "Owner's emergency contact",
                'purpose' => 'Keep the company running if its owner dies or cannot act',
                'retention' => $keptUntilAccountClosure,
            ],
            'consent_records' => [
                'name' => 'Consent records',
                'purpose' => 'Prove which policy versions each member accepted',
                'retention' => $keptUntilAccountClosure,
            ],
        ],
        'billing' => [
            'invoices' => [
                'name' => 'Invoices',
                'purpose' => "Bill the company's subscription",
                'retention' => '10 years (Companies Act), anonymized when the company is deleted',
            ],
        ],
        'audit' => [
            'audit_trail' => [
                'name' => 'Audit trail',
                'purpose' => 'Account for every change to personal data',
                'retention' => 'Kept indefinitely; never deleted',
            ],
            'access_requests' => [
                'name' => 'Access requests',
                'purpose' => "Answer members' requests for a copy of their data",
                'retention' => 'The export is deleted 7 days after it is ready; the request is kept with the audit trail',
            ],
        ],
    ],

    'data_categories' => [
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password (hashed)',
        'two_factor_secret' => 'Two-factor secret (encrypted)',
        'sign_in_sessions' => 'Sign-in sessions',
        'sign_in_records' => 'Sign-in records',
        'refused_request_records' => 'Refused-request records',
        'ip_address' => 'IP address',
        'company_identity' => 'Company identity (name, BR number, EPF number, country)',
        'memberships_and_roles' => 'Memberships and roles',
        'invitations' => 'Invitations (email address)',
        'emergency_contact' => "Emergency contact's name, phone and relationship (encrypted)",
        'accepted_policy_versions' => 'Accepted policy versions',
        'invoices' => 'Invoices',
        'billing_address' => 'Billing address',
        'payment_references' => 'Payment references',
        'data_changes' => 'Who changed what, and when',
        'access_requests' => 'Access requests',
        'access_request_exports' => 'Access request exports',
    ],

    'legal_bases' => [
        'contract' => 'Contract',
        'legal_obligation' => 'Legal obligation',
        'legitimate_interests' => 'Legitimate interests',
    ],

    'third_parties' => [
        'hosting_provider' => 'Hosting provider',
        'payment_processor' => 'Payment processor',
        'error_monitoring' => 'Error monitoring',
        'email_delivery_service' => 'Email delivery service',
    ],

    'countries' => [
        'LK' => 'Sri Lanka',
    ],

    'notice' => [
        'controller' => [
            'heading' => 'Who controls your data',
            'company' => ':name, BR number :br_number, :country',
            'contact' => 'Contact: :name, :email',
        ],
        'dpo' => [
            'heading' => 'Data protection officer',
            'body' => 'No data protection officer has been designated; contact the controller above',
        ],
        'purposes' => ['heading' => 'Purposes and legal basis'],
        'legitimate_interests' => ['heading' => 'Legitimate interests'],
        'data_categories' => ['heading' => 'Personal data we process'],
        'withdraw_consent' => ['heading' => 'Withdrawing consent'],
        'recipients' => ['heading' => 'Who receives your data'],
        'cross_border_transfer' => ['heading' => 'Where your data is processed'],
        'retention' => ['heading' => 'How long it is kept'],
        'data_subject_rights' => ['heading' => 'Your rights and how to use them'],
        'complaint' => ['heading' => 'Complaints'],
        'statutory_or_contractual' => ['heading' => 'Is providing the data required?'],
        'automated_decisions' => ['heading' => 'Automated decisions'],
    ],
];
