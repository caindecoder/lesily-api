<?php

namespace App\Foundation\AccessLog;

use Illuminate\Support\ServiceProvider;
use App\Foundation\AccessLog\IO\Console\PruneAccessLog;

class FoundationAccessLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAccessLog::class]);
        }
    }
}
