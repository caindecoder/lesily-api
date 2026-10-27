<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;

class ViewRolesTest extends MembershipsTestCase
{
    #[Test]
    public function when_outsider_lists_roles_then_company_access_denied(): void
    {
        // Given an owner of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('owner');
        [$otherCompany] = $this->aCompanyWithMember('owner');

        // When they list the second company's roles
        $response = $this->viewRoles($outsider, $otherCompany);

        // Then the company context is denied
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    protected function viewRoles(User $actor, Company $company): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/roles', ['X-Company-Context' => $company->getKey()]);
    }
}
