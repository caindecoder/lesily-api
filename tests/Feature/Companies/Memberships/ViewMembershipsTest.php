<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Companies\Memberships\UseCases\Commands\ListMembershipsCommand;

class ViewMembershipsTest extends MembershipsTestCase
{
    use AssertsFilterEmptyValueRejection;
    use FreeTierCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
        $this->owner->update(['name' => 'Bhanu Fernando', 'email' => 'bhanu@contoso.lk']);
        $this->admin->update(['name' => 'Anna Perera', 'email' => 'anna@contoso.lk']);
        $this->employee->update(['name' => 'Chamil Silva', 'email' => 'chamil@contoso.lk']);
    }

    // --- Sad ---

    #[Test]
    public function when_request_has_no_token_then_unauthenticated(): void
    {
        // Given a company with an owner member

        // When the roster is requested with no auth token
        $response = $this->getJson('/api/v1/memberships', ['X-Company-Context' => $this->company->getKey()]);

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    public function when_actor_has_no_membership_in_context_company_then_company_access_is_denied(): void
    {
        // Given a member of one company and a second company they never joined
        $companyB = Company::factory()->create();
        $this->attachMember($companyB, User::factory()->create(), $this->seededRole('owner'));

        // When they request the second company's roster
        $response = $this->viewMemberships($this->owner, $companyB);

        // Then the company context is denied (AuthorizeCompany membership check —
        // an unknown company id 404s via the resolver instead; built #03 contract)
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    #[DataProvider('provideNonViewingRoles')]
    public function when_non_permitted_role_lists_memberships_then_refused(string $roleCode): void
    {
        // Given a member whose role does not carry users.view (2026-05-24 matrix)
        $actor = $this->memberWithRole($roleCode);

        // When they request the roster
        $response = $this->viewMemberships($actor, $this->company);

        // Then the roster is denied for the missing permission
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
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
    public function when_query_input_is_invalid_then_invalid(array $query, string $invalidField, string $message): void
    {
        // Given an owner of a company

        // When they request the roster with invalid query input
        $response = $this->viewMemberships($this->owner, $this->company, $query);

        // Then the input is refused as invalid on that field (api-conventions §10 —
        // per-endpoint filter/sort whitelist, unknown params 422)
        $this->assertValidationRejected($response, $invalidField, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidQueryInputs(): array
    {
        return [
            'unknown filter key' => [['team' => 'x'], 'team', 'This filter is not supported.'],
            'non-whitelisted sort' => [['sort' => 'email'], 'sort', 'The selected sort is invalid.'],
            'per_page over max' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'non-numeric page' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
            'status not whitelisted' => [['status' => 'pending'], 'status', 'The selected status is invalid.'],
            'page below minimum' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'empty role value' => [['role' => ''], 'role', 'The role field must have a value.'],
        ];
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_a_membership_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for a whitelisted filter key — reachable only at
        // the DTO seam (TrimStrings + ConvertEmptyStringsToNull neutralise it over HTTP)

        // When the filter is built directly, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(ListMembershipsCommand::class, $field, $value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        return [
            'empty role' => ['role', ''],
            'empty status' => ['status', ''],
            'empty search' => ['search', ''],
            'empty sort' => ['sort', ''],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'ascii whitespace per_page' => ['per_page', ' '],
            'ascii whitespace search' => ['search', ' '],
        ];
    }

    // --- Edge ---

    #[Test]
    public function when_member_is_soft_deleted_then_absent_from_roster(): void
    {
        // Given a company whose fourth member was removed (soft-deleted pivot, D5)
        $removed = User::factory()->create();
        $this->aRemovedMembership($this->company, $removed);

        // When the owner requests the roster
        $response = $this->viewMemberships($this->owner, $this->company);

        // Then only the active memberships are listed — the removed member is absent
        $this->assertRosterUserIds($response, [$this->owner->getKey(), $this->admin->getKey(), $this->employee->getKey()]);
    }

    #[Test]
    public function when_user_belongs_to_two_companies_then_roster_shows_only_context_company(): void
    {
        // Given a user in companies A and B — staged in B's context so a
        // first-company resolution bug is observable — with an A-only member
        $companyB = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->attachMember($companyB, $this->owner, $this->seededRole('admin'));

        // When they request company B's roster
        $response = $this->viewMemberships($this->owner, $companyB);

        // Then only company B's membership is listed — nothing leaks from A
        $this->assertRosterUserIds($response, [$this->owner->getKey()]);
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given a three-member roster paged two at a time (name order: Anna, Bhanu | Chamil)
        Sanctum::actingAs($this->owner);

        // When a second page is asked for in the BODY rather than the query string
        $response = $this->json('GET', '/api/v1/memberships?per_page=2', ['page' => 2], [
            'X-Company-Context' => $this->company->getKey(),
        ]);

        // Then it is ignored: paging is driven only by the whitelisted, validated
        // query DTO, so the first page comes back (api-conventions §10.1)
        $this->assertRosterOrder($response, ['Anna Perera', 'Bhanu Fernando']);
        $this->assertPaginationMeta($response, ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    }

    #[Test]
    public function when_a_membership_filter_is_built_without_values_then_defaults_apply(): void
    {
        // Given no query values at all

        // When the filter is built at the DTO seam (the roster echo path builds
        // exactly this shape in production — EloquentCompanyUserRepository::findById)
        $filters = ListMembershipsCommand::validateAndCreate([]);

        // Then it constructs with every property at its null default — the
        // filled guard must never fire on an absent key
        $this->assertSame([
            'role' => null,
            'status' => null,
            'search' => null,
            'sort' => null,
            'page' => null,
            'perPage' => null,
        ], $filters->toArray());
    }

    #[Test]
    public function when_search_term_contains_a_like_wildcard_then_it_matches_literally(): void
    {
        // Given two members whose emails differ exactly where the term has a
        // literal underscore (an unescaped LIKE `_` would match both)
        $underscored = User::factory()->named('Sam One')->withEmail('sam_one@octane.lk')->create();
        $lookalike = User::factory()->named('Samsone Perera')->withEmail('samsone@octane.lk')->create();
        $this->attachMember($this->company, $underscored, $this->seededRole('employee'));
        $this->attachMember($this->company, $lookalike, $this->seededRole('employee'));

        // When the owner searches with the literal-underscore term
        $response = $this->viewMemberships($this->owner, $this->company, ['search' => 'sam_one']);

        // Then only the literal match is listed — wildcards in user input are inert
        $this->assertRosterUserIds($response, [$underscored->getKey()]);
    }

    #[Test]
    public function when_per_page_is_at_the_maximum_then_accepted(): void
    {
        // Given a company with an owner

        // When they request the roster at the per_page ceiling (api-conventions §9 max)
        $response = $this->viewMemberships($this->owner, $this->company, ['per_page' => 100]);

        // Then the ceiling value is accepted and echoed in the meta
        $this->assertPaginationMeta($response, ['page' => 1, 'per_page' => 100, 'total' => 3, 'last_page' => 1]);
    }

    #[Test]
    public function when_other_company_has_pending_invitations_then_not_in_roster(): void
    {
        // Given company A's owner and a pending invitation in company B only
        $companyB = Company::factory()->create();
        $ownerB = User::factory()->create();
        $this->attachMember($companyB, $ownerB, $this->seededRole('owner'));
        $this->aPendingInvitation($companyB, $ownerB, 'kasun@contoso.lk');

        // When A's owner requests A's roster
        $response = $this->viewMemberships($this->owner, $this->company);

        // Then B's pending invitation leaks nowhere — only A's own rows are
        // listed (the union's second tenant surface, s110 #13)
        $this->assertRosterUserIds($response, [$this->owner->getKey(), $this->admin->getKey(), $this->employee->getKey()]);
    }

    // --- Happy ---

    /**
     * @param  list<string>  $expectedNames
     */
    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_permitted_role_lists_memberships_then_roster_returned(string $roleCode, string $actorName, array $expectedNames): void
    {
        // Given Contoso's three named members, and the actor under the row's role
        $actor = $this->memberWithRole($roleCode);
        $actor->update(['name' => $actorName]);

        // When they request the roster
        $response = $this->viewMemberships($actor, $this->company);

        // Then every membership is listed with the full row shape, in the
        // default name-ascending order, under the paginated envelope
        $this->assertMembershipListed($response, $this->owner, 'owner');
        $this->assertMembershipListed($response, $this->admin, 'admin');
        $this->assertMembershipListed($response, $this->employee, 'employee');
        $this->assertMembershipListed($response, $actor, $roleCode);
        $this->assertRosterOrder($response, $expectedNames);
        $this->assertPaginationMeta($response, ['page' => 1, 'per_page' => 15, 'total' => count($expectedNames), 'last_page' => 1]);
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function provideViewingRoles(): array
    {
        return [
            'owner' => ['owner', 'Bhanu Fernando', ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva']],
            'admin' => ['admin', 'Anna Perera', ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva']],
            'hr officer' => ['hr_officer', 'Dilan Perera', ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva', 'Dilan Perera']],
            'auditor' => ['auditor', 'Dilan Perera', ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva', 'Dilan Perera']],
        ];
    }

    #[Test]
    public function when_filtered_by_role_then_only_matching_members_listed(): void
    {
        // Given Contoso's three members plus two HR officers and another employee
        $saman = User::factory()->named('Saman Perera')->create();
        $ravi = User::factory()->named('Ravi Silva')->create();
        $employee = User::factory()->create();
        $this->attachMember($this->company, $saman, $this->seededRole('hr_officer'));
        $this->attachMember($this->company, $ravi, $this->seededRole('hr_officer'));
        $this->attachMember($this->company, $employee, $this->seededRole('employee'));

        // When the owner filters the roster by the hr_officer role
        $response = $this->viewMemberships($this->owner, $this->company, ['role' => 'hr_officer']);

        // Then only the HR officer memberships are listed
        $this->assertRosterUserIds($response, [$saman->getKey(), $ravi->getKey()]);
    }

    #[Test]
    public function when_filtered_by_active_status_then_invited_rows_excluded(): void
    {
        // Given a three-member roster and a pending invitation
        $this->aPendingInvitation($this->company, $this->owner, 'kasun@contoso.lk');

        // When the owner filters the roster by the active status
        $response = $this->viewMemberships($this->owner, $this->company, ['status' => 'active']);

        // Then only the memberships are listed — the pending invitation is absent
        $this->assertRosterUserIds($response, [$this->owner->getKey(), $this->admin->getKey(), $this->employee->getKey()]);
    }

    #[Test]
    #[DataProvider('provideNonLiveInvitationStates')]
    public function when_filtered_by_invited_status_then_only_live_pending_invitations_listed(string $nonLiveState): void
    {
        // Given Contoso's active members, one non-live invitation, and one live pending one
        match ($nonLiveState) {
            'accepted' => $this->anAcceptedInvitation($this->company, $this->owner, 'stale@contoso.lk'),
            'revoked' => $this->aRevokedInvitation($this->company, $this->owner, 'stale@contoso.lk'),
            default => $this->anExpiredInvitation($this->company, $this->owner, 'stale@contoso.lk'),
        };
        $this->aPendingInvitation($this->company, $this->owner, 'kasun@contoso.lk');

        // When the owner filters the roster by the invited status
        $response = $this->viewMemberships($this->owner, $this->company, ['status' => 'invited']);

        // Then ONLY the live pending invitation is listed (R1 s110 — live =
        // unaccepted + unexpired); the non-live one and every member are absent
        $this->assertOnlyInvitedRowListed($response, 'kasun@contoso.lk', 'employee');
    }

    /**
     * The non-live invitation states the invited filter must exclude — accepted,
     * expired, and (Task 5) revoked, all indistinguishable from "gone" to the
     * roster (H2/R6 s110).
     *
     * @return array<string, array{string}>
     */
    public static function provideNonLiveInvitationStates(): array
    {
        return [
            'an accepted invitation' => ['accepted'],
            'an expired invitation' => ['expired'],
            'a revoked invitation' => ['revoked'],
        ];
    }

    /**
     * An invitation for $email consumed by its invitee — the accepted state
     * the invited filter must exclude (its member holds an ACTIVE row instead).
     */
    protected function anAcceptedInvitation(Company $company, User $actor, string $email): void
    {
        $token = $this->aPendingInvitation($company, $actor, $email);
        $this->acceptInvitation($token, $this->validNewUserBody())->assertCreated();
    }

    /**
     * An invitation for $email aged past its 7-day window — the live invitation
     * in the test arrange is created AFTER the jump, so it alone stays unexpired.
     */
    protected function anExpiredInvitation(Company $company, User $actor, string $email): void
    {
        $this->aPendingInvitation($company, $actor, $email);
        $this->travel(8)->days();
    }

    #[Test]
    #[DataProvider('provideSearchTerms')]
    public function when_searched_then_matching_members_listed(string $term): void
    {
        // Given a roster of deterministic identities with one distinct member
        $alice = User::factory()->named('Alice Gunawardena')->withEmail('alice@octane.lk')->create();
        $nuwan = User::factory()->named('Nuwan Jayasuriya')->withEmail('nuwan@octane.lk')->create();
        $this->attachMember($this->company, $alice, $this->seededRole('employee'));
        $this->attachMember($this->company, $nuwan, $this->seededRole('employee'));

        // When the owner searches the roster
        $response = $this->viewMemberships($this->owner, $this->company, ['search' => $term]);

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
        $this->backdateMembership($this->company, $this->owner, now()->subDays(2));
        $this->backdateMembership($this->company, $this->admin, now()->subDay());

        // When the owner sorts the roster by newest membership first
        $response = $this->viewMemberships($this->owner, $this->company, ['sort' => '-created_at']);

        // Then the most recently joined member leads
        $this->assertRosterOrder($response, ['Chamil Silva', 'Anna Perera', 'Bhanu Fernando']);
    }

    #[Test]
    public function when_results_exceed_per_page_then_page_two_returns_remainder(): void
    {
        // Given a three-member roster paged two at a time (name order: Anna, Bhanu | Chamil)

        // When the owner requests the second page
        $response = $this->viewMemberships($this->owner, $this->company, ['per_page' => 2, 'page' => 2]);

        // Then the remainder row is returned under the full pagination meta
        $this->assertRosterOrder($response, ['Chamil Silva']);
        $this->assertPaginationMeta($response, ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    }

    #[Test]
    public function when_sorted_by_name_explicitly_then_members_listed_alphabetically(): void
    {
        // Given members whose join order, either way, disagrees with name order
        $this->backdateMembership($this->company, $this->owner, now()->subDays(2));
        $this->backdateMembership($this->company, $this->employee, now()->subDay());

        // When the owner sorts the roster by name explicitly (the whitelisted token,
        // not the default fallback)
        $response = $this->viewMemberships($this->owner, $this->company, ['sort' => 'name']);

        // Then the members are listed alphabetically
        $this->assertRosterOrder($response, ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva']);
    }

    #[Test]
    public function when_pending_invitation_exists_then_roster_shows_invited_row(): void
    {
        // Given Contoso's three members and a pending invitation
        $this->aPendingInvitation($this->company, $this->owner, 'kasun@contoso.lk');

        // When the owner requests the unfiltered roster
        $response = $this->viewMemberships($this->owner, $this->company);

        // Then the pending invitation appears as an invited row, placed by its
        // email in the name-ascending order (the sort_name fallback — s110 sort
        // ruling: 'Bhanu…' < 'kasun@…'), and counted by the pagination meta
        $this->assertInvitedRowListed($response, 'kasun@contoso.lk', 'employee');
        $this->assertPaginationMeta($response, ['page' => 1, 'per_page' => 15, 'total' => 4, 'last_page' => 1]);
        $this->assertRosterOrder($response, ['Anna Perera', 'Bhanu Fernando', 'Chamil Silva', null]);
    }

    /**
     * The invited row for $email is listed AND is the response's only row —
     * every other roster candidate (members, non-live invitations) is excluded.
     */
    protected function assertOnlyInvitedRowListed(TestResponse $response, string $email, string $roleCode): void
    {
        $this->assertInvitedRowListed($response, $email, $roleCode);
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * The roster carries the pending invitation for $email as an invited row
     * (s110 R4a/R4b): id = the invitation UUID (D5b), a null-identity user{}
     * holding the invitation email, the invitation's role, status invited with
     * invited_at set and last_active_at null.
     */
    protected function assertInvitedRowListed(TestResponse $response, string $email, string $roleCode): void
    {
        $response->assertOk();

        /** @var array<string, mixed>|null $row */
        $row = $response->collect('data')->firstWhere('user.email', $email);

        $this->assertNotNull($row, "No invited roster row for {$email}.");
        $invitation = Invitation::query()->where('email', $email)->sole();
        $this->assertSame($invitation->getKey(), $row['id']);
        $this->assertSame(['id' => null, 'name' => null, 'email' => $email], $row['user']);
        $this->assertSame($roleCode, $row['role']['code']);
        $this->assertSame('invited', $row['status']);
        $this->assertNotNull($row['invited_at']);
        $this->assertNull($row['last_active_at']);
    }

    #[Test]
    public function when_sorted_by_join_date_descending_then_newest_invitation_first(): void
    {
        // Given members who joined on earlier days and an invitation issued today
        $this->backdateMembership($this->company, $this->owner, now()->subDays(3));
        $this->backdateMembership($this->company, $this->admin, now()->subDays(2));
        $this->backdateMembership($this->company, $this->employee, now()->subDay());
        $this->aPendingInvitation($this->company, $this->owner, 'kasun@contoso.lk');

        // When the owner sorts the roster by newest first
        $response = $this->viewMemberships($this->owner, $this->company, ['sort' => '-created_at']);

        // Then the invited row leads — the invitation's issue time rides the
        // union's shared created_at sort column
        $this->assertRosterOrder($response, [null, 'Chamil Silva', 'Anna Perera', 'Bhanu Fernando']);
    }

    #[Test]
    public function when_filtered_by_role_then_invited_rows_with_that_role_listed(): void
    {
        // Given an owner, an hr_officer member, and a pending AUDITOR invitation
        $hr = User::factory()->create();
        $this->attachMember($this->company, $hr, $this->seededRole('hr_officer'));
        $this->invite($this->owner, $this->company, ['email' => 'kasun@contoso.lk', 'roleCode' => 'auditor'])->assertCreated();

        // When the owner filters the roster by the AUDITOR role
        $response = $this->viewMemberships($this->owner, $this->company, ['role' => 'auditor']);

        // Then the invited row is the only match — the role filter spans both
        // union arms (uniform filters, s110)
        $this->assertOnlyInvitedRowListed($response, 'kasun@contoso.lk', 'auditor');
    }

    #[Test]
    public function when_searched_by_invitee_email_then_invited_row_listed(): void
    {
        // Given a member whose identity never matches the term + two pending
        // invitations, only one of which carries the term
        $this->aPendingInvitation($this->company, $this->owner, 'kasun@contoso.lk');
        $this->aPendingInvitation($this->company, $this->owner, 'nimal@contoso.lk');

        // When the owner searches by the invitee's email fragment
        $response = $this->viewMemberships($this->owner, $this->company, ['search' => 'kasun@']);

        // Then the invited row is the only match — search spans both union arms
        // by email (an invited row has no name to match; uniform filters, s110)
        $this->assertOnlyInvitedRowListed($response, 'kasun@contoso.lk', 'employee');
    }

    #[Test]
    public function when_invitation_accepted_then_invited_row_replaced_by_active_row(): void
    {
        // Given a pending invitation that the invitee then accepts
        $token = $this->aPendingInvitation($this->company, $this->owner, 'kasun@contoso.lk');
        $invitationId = Invitation::query()->where('email', 'kasun@contoso.lk')->sole()->getKey();
        $this->acceptInvitation($token, $this->validNewUserBody())->assertCreated();

        // When the owner requests the roster
        $response = $this->viewMemberships($this->owner, $this->company);

        // Then the SAME row id is now active with no invited duplicate, and the
        // roster counts exactly the four members
        $this->assertInvitedRowBecameActive($response, $invitationId, 'kasun@contoso.lk');
        $this->assertPaginationMeta($response, ['page' => 1, 'per_page' => 15, 'total' => 4, 'last_page' => 1]);
    }

    /**
     * The accepted invitation kept its row id across the flip (D5b identity —
     * invitation UUID == pivot UUID): the row is now active under the joiner's
     * email and no invited row remains anywhere in the response.
     */
    protected function assertInvitedRowBecameActive(TestResponse $response, string $invitationId, string $email): void
    {
        $response->assertOk();

        /** @var array<string, mixed>|null $row */
        $row = $response->collect('data')->firstWhere('id', $invitationId);

        $this->assertNotNull($row, 'The accepted invitation must keep its row id (D5b).');
        $this->assertSame(['active', $email], [$row['status'], $row['user']['email']]);
        $this->assertCount(0, $response->collect('data')->where('status', 'invited'));
    }

    #[Test]
    public function when_owner_views_roster_then_exact_contract_returned(): void
    {
        // Given Contoso's three named members

        // When the owner requests the roster
        $response = $this->viewMemberships($this->owner, $this->company);

        // Then the envelope is EXACTLY the slice contract — no leaked pivot columns
        // (role_id/company_id/deleted_at) and no Laravel default links/meta blocks
        $response->assertExactJson([
            'data' => [
                [
                    'id' => $this->membershipIdOf($this->company, $this->admin),
                    'user' => ['id' => $this->admin->getKey(), 'name' => 'Anna Perera', 'email' => 'anna@contoso.lk'],
                    'role' => ['code' => 'admin', 'name' => $this->seededRole('admin')->name],
                    'status' => 'active',
                    'invited_at' => null,
                    'last_active_at' => null,
                ],
                [
                    'id' => $this->membershipIdOf($this->company, $this->owner),
                    'user' => ['id' => $this->owner->getKey(), 'name' => 'Bhanu Fernando', 'email' => 'bhanu@contoso.lk'],
                    'role' => ['code' => 'owner', 'name' => $this->seededRole('owner')->name],
                    'status' => 'active',
                    'invited_at' => null,
                    'last_active_at' => null,
                ],
                [
                    'id' => $this->membershipIdOf($this->company, $this->employee),
                    'user' => ['id' => $this->employee->getKey(), 'name' => 'Chamil Silva', 'email' => 'chamil@contoso.lk'],
                    'role' => ['code' => 'employee', 'name' => $this->seededRole('employee')->name],
                    'status' => 'active',
                    'invited_at' => null,
                    'last_active_at' => null,
                ],
            ],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 15, 'total' => 3, 'last_page' => 1]],
        ]);
    }
}
