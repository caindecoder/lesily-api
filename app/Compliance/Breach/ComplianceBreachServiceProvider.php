<?php

namespace App\Compliance\Breach;

use Illuminate\Support\ServiceProvider;
use App\Compliance\Breach\IO\Console\FlagMissedBreachDeadlines;

class ComplianceBreachServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([FlagMissedBreachDeadlines::class]);
        }
    }
}
