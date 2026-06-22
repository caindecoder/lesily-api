<?php

namespace App\Providers;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use App\Foundation\Auth\Totp\TotpVerifier;
use Illuminate\Support\Facades\RateLimiter;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\Totp\Google2faTotpVerifier;
use App\Foundation\Auth\Totp\CacheLoginChallengeStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The TOTP boundary (DIP, slice-2fa): production resolves the
        // pragmarx/google2fa adapter; feature tests bind a deterministic double.
        $this->app->bind(TotpVerifier::class, Google2faTotpVerifier::class);

        // The 2FA login-challenge boundary (DIP, slice-2fa #02): bridges login -> /2fa/verify.
        $this->app->bind(LoginChallengeStore::class, CacheLoginChallengeStore::class);
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

        // The public auth surface (register/login/reset/refresh) shares one
        // per-IP limit — anti-brute-force per api-1-auth-onboarding §register
        // (10/min/IP). Distinct from the per-tenant-tier general-API limiter
        // (OQ-1-098), which resolves the limit from the company's plan.
        RateLimiter::for('auth-tier', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
