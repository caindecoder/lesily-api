<?php

namespace Tests\Feature\Foundation;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Middleware\AuthorizeCompany;
use App\Foundation\Http\Middleware\EnsureCurrentConsent;

/**
 * UC-1-069 — every company route asks for the live policy versions, except the
 * person's data rights and their own 2FA setup.
 */
class RequireCurrentConsentOnEveryCompanyRouteTest extends FoundationTestCase
{
    protected const array EXEMPT_ROUTES = [
        'GET|HEAD api/v1/dsar-requests',
        'GET|HEAD api/v1/dsar-requests/{requestId}',
        'GET|HEAD api/v1/dsar-requests/{requestId}/download',
        'PATCH api/v1/dsar-requests/{requestId}/cancel',
        'POST api/v1/dsar-requests',
        'POST api/v1/auth/2fa/enable',
        'POST api/v1/auth/2fa/confirm',
    ];

    #[Test]
    public function when_an_exemption_is_listed_then_it_exists_and_carries_no_gate(): void
    {
        // Given the routes the application registers

        // When the company routes are walked
        $walked = $this->walkCompanyRoutes();

        // Then each exempted route is a company route without the gate
        $this->assertExemptionsExistWithoutTheGate($walked);
    }

    #[Test]
    public function when_any_company_route_is_registered_then_it_carries_the_gate_unless_exempt(): void
    {
        // Given the routes the application registers

        // When the company routes are walked
        $walked = $this->walkCompanyRoutes();

        // Then every one outside the exemptions carries the gate
        $this->assertEveryWalkedRouteIsGated($walked);
    }

    /**
     * Every api/v1 route behind AuthorizeCompany, keyed `METHODS uri`, mapped to
     * whether it carries the consent gate.
     *
     * @return array<string, bool>
     */
    protected function walkCompanyRoutes(): array
    {
        $router = $this->app->make(Router::class);

        return collect($router->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/')
                && in_array(AuthorizeCompany::class, $router->gatherRouteMiddleware($route), true))
            ->mapWithKeys(fn (Route $route) => [
                implode('|', $route->methods()).' '.$route->uri() => in_array(
                    EnsureCurrentConsent::class,
                    $router->gatherRouteMiddleware($route),
                    true,
                ),
            ])
            ->all();
    }

    /**
     * @param  array<string, bool>  $walked
     */
    protected function assertExemptionsExistWithoutTheGate(array $walked): void
    {
        $expected = array_fill_keys(self::EXEMPT_ROUTES, false);
        $exempted = array_intersect_key($walked, $expected);
        ksort($expected);
        ksort($exempted);

        $this->assertSame($expected, $exempted);
    }

    /**
     * @param  array<string, bool>  $walked
     */
    protected function assertEveryWalkedRouteIsGated(array $walked): void
    {
        $this->assertArrayHasKey('GET|HEAD api/v1/companies/{companyId}', $walked);
        $this->assertSame([], array_keys(array_filter(
            $walked,
            fn (bool $gated, string $route) => ! $gated && ! in_array($route, self::EXEMPT_ROUTES, true),
            ARRAY_FILTER_USE_BOTH,
        )));
    }
}
