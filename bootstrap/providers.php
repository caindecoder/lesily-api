<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Billing\Subscriptions\BillingSubscriptionsServiceProvider;

return [
    AppServiceProvider::class,
    BillingSubscriptionsServiceProvider::class,
    TelescopeServiceProvider::class,
];
