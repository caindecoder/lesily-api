<?php

namespace App\Compliance\Privacy\UseCases\Register;

use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;

class AuditProcessingActivities implements ProcessingActivityProvider
{
    public function activities(): array
    {
        return [
            new ProcessingActivity(
                module: 'audit',
                activity: 'audit_trail',
                dataCategories: ['data_changes'],
                legalBasis: LegalBasis::LegalObligation,
                thirdParties: [ThirdParty::HostingProvider],
            ),
            new ProcessingActivity(
                module: 'audit',
                activity: 'access_requests',
                dataCategories: ['access_requests', 'access_request_exports'],
                legalBasis: LegalBasis::LegalObligation,
                thirdParties: [ThirdParty::HostingProvider],
            ),
        ];
    }
}
