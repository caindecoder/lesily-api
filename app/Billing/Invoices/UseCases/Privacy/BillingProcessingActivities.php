<?php

namespace App\Billing\Invoices\UseCases\Privacy;

use App\Compliance\Privacy\Enums\LegalBasis;
use App\Compliance\Privacy\Enums\ThirdParty;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivity;
use App\Compliance\Privacy\UseCases\Register\ProcessingActivityProvider;

class BillingProcessingActivities implements ProcessingActivityProvider
{
    public function activities(): array
    {
        return [
            new ProcessingActivity(
                module: 'billing',
                activity: 'invoices',
                dataCategories: ['invoices', 'billing_address', 'payment_references'],
                legalBasis: LegalBasis::Contract,
                thirdParties: [ThirdParty::PaymentProcessor, ThirdParty::HostingProvider],
            ),
        ];
    }
}
