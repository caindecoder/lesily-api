<?php

namespace App\Billing\Subscriptions;

use Illuminate\Support\ServiceProvider;
use App\Billing\Subscriptions\UseCases\Console\ExpireTrials;
use App\Billing\Subscriptions\UseCases\Repositories\SubscriptionRepositoryInterface;
use App\Billing\Subscriptions\IO\Database\Repositories\EloquentSubscriptionRepository;

class BillingSubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireTrials::class]);
        }
    }
}
