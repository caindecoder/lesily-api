<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsAccessLog;

abstract class FoundationTestCase extends TestCase
{
    use AssertsAccessLog;

    /**
     * $user calls a gated probe route in routes/testing.php in $company's context.
     */
    protected function attemptGatedAction(string $route, User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson(
            "/api/_test/{$route}",
            ['X-Company-Context' => $company->getKey()],
        );
    }
}
