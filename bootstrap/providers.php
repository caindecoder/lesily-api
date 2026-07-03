<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Billing\Subscriptions\BillingSubscriptionsServiceProvider;
use App\Companies\Memberships\CompaniesMembershipsServiceProvider;

return [
    AppServiceProvider::class,
    BillingSubscriptionsServiceProvider::class,
    CompaniesMembershipsServiceProvider::class,
    TelescopeServiceProvider::class,
];
