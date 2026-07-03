<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
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

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueryInputs')]
    public function when_query_input_is_invalid_then_invalid(array $query, string $invalidField): void
    {
        // Given an owner of a company
        [$company, $owner] = $this->aCompanyWithMember('owner');

        // When they request the roster with invalid query input
        $response = $this->viewMemberships($owner, $company, $query);

        // Then the input is refused as invalid on that field (api-conventions §10 —
        // per-endpoint filter/sort whitelist, unknown params 422)
        $this->assertValidationRejected($response, $invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidQueryInputs(): array
    {
        return [
            'unknown filter key' => [['team' => 'x'], 'team'],
            'non-whitelisted sort' => [['sort' => 'email'], 'sort'],
            'per_page over max' => [['per_page' => 101], 'per_page'],
            'non-numeric page' => [['page' => 'abc'], 'page'],
        ];
    }

    // --- Edge ---

    #[Test]
    public function when_member_is_soft_deleted_then_absent_from_roster(): void
    {
        // Given a company whose second member was removed (soft-deleted pivot, D5)
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $removed = User::factory()->create();
        $this->aRemovedMembership($company, $removed);

        // When the owner requests the roster
        $response = $this->viewMemberships($owner, $company);

        // Then only the active membership is listed — the removed member is absent
        $this->assertRosterUserIds($response, [$owner->getKey()]);
    }

    #[Test]
    public function when_user_belongs_to_two_companies_then_roster_shows_only_context_company(): void
    {
        // Given a user in companies A and B — staged in B's context so a
        // first-company resolution bug is observable — with an A-only member
        [$companyA, $shared] = $this->aCompanyWithMember('owner');
        $aOnlyMember = User::factory()->create();
        $this->attachMember($companyA, $aOnlyMember, $this->seededRole('employee'));

        $companyB = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->attachMember($companyB, $shared, $this->seededRole('admin'));

        // When they request company B's roster
        $response = $this->viewMemberships($shared, $companyB);

        // Then only company B's membership is listed — nothing leaks from A
        $this->assertRosterUserIds($response, [$shared->getKey()]);
    }
}
