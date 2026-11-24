<?php

namespace Tests\Feature\Compliance\Privacy;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Testing\TestResponse;

abstract class PrivacyTestCase extends TestCase
{
    use FreeTierCompany;

    protected const string KEPT_UNTIL_ACCOUNT_CLOSURE = 'Kept while the company account is open; after it closes, deleted on the account-closure schedule, with financial records kept as the law requires';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->makeFreeTierCompany();
    }

    protected function viewRegister(User $actor, Company $company): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/privacy/processing-register', ['X-Company-Context' => $company->getKey()]);
    }

    protected function viewNotice(User $actor, Company $company): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/privacy/notice', ['X-Company-Context' => $company->getKey()]);
    }

    protected function sendToRegister(string $verb, User $actor, Company $company): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->json($verb, '/api/v1/privacy/processing-register', [], ['X-Company-Context' => $company->getKey()]);
    }
}
