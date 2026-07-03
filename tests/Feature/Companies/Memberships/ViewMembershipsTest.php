<?php

namespace Tests\Feature\Companies\Memberships;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ViewMembershipsTest extends MembershipsTestCase
{
    // --- Sad ---

    #[Test]
    public function when_request_has_no_token_then_unauthenticated(): void
    {
        // Given a company with an owner member
        [$company] = $this->aCompanyWithMember('owner');

        // When the roster is requested with no auth token
        $response = $this->getJson('/api/v1/memberships', ['X-Company-Context' => $company->getKey()]);

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    public function when_actor_has_no_membership_in_context_company_then_company_access_is_denied(): void
    {
        // Given a member of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('owner');
        [$companyB] = $this->aCompanyWithMember('owner');

        // When they request the second company's roster
        $response = $this->viewMemberships($outsider, $companyB);

        // Then the company context is denied (AuthorizeCompany membership check —
        // an unknown company id 404s via the resolver instead; built #03 contract)
        $this->assertRefusedWith($response, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    #[DataProvider('provideNonViewingRoles')]
    public function when_non_permitted_role_lists_memberships_then_refused(string $roleCode): void
    {
        // Given a member whose role does not carry users.view (2026-05-24 matrix)
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they request the roster
        $response = $this->viewMemberships($actor, $company);

        // Then the roster is denied for the missing permission
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonViewingRoles(): array
    {
        return [
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }
}
