<?php

namespace App\Foundation\Auth\UseCases\Privacy;

use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivity;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivityProvider;

class AccountsProcessingActivities implements ProcessingActivityProvider
{
    public function activities(): array
    {
        return [
            new ProcessingActivity(
                module: 'accounts',
                activity: 'sign_in',
                dataCategories: ['name', 'email', 'password', 'two_factor_secret', 'sign_in_sessions'],
                legalBasis: LegalBasis::Contract,
                thirdParties: [ThirdParty::HostingProvider, ThirdParty::EmailDeliveryService, ThirdParty::ErrorMonitoring],
            ),
            new ProcessingActivity(
                module: 'accounts',
                activity: 'security_records',
                dataCategories: ['sign_in_records', 'refused_request_records', 'ip_address'],
                legalBasis: LegalBasis::LegitimateInterests,
                thirdParties: [ThirdParty::HostingProvider],
            ),
        ];
    }
}
