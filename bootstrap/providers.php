<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Compliance\Dsar\ComplianceDsarServiceProvider;
use App\Billing\Invoices\BillingInvoicesServiceProvider;
use App\Compliance\Breach\ComplianceBreachServiceProvider;
use App\Compliance\Privacy\CompliancePrivacyServiceProvider;
use App\Foundation\AccessLog\FoundationAccessLogServiceProvider;
use App\Billing\Subscriptions\BillingSubscriptionsServiceProvider;
use App\Companies\Memberships\CompaniesMembershipsServiceProvider;

return [
    AppServiceProvider::class,
    BillingInvoicesServiceProvider::class,
    BillingSubscriptionsServiceProvider::class,
    CompaniesMembershipsServiceProvider::class,
    ComplianceBreachServiceProvider::class,
    ComplianceDsarServiceProvider::class,
    CompliancePrivacyServiceProvider::class,
    FoundationAccessLogServiceProvider::class,
    TelescopeServiceProvider::class,
];
