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
        $this->app->bind(ProcessingRegister::class, fn (Application $app): ProcessingRegister => new ProcessingRegister([
            $app->make(AccountsProcessingActivities::class),
            $app->make(CompaniesProcessingActivities::class),
            $app->make(BillingProcessingActivities::class),
            $app->make(AuditProcessingActivities::class),
        ]));
    }
}
