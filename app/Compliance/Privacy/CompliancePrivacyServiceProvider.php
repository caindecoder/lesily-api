<?php

namespace App\Compliance\Privacy;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use App\Compliance\Privacy\UseCases\Register\ProcessingRegister;
use App\Companies\UseCases\Privacy\CompaniesProcessingActivities;
use App\Billing\Invoices\UseCases\Privacy\BillingProcessingActivities;
use App\Foundation\Auth\UseCases\Privacy\AccountsProcessingActivities;
use App\Compliance\Privacy\UseCases\Register\AuditProcessingActivities;

class CompliancePrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProcessingRegister::class, fn (Application $application): ProcessingRegister => new ProcessingRegister([
            $application->make(AccountsProcessingActivities::class),
            $application->make(CompaniesProcessingActivities::class),
            $application->make(BillingProcessingActivities::class),
            $application->make(AuditProcessingActivities::class),
        ]));
    }
}
