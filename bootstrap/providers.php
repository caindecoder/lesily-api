<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Billing\Invoices\BillingInvoicesServiceProvider;
use App\Billing\Subscriptions\BillingSubscriptionsServiceProvider;
use App\Companies\Memberships\CompaniesMembershipsServiceProvider;

return [
    BillingInvoicesServiceProvider::class,
    BillingSubscriptionsServiceProvider::class,
    CompaniesMembershipsServiceProvider::class,
    AppServiceProvider::class,
    TelescopeServiceProvider::class,
];
