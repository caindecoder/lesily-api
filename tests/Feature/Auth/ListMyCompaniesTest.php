<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ListMyCompaniesTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
    }

    // Sad — the switcher list is guarded: a request with no token is rejected by
    // the Sanctum guard before any listing runs (proves auth:sanctum, UC-1-034).
    #[Test]
    public function when_request_has_no_token_then_unauthenticated(): void
    {
        // Given no authenticated token

        // When the companies list is requested
        $response = $this->getJson('/api/v1/me/companies');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Sad — a removed membership (soft-deleted pivot, UC-1-024) must not resurface
    // in the switcher: the list reads live pivots only. Eloquent's SoftDeletes scope
    // does not reach pivot-table reads, so the query must filter deleted_at itself.
    #[Test]
    public function when_membership_was_removed_then_that_company_absent_from_list(): void
    {
        // Given a user with a live membership in Acme and a removed one in Beta
        $user = $this->aRegisteredUser();
        $this->aMembershipFor($user, Company::factory()->named('Acme')->create());
        $this->aRemovedMembershipIn(Company::factory()->named('Beta')->create(), $user);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then only Acme is listed — the removed Beta membership is absent
        $this->assertCompaniesListed($response, ['Acme']);
    }

    // Sad — the list is scoped to the requester: another user's memberships are
    // never exposed. Account-level read (no company context), so own-only IS the
    // tenant boundary here (the sessions-list precedent).
    #[Test]
    public function when_other_users_have_companies_then_only_my_memberships_listed(): void
    {
        // Given two users, each a member of their own company
        $me = $this->aRegisteredUser();
        $other = $this->aRegisteredUser('bob@contoso.lk');
        $this->aMembershipFor($me, Company::factory()->named('Mine')->create());
        $this->aMembershipFor($other, Company::factory()->named('Theirs')->create());

        // When I list my companies
        $response = $this->listCompanies($me);

        // Then only my membership is listed — the other user's company is absent
        $this->assertCompaniesListed($response, ['Mine']);
    }

    // Edge — an invitation alone is not a membership: a company that has only
    // INVITED the user (a live-pending invitations row, no accepted pivot) must
    // not appear. The roster's invited-row union (UC-1-017) is deliberately not
    // replicated here — the switcher reads live pivots only.
    #[Test]
    public function when_only_a_pending_invitation_exists_then_that_company_absent_from_list(): void
    {
        // Given a member of Acme who is merely invited to Beta
        $user = $this->aRegisteredUser();
        $this->aMembershipFor($user, Company::factory()->named('Acme')->create());
        $this->anInvitationRowFor(Company::factory()->named('Beta')->create(), $user->email);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then only Acme is listed — the invited-only Beta is absent
        $this->assertCompaniesListed($response, ['Acme']);
    }

    // Edge — every live membership is listed regardless of the company's status:
    // a trial-expired company stays in the switcher carrying its status, so the
    // client can render the read-only/expired state screens (UC-1-034 3a/3b).
    #[Test]
    public function when_a_company_is_trial_expired_then_listed_with_its_status(): void
    {
        // Given a member of a trial-expired company
        $user = $this->aRegisteredUser();
        $this->aMembershipFor($user, Company::factory()->named('Acme')->trialExpired()->create());

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then the company is listed carrying its status
        $this->assertCompaniesListed($response, ['Acme']);
        $response->assertJsonPath('data.0.status', 'trial_expired');
    }

    // Edge — a single-company user still gets their one-row list: hiding the
    // switcher is a client concern (UC-1-034 alt-2a), never a server-side gate.
    #[Test]
    public function when_user_belongs_to_one_company_then_single_row_returned(): void
    {
        // Given a user with exactly one live membership
        $user = $this->aRegisteredUser();
        $this->aMembershipFor($user, Company::factory()->named('Acme')->create());

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then the single membership is returned
        $this->assertCompaniesListed($response, ['Acme']);
    }

    // Happy — the full switcher contract (slice-companies B4): every live
    // membership with its role, plan, status, onboarding flags and join date,
    // name-ordered, in the exact non-paginated envelope (leak-lock). Zeta is
    // attached FIRST so the name ordering has teeth; is_default is false
    // everywhere until UC-1-139 lands the default-company column.
    #[Test]
    public function when_user_belongs_to_multiple_companies_then_all_live_memberships_returned_name_ordered(): void
    {
        // Given a user who joined Zeta (Pro, admin, onboarded) before Acme (Free, employee)
        $this->travelTo(CarbonImmutable::parse('2026-07-08 10:00:00'));
        $user = $this->aRegisteredUser();
        $zeta = Company::factory()->named('Zeta')->onPlan(BillingPlan::Pro)->create();
        $acme = Company::factory()->named('Acme')->onPlan(BillingPlan::Free)->create();
        $this->aMembershipFor($user, $zeta, 'admin');
        $this->completedOnboardingIn($zeta, $user);
        $this->aMembershipFor($user, $acme);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then both memberships return name-ordered with the exact B4 row shape
        $response->assertExactJson([
            'data' => [
                $this->expectedCompanyRow($acme, 'employee'),
                $this->expectedCompanyRow($zeta, 'admin', onboarded: true),
            ],
        ]);
    }

    // Edge — a user whose only membership was removed has an EMPTY list: 200 with
    // data: [] (the client renders the no-companies state — there is never a
    // server-side minimum-membership gate; exact envelope locks out pagination).
    #[Test]
    public function when_user_has_no_live_memberships_then_empty_list_returned(): void
    {
        // Given a user whose only membership was removed
        $user = $this->aRegisteredUser();
        $this->aRemovedMembershipIn(Company::factory()->named('Acme')->create(), $user);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then the list is empty
        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    // Edge — identically-named companies keep a stable order: the membership id
    // (time-ordered uuid) breaks the name tie, so the earlier-joined company leads
    // deterministically (companies.name carries no unique constraint).
    #[Test]
    public function when_two_companies_share_a_name_then_join_order_breaks_the_tie(): void
    {
        // Given memberships in two companies both named Acme, joined in a known order
        $user = $this->aRegisteredUser();
        $first = Company::factory()->named('Acme')->create();
        $second = Company::factory()->named('Acme')->create();
        $this->aMembershipFor($user, $first);
        $this->aMembershipFor($user, $second);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then the earlier-joined membership leads
        $this->assertCompaniesListed($response, ['Acme', 'Acme']);
        $this->assertSame([$first->getKey(), $second->getKey()], $response->json('data.*.id'));
    }

    // Edge — edge row values serialize faithfully: a planless (trial) company and
    // a skipped onboarding wizard surface as plan: null and skipped: true.
    #[Test]
    public function when_onboarding_was_skipped_in_a_planless_company_then_row_reflects_both(): void
    {
        // Given a member of a planless company who skipped the onboarding wizard
        $user = $this->aRegisteredUser();
        $company = Company::factory()->named('Acme')->withoutPlan()->create();
        $this->aMembershipFor($user, $company);
        $this->skippedOnboardingIn($company, $user);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then the row carries the null plan and the skipped flag
        $response->assertJsonPath('data.0.plan', null);
        $response->assertJsonPath('data.0.onboarding', ['completed' => false, 'skipped' => true]);
    }

    // Edge — the B2 read-time fallback (UC-1-139 Gherkin 5): a default pointing at
    // a since-removed membership degrades gracefully — the row is excluded (live
    // pivots only) so NOTHING is flagged; the stale users.default_company_id value
    // is harmless and needs no cleanup job.
    #[Test]
    public function when_the_default_membership_was_removed_then_no_row_flagged_default(): void
    {
        // Given a member of Acme and Beta whose default Beta membership was then removed
        $user = $this->aRegisteredUser();
        $acme = Company::factory()->named('Acme')->create();
        $beta = Company::factory()->named('Beta')->create();
        $this->aMembershipFor($user, $acme);
        $this->aMembershipFor($user, $beta);
        $this->setDefaultCompany($user, ['companyId' => $beta->getKey()]);
        CompanyUser::query()
            ->where('company_id', $beta->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now()]);

        // When they list their companies
        $response = $this->listCompanies($user);

        // Then the removed row is absent and no remaining row is flagged default
        $this->assertCompaniesListed($response, ['Acme']);
        $this->assertListFlagsDefault($user, null);
    }

    /**
     * Mark the user's membership in $company as having skipped the onboarding
     * wizard — the counterpart flag to completedOnboardingIn.
     */
    protected function skippedOnboardingIn(Company $company, User $user): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->update(['onboarding_skipped' => true]);
    }

    /**
     * Mark the user's membership in $company as having completed onboarding —
     * the wizard flag the switcher row surfaces (company_user pivot).
     */
    protected function completedOnboardingIn(Company $company, User $user): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->update(['onboarding_completed' => true]);
    }

    /**
     * The exact B4 switcher row expected for $company: identity, the member's
     * role (seeded catalog), plan/status, the T1 is_default:false pin, the
     * onboarding flags and the frozen-clock join date.
     *
     * @return array<string, mixed>
     */
    protected function expectedCompanyRow(Company $company, string $roleCode, bool $onboarded = false): array
    {
        $company->refresh();

        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'role' => [
                'code' => $roleCode,
                'name' => $this->seededRole($roleCode)->name,
            ],
            'plan' => $company->current_billing_plan?->value,
            'status' => $company->status->value,
            'is_default' => false,
            'onboarding' => [
                'completed' => $onboarded,
                'skipped' => false,
            ],
            'joined_at' => now()->toISOString(),
        ];
    }

    /**
     * The list responded with exactly the expected companies, in order: 200, one
     * row per expected name, data.*.name matching $expectedNames verbatim.
     */
    protected function assertCompaniesListed(TestResponse $response, array $expectedNames): void
    {
        $response->assertOk();
        $response->assertJsonCount(count($expectedNames), 'data');
        $this->assertSame($expectedNames, $response->json('data.*.name'));
    }
}
