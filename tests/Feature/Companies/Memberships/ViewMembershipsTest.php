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

    // --- Happy ---

    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_permitted_role_lists_memberships_then_roster_returned(string $roleCode): void
    {
        // Given a company whose roster is the actor + two named members
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $actor->update(['name' => 'Bhanu Fernando']);
        $anna = User::factory()->named('Anna Perera')->create();
        $chamil = User::factory()->named('Chamil Silva')->create();
        $this->attachMember($company, $anna, $this->seededRole('employee'));
        $this->attachMember($company, $chamil, $this->seededRole('hr_officer'));

        // When they request the roster
        $response = $this->viewMemberships($actor, $company);

        // Then all three memberships are listed with the full row shape, in the
        // default name-ascending order, under the paginated envelope
        $this->assertMembershipListed($response, $actor, $roleCode);
        $this->assertMembershipListed($response, $anna, 'employee');
        $this->assertMembershipListed($response, $chamil, 'hr_officer');
        $this->assertRosterOrder($response, ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva']);
        $this->assertPaginationMeta($response, ['page' => 1, 'per_page' => 15, 'total' => 3, 'last_page' => 1]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideViewingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    public function when_filtered_by_role_then_only_matching_members_listed(): void
    {
        // Given a roster with two HR officers and an employee besides the owner
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $saman = User::factory()->named('Saman Perera')->create();
        $ravi = User::factory()->named('Ravi Silva')->create();
        $employee = User::factory()->create();
        $this->attachMember($company, $saman, $this->seededRole('hr_officer'));
        $this->attachMember($company, $ravi, $this->seededRole('hr_officer'));
        $this->attachMember($company, $employee, $this->seededRole('employee'));

        // When the owner filters the roster by the hr_officer role
        $response = $this->viewMemberships($owner, $company, ['role' => 'hr_officer']);

        // Then only the HR officer memberships are listed
        $this->assertRosterUserIds($response, [$saman->getKey(), $ravi->getKey()]);
    }

    #[Test]
    #[DataProvider('provideStatusFilters')]
    public function when_filtered_by_status_then_matching_members_listed(string $status, bool $expectsRoster): void
    {
        // Given a company with an owner and one more member
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $member = User::factory()->create();
        $this->attachMember($company, $member, $this->seededRole('employee'));

        // When the owner filters the roster by that status
        $response = $this->viewMemberships($owner, $company, ['status' => $status]);

        // Then active lists every membership; invited is empty until the
        // pending-invitation union lands (Task 4, slice §7 — declared s108)
        $this->assertRosterUserIds($response, $expectsRoster ? [$owner->getKey(), $member->getKey()] : []);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideStatusFilters(): array
    {
        return [
            'active lists memberships' => ['active', true],
            'invited empty until the union lands' => ['invited', false],
        ];
    }

    #[Test]
    #[DataProvider('provideSearchTerms')]
    public function when_searched_then_matching_members_listed(string $term): void
    {
        // Given a roster of deterministic identities with one distinct member
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $owner->update(['name' => 'Bhanu Fernando', 'email' => 'bhanu@octane.lk']);
        $alice = User::factory()->named('Alice Gunawardena')->withEmail('alice@octane.lk')->create();
        $nuwan = User::factory()->named('Nuwan Jayasuriya')->withEmail('nuwan@octane.lk')->create();
        $this->attachMember($company, $alice, $this->seededRole('employee'));
        $this->attachMember($company, $nuwan, $this->seededRole('employee'));

        // When the owner searches the roster
        $response = $this->viewMemberships($owner, $company, ['search' => $term]);

        // Then only the matching membership is listed
        $this->assertRosterUserIds($response, [$alice->getKey()]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSearchTerms(): array
    {
        return [
            'by name fragment' => ['Gunaward'],
            'by email fragment' => ['alice@'],
        ];
    }

    #[Test]
    public function when_sorted_by_join_date_descending_then_newest_member_first(): void
    {
        // Given members who joined on three distinct days, named so that join
        // order and name order disagree (a default-sort fallback is observable)
        $this->travelTo(now()->subDays(2));
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $owner->update(['name' => 'Bhanu Fernando']);

        $this->travel(1)->days();
        $anna = User::factory()->named('Anna Perera')->create();
        $this->attachMember($company, $anna, $this->seededRole('employee'));

        $this->travel(1)->days();
        $chamil = User::factory()->named('Chamil Silva')->create();
        $this->attachMember($company, $chamil, $this->seededRole('employee'));
        $this->travelBack();

        // When the owner sorts the roster by newest membership first
        $response = $this->viewMemberships($owner, $company, ['sort' => '-created_at']);

        // Then the most recently joined member leads
        $this->assertRosterOrder($response, ['Chamil Silva', 'Anna Perera', 'Bhanu Fernando']);
    }
}
