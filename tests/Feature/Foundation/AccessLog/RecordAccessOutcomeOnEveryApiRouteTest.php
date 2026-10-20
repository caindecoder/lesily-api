<?php

namespace Tests\Feature\Foundation\AccessLog;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\Http\Middleware\RecordAccessOutcome;

class RecordAccessOutcomeOnEveryApiRouteTest extends FoundationTestCase
{
    private const EXEMPT_ROUTES = [
        'GET|HEAD api/documentation',
        'GET|HEAD api/oauth2-callback',
    ];

    #[Test]
    public function when_a_route_is_exempted_then_it_exists_and_skips_the_hook(): void
    {
        // Given the routes the application registers

        // When they are walked
        $walked = $this->walkApiRoutes();

        // Then each exempted route is among them, without the hook
        $this->assertSame(
            array_fill_keys(self::EXEMPT_ROUTES, false),
            array_intersect_key($walked, array_flip(self::EXEMPT_ROUTES)),
        );
    }

    #[Test]
    public function when_the_routes_are_walked_then_every_api_route_records_its_access_outcome(): void
    {
        // Given the routes the application registers

        // When they are walked
        $walked = $this->walkApiRoutes();

        // Then every one outside the exemptions carries the hook
        $this->assertEveryWalkedRouteRecordsItsOutcome($walked);
    }

    protected function walkApiRoutes(): array
    {
        $router = $this->app->make(Router::class);

        return collect($router->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri().'/', 'api/'))
            ->mapWithKeys(fn (Route $route) => [
                implode('|', $route->methods()).' '.$route->uri() => in_array(
                    RecordAccessOutcome::class,
                    $router->gatherRouteMiddleware($route),
                    true,
                ),
            ])
            ->all();
    }

    protected function assertEveryWalkedRouteRecordsItsOutcome(array $walked): void
    {
        $this->assertArrayHasKey('POST api/v1/auth/login', $walked);
        $this->assertSame([], array_keys(array_filter(
            $walked,
            fn (bool $hooked, string $route) => ! $hooked && ! in_array($route, self::EXEMPT_ROUTES, true),
            ARRAY_FILTER_USE_BOTH,
        )));
    }
}
