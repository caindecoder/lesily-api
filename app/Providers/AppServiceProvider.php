<?php

namespace App\Providers;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The company AuthorizeCompany resolved and validated for this request —
        // the tenant context every tenant-scoped controller consumes
        // (cd-foundation-http §3 step 4; §S11 isolation Layer 3).
        Request::macro('company', function (): Company {
            /** @var Request $this */
            return $this->attributes->get('company');
        });
    }
}
