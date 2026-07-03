<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Billing\Subscriptions\BillingSubscriptionsServiceProvider;
use App\Companies\Memberships\CompaniesMembershipsServiceProvider;

return [
    BillingSubscriptionsServiceProvider::class,
    CompaniesMembershipsServiceProvider::class,
    AppServiceProvider::class,
    TelescopeServiceProvider::class,
];
