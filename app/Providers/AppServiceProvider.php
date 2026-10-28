<?php

namespace App\Providers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use Illuminate\Foundation\DevCommands;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use App\Foundation\Auth\Totp\TotpVerifier;
use Illuminate\Support\Facades\RateLimiter;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\Totp\Google2faTotpVerifier;
use App\Foundation\Auth\Totp\CacheLoginChallengeStore;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;

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

        // One recorder per request: the use case notes the outcome, the middleware writes it.
        $this->app->scoped(AccessLogRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Eloquent strict mode outside production is the N+1 net; production keeps
        // the guards off and has no lazy-load signal yet (conv-plan §2.19 R1/R6).
        Model::shouldBeStrict(! $this->app->isProduction());

        // package.json stays for puppeteer and lefthook, so the framework registers its
        // Vite process; this API builds no assets.
        DevCommands::except('vite');

        // The company AuthorizeCompany resolved and validated for this request —
        // the tenant context every tenant-scoped controller consumes
        // (cd-foundation-http §3 step 4; §S11 isolation Layer 3).
        Request::macro('company', function (): Company {
            /** @var Request $this */
            return $this->attributes->get('company');
        });

        // auth-tier: one 10/min per-IP bucket shared by every route that mounts
        // throttle:auth-tier — the public credential surface (register, login,
        // reset-password, 2FA verify), the authenticated credential checks and the
        // verification-email resend; see routes/api.php. Anti-brute-force per api-1-auth-onboarding §register.
        // Distinct from the per-tenant-tier general-API limiter (OQ-1-098), which
        // resolves the limit from the company's plan.
        RateLimiter::for('auth-tier', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        // Forgot-password stacks two limits: 3/15min keyed by the TARGET email — a reset link
        // must not be spammable into a victim's inbox (questionnaire §S20) — and 10/min keyed
        // by client IP, so one host cannot spray distinct emails into unbounded lookups, token
        // inserts and reset mail (§S14 auth-endpoint parity). The keys are prefixed because the
        // throttle runs before validation and a body email could equal an IP string; for the same
        // reason any non-string email (an array, a number, a boolean) keys as '': validation refuses
        // it next, and casting an array would have crashed the request.
        // Enumeration-safe by design: the endpoint 204s regardless of registration, so the
        // limiter is the only signal and it never differs known vs unknown.
        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perMinutes(15, 3)->by('email:'.Str::lower(
                is_string($email = $request->input('email')) ? $email : ''
            )),
            Limit::perMinute(10)->by('ip:'.(string) $request->ip()),
        ]);

        // One fresh verification link per signed-in person per minute, keyed by the person,
        // not the IP. Mounted before auth-tier, so a refused repeat spends no shared budget.
        RateLimiter::for('email-verification', fn (Request $request) => Limit::perMinute(1)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
