<?php

namespace App\Companies\UseCases\Privacy;

use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivity;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivityProvider;

class CompaniesProcessingActivities implements ProcessingActivityProvider
{
    public function activities(): array
    {
        return [
            new ProcessingActivity(
                module: 'companies',
                activity: 'account',
                dataCategories: ['company_identity', 'memberships_and_roles', 'invitations'],
                legalBasis: LegalBasis::Contract,
                thirdParties: [ThirdParty::HostingProvider, ThirdParty::EmailDeliveryService],
            ),
            new ProcessingActivity(
                module: 'companies',
                activity: 'emergency_contact',
                dataCategories: ['emergency_contact'],
                legalBasis: LegalBasis::LegitimateInterests,
                thirdParties: [ThirdParty::HostingProvider],
            ),
            new ProcessingActivity(
                module: 'companies',
                activity: 'consent_records',
                dataCategories: ['accepted_policy_versions', 'ip_address'],
                legalBasis: LegalBasis::LegalObligation,
                thirdParties: [ThirdParty::HostingProvider],
            ),
        ];
    }
}
