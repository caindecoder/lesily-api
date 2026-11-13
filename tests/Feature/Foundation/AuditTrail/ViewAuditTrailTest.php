<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Commands\AuditTrailFilters;

class ViewAuditTrailTest extends AuditTrailTestCase
{
    use AssertsFilterEmptyValueRejection;

    #[Test]
    public function when_a_guest_views_the_audit_trail_then_they_are_unauthenticated(): void
    {
        // Given company A has an audit entry
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();

        // When a caller with no token views company A's audit trail
        $response = $this->viewAuditTrail(null, $this->companyA);

        // Then they are refused as unauthenticated and no entry is shown
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheAuditPermission')]
    public function when_a_member_without_the_audit_permission_views_then_the_role_is_insufficient(string $roleCode): void
    {
        // Given company A has an audit entry, and a member of A whose role cannot view the audit trail
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member views company A's audit trail
        $response = $this->viewAuditTrail($member, $this->companyA);

        // Then the role is refused and no entry is shown
        $this->assertRoleInsufficient($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_of_another_company_views_then_company_access_is_denied(): void
    {
        // Given company A has an audit entry, and the owner of company B
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $outsider = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner views company A's audit trail
        $response = $this->viewAuditTrail($outsider, $this->companyA);

        // Then access to company A is denied and no entry is shown
        $this->assertCompanyAccessDenied($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given company A has an audit entry, and A's owner accepted the policies before a new privacy policy version
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the audit trail is refused until they accept, and no entry is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $response->assertJsonMissingPath('data');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidAuditTrailQueries')]
    public function when_the_audit_trail_query_is_invalid_then_it_is_refused_with_the_reason(array $query, string $field, string $message): void
    {
        // Given company A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail with an invalid query
        $response = $this->viewAuditTrail($owner, $this->companyA, $query);

        // Then the query is refused with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_an_audit_trail_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty or blank value for one of the audit trail's query keys
        // When the audit trail filter is built from it
        // Then the value is rejected
        $this->assertEmptyFilterValueRejected(AuditTrailFilters::class, $field, $value);
    }

    #[Test]
    #[DataProvider('provideWriteVerbs')]
    public function when_a_write_verb_is_sent_to_the_audit_trail_then_the_method_is_not_allowed(string $verb): void
    {
        // Given company A has an audit entry, and A's owner
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends a write verb to company A's audit trail
        $response = $this->sendToAuditTrail($verb, $owner, '/api/v1/audit-trail');

        // Then no route writes it, and the entry is kept
        $this->assertRefusedWith($response, Response::HTTP_METHOD_NOT_ALLOWED, ApiErrorCode::HttpError);
        $this->assertDatabaseCount('audit_trail', 1);
    }

    #[Test]
    #[DataProvider('provideEntryVerbs')]
    public function when_an_entry_is_addressed_by_its_id_then_no_route_answers_and_it_is_unchanged(string $verb): void
    {
        // Given company A has an audit entry, and A's owner
        $entry = AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends the verb to the entry's own address
        $response = $this->sendToAuditTrail($verb, $owner, '/api/v1/audit-trail/'.$entry->getKey());

        // Then no route answers, and the entry is unchanged
        $response->assertNotFound();
        $this->assertDatabaseHas('audit_trail', ['id' => $entry->getKey(), 'description' => $entry->description]);
    }

    #[Test]
    public function when_the_company_has_no_entries_then_the_list_is_empty(): void
    {
        // Given only company B has an audit entry, and A's owner
        AuditEntry::factory()->forCompany($this->companyB)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the list is empty
        $this->assertNothingListed($response);
    }

    #[Test]
    public function when_other_companies_and_the_platform_have_entries_then_only_the_company_own_are_listed(): void
    {
        // Given an entry of company A, an entry of company B and a platform-level entry
        $own = AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        AuditEntry::factory()->forCompany($this->companyB)->byMember(User::factory()->create(), 'admin')->create();
        AuditEntry::factory()->platformLevel()->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When A's owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then only company A's entry is listed
        $this->assertEntriesListed($response, [$own->getKey()]);
    }

    #[Test]
    public function when_recorded_times_tie_then_the_newer_entry_comes_first(): void
    {
        // Given two entries of company A recorded at the same moment
        $first = $this->anEntryOfCompanyAAt('2026-04-01 09:00:00');
        $second = $this->anEntryOfCompanyAAt('2026-04-01 09:00:00');
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the entry written last comes first
        $this->assertEntriesListed($response, [$second->getKey(), $first->getKey()]);
    }

    #[Test]
    public function when_the_entries_span_pages_then_they_are_listed_page_by_page(): void
    {
        // Given three entries of company A
        $this->anEntryOfCompanyAAt('2026-04-03 09:00:00');
        $this->anEntryOfCompanyAAt('2026-04-02 09:00:00');
        $oldest = $this->anEntryOfCompanyAAt('2026-04-01 09:00:00');
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views the second page of two
        $response = $this->viewAuditTrail($owner, $this->companyA, ['per_page' => 2, 'page' => 2]);

        // Then the oldest entry is on it, and the page says where it sits
        $this->assertEntriesListed($response, [$oldest->getKey()]);
        $this->assertPageShown($response, ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    }

    #[Test]
    public function when_no_page_size_is_given_then_fifteen_entries_are_listed(): void
    {
        // Given sixteen entries of company A
        AuditEntry::factory()->count(16)->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail with no page size
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then fifteen entries are listed on the first of two pages
        $response->assertJsonCount(15, 'data');
        $this->assertPageShown($response, ['page' => 1, 'per_page' => 15, 'total' => 16, 'last_page' => 2]);
    }

    #[Test]
    public function when_a_page_of_one_hundred_is_asked_then_it_is_accepted(): void
    {
        // Given sixteen entries of company A
        AuditEntry::factory()->count(16)->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner asks for pages of one hundred
        $response = $this->viewAuditTrail($owner, $this->companyA, ['per_page' => 100]);

        // Then all sixteen are listed on one page
        $response->assertJsonCount(16, 'data');
        $this->assertPageShown($response, ['page' => 1, 'per_page' => 100, 'total' => 16, 'last_page' => 1]);
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given three entries of company A, and A's owner
        $newest = $this->anEntryOfCompanyAAt('2026-04-03 09:00:00');
        $middle = $this->anEntryOfCompanyAAt('2026-04-02 09:00:00');
        $this->anEntryOfCompanyAAt('2026-04-01 09:00:00');
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        Sanctum::actingAs($owner);

        // When the owner asks for page two in the request body, two to a page in the query
        $response = $this->json('GET', '/api/v1/audit-trail?per_page=2', ['page' => 2], ['X-Company-Context' => $this->companyA->getKey()]);

        // Then the first page is shown
        $this->assertEntriesListed($response, [$newest->getKey(), $middle->getKey()]);
    }

    #[Test]
    public function when_the_system_wrote_an_entry_then_who_is_empty(): void
    {
        // Given an entry of company A written by the system, with no actor
        $entry = AuditEntry::factory()->forCompany($this->companyA)->system()->createOne();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the entry is listed and names no one
        $this->assertNoActorShown($response, $entry);
    }

    #[Test]
    public function when_lesily_staff_wrote_an_entry_then_it_shows_lesily_support_without_name_ip_or_device(): void
    {
        // Given a Lesily platform admin reviewed company A's invoice from their own address and browser
        $staff = User::factory()->named('Pat Silva')->create();
        AuditEntry::factory()
            ->forCompany($this->companyA)
            ->byStaff($staff)
            ->fromOrigin('10.0.0.5', 'Mozilla/5.0 (Windows NT 10.0) Edge/140.0')
            ->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the entry shows Lesily support, and nothing that names or locates the staff member
        $this->assertStaffShownAsLesilySupport($response);
        $response->assertDontSee('Pat Silva');
        $response->assertDontSee('10.0.0.5');
    }

    #[Test]
    public function when_the_actor_filter_names_a_staff_member_then_nothing_is_listed(): void
    {
        // Given an entry of company A by a Lesily staff member
        $staff = User::factory()->create();
        AuditEntry::factory()->forCompany($this->companyA)->byStaff($staff)->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail by the staff member's own id
        $response = $this->viewAuditTrail($owner, $this->companyA, ['actor' => $staff->getKey()]);

        // Then nothing is listed, so the filter cannot tell who the staff member was
        $this->assertNothingListed($response);
    }

    #[Test]
    public function when_a_member_entry_has_no_role_on_record_then_the_actor_filter_still_lists_it(): void
    {
        // Given an entry of company A by Alice with no role on record
        $alice = User::factory()->create();
        $entry = AuditEntry::factory()->forCompany($this->companyA)->byMember($alice, 'admin')->withoutRole()->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail by Alice
        $response = $this->viewAuditTrail($owner, $this->companyA, ['actor' => $alice->getKey()]);

        // Then Alice's entry is listed
        $this->assertEntriesListed($response, [$entry->getKey()]);
    }

    #[Test]
    public function when_an_entry_records_no_changes_then_no_changes_and_no_other_metadata_are_shown(): void
    {
        // Given an entry of company A whose metadata holds query details but no changes
        $entry = AuditEntry::factory()
            ->forCompany($this->companyA)
            ->byMember(User::factory()->create(), 'admin')
            ->withMetadata(['filters' => ['stage' => 'recorded'], 'result_count' => 4])
            ->createOne();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the entry is listed with no changes, and none of the other metadata
        $this->assertEntriesListed($response, [$entry->getKey()]);
        $this->assertSame(['changes' => null], array_intersect_key($response->json('data.0'), ['changes' => true]));
        $response->assertDontSee('result_count');
    }

    #[Test]
    public function when_an_entry_records_an_encrypted_change_then_it_is_shown_decrypted(): void
    {
        // Given an entry of company A recording an encrypted phone change beside a plain role change
        AuditEntry::factory()
            ->forCompany($this->companyA)
            ->byMember(User::factory()->create(), 'owner')
            ->withChanges([
                'phone' => ['from' => Crypt::encryptString('+94771234567'), 'to' => Crypt::encryptString('+94777654321'), 'encrypted' => true],
                'role' => ['from' => 'employee', 'to' => 'hr_officer'],
            ])
            ->create();
        $auditor = $this->aConsentingMember($this->companyA, 'auditor');

        // When the auditor views company A's audit trail
        $response = $this->viewAuditTrail($auditor, $this->companyA);

        // Then the phone shows its old and new number in plain, unmarked, and the role as stored
        $response->assertOk();
        $this->assertEquals([
            'phone' => ['from' => '+94771234567', 'to' => '+94777654321'],
            'role' => ['from' => 'employee', 'to' => 'hr_officer'],
        ], $response->json('data.0.changes'));
    }

    #[Test]
    public function when_entries_are_listed_then_the_newest_comes_first(): void
    {
        // Given company A's entries are written in an order that is not their recorded order
        $middle = $this->anEntryOfCompanyAAt('2026-04-02 09:00:00');
        $newest = $this->anEntryOfCompanyAAt('2026-04-03 09:00:00');
        $oldest = $this->anEntryOfCompanyAAt('2026-04-01 09:00:00');
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the newest entry comes first
        $this->assertEntriesListed($response, [$newest->getKey(), $middle->getKey(), $oldest->getKey()]);
    }

    #[Test]
    public function when_the_owner_views_then_each_entry_shows_who_what_when_where_and_severity(): void
    {
        // Given an admin of company A asked for a copy of personal data
        $alice = User::factory()->named('Alice Perera')->create();
        $entry = AuditEntry::factory()
            ->forCompany($this->companyA)
            ->byMember($alice, 'admin')
            ->ofAction(AuditAction::DsarRequested, AuditSubjectType::DsarRequest)
            ->withSeverity('critical')
            ->fromOrigin('203.0.113.7', 'Mozilla/5.0 (X11; Linux) Firefox/140.0')
            ->recordedAt(CarbonImmutable::parse('2026-04-01 09:00:00'))
            ->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the entry shows who, what, when, where and how severe
        $response->assertOk();
        $this->assertSame([
            'id' => $entry->getKey(),
            'recorded_at' => '2026-04-01T09:00:00.000000Z',
            'actor_id' => $alice->getKey(),
            'actor_name' => 'Alice Perera',
            'actor_role' => 'admin',
            'actor_role_name' => 'Admin',
            'action' => 'dsar_requested',
            'action_words' => 'Asked for a copy of personal data',
            'subject_type' => 'dsar_request',
            'subject_type_words' => 'A data request',
            'subject_id' => $entry->subject_id,
            'subject_label' => 'INV-2026-000001',
            'description' => $entry->description,
            'changes' => null,
            'ip_address' => '203.0.113.7',
            'device' => 'Mozilla/5.0 (X11; Linux) Firefox/140.0',
            'severity' => 'critical',
        ], $response->json('data.0'));
    }

    #[Test]
    public function when_an_entry_records_changes_then_each_field_shows_its_old_and_new_value(): void
    {
        // Given an entry of company A recording a role changed from employee to hr_officer
        AuditEntry::factory()
            ->forCompany($this->companyA)
            ->byMember(User::factory()->create(), 'admin')
            ->withChanges(['role' => ['from' => 'employee', 'to' => 'hr_officer']])
            ->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the entry shows the role's old and new value
        $response->assertOk();
        $this->assertEquals(['role' => ['from' => 'employee', 'to' => 'hr_officer']], $response->json('data.0.changes'));
        $this->assertContainsOnlyString($response->json('data.0.changes.role'));
    }

    #[Test]
    #[DataProvider('provideRolesWithTheAuditPermission')]
    public function when_a_member_with_the_audit_permission_views_then_the_entries_are_listed(string $roleCode): void
    {
        // Given an entry of company A, and a member of A whose role may view the audit trail
        $entry = AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member views company A's audit trail
        $response = $this->viewAuditTrail($member, $this->companyA);

        // Then the entry is listed
        $this->assertEntriesListed($response, [$entry->getKey()]);
    }

    #[Test]
    #[DataProvider('provideCompaniesWithoutAPaidPlan')]
    public function when_the_company_has_no_paid_plan_then_the_audit_trail_is_still_shown(Closure $aCompany): void
    {
        // Given a company with no paid plan, an entry of it, and its owner
        $company = $aCompany();
        $entry = AuditEntry::factory()->forCompany($company)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner views the company's audit trail
        $response = $this->viewAuditTrail($owner, $company);

        // Then the entry is listed — the audit trail is never plan-gated
        $this->assertEntriesListed($response, [$entry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_date_range_then_only_entries_within_it_are_listed(): void
    {
        // Given entries of company A just outside and just inside April 2026
        $this->anEntryOfCompanyAAt('2026-03-31 23:59:59');
        $firstInside = $this->anEntryOfCompanyAAt('2026-04-01 00:00:00');
        $lastInside = $this->anEntryOfCompanyAAt('2026-04-30 23:59:59');
        $this->anEntryOfCompanyAAt('2026-05-01 00:00:00');
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail within April
        $response = $this->viewAuditTrail($owner, $this->companyA, ['within' => '2026-04-01,2026-04-30']);

        // Then only April's entries are listed
        $this->assertEntriesListed($response, [$lastInside->getKey(), $firstInside->getKey()]);
    }

    #[Test]
    public function when_filtered_by_actor_then_only_that_member_entries_are_listed(): void
    {
        // Given entries of company A by Alice, by Bob and by Lesily staff
        $alice = User::factory()->create();
        $alicesEntry = AuditEntry::factory()->forCompany($this->companyA)->byMember($alice, 'admin')->create();
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        AuditEntry::factory()->forCompany($this->companyA)->byStaff(User::factory()->create())->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail by Alice
        $response = $this->viewAuditTrail($owner, $this->companyA, ['actor' => $alice->getKey()]);

        // Then only Alice's entry is listed
        $this->assertEntriesListed($response, [$alicesEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_lesily_support_then_only_staff_entries_are_listed(): void
    {
        // Given entries of company A by Alice and by two Lesily staff members
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $first = AuditEntry::factory()->forCompany($this->companyA)->byStaff(User::factory()->create())->recordedAt(CarbonImmutable::parse('2026-04-01 09:00:00'))->create();
        $second = AuditEntry::factory()->forCompany($this->companyA)->byStaff(User::factory()->create())->recordedAt(CarbonImmutable::parse('2026-04-02 09:00:00'))->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail by Lesily support
        $response = $this->viewAuditTrail($owner, $this->companyA, ['actor' => 'lesily_support']);

        // Then both staff entries are listed, and Alice's is not
        $this->assertEntriesListed($response, [$second->getKey(), $first->getKey()]);
    }

    #[Test]
    public function when_filtered_by_action_then_only_that_action_is_listed(): void
    {
        // Given entries of company A for three different actions
        $this->anEntryOfCompanyAFor(AuditAction::DsarRequested, AuditSubjectType::DsarRequest);
        $downloaded = $this->anEntryOfCompanyAFor(AuditAction::DsarDownloaded, AuditSubjectType::DsarRequest);
        $this->anEntryOfCompanyAFor(AuditAction::AdminReviewed, AuditSubjectType::Invoice);
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail for downloads
        $response = $this->viewAuditTrail($owner, $this->companyA, ['action' => 'dsar_downloaded']);

        // Then only the download is listed
        $this->assertEntriesListed($response, [$downloaded->getKey()]);
    }

    #[Test]
    public function when_filtered_by_subject_type_then_only_that_subject_type_is_listed(): void
    {
        // Given entries of company A about a data request, an invoice and a data breach
        $this->anEntryOfCompanyAFor(AuditAction::DsarRequested, AuditSubjectType::DsarRequest);
        $invoice = $this->anEntryOfCompanyAFor(AuditAction::AdminReviewed, AuditSubjectType::Invoice);
        $this->anEntryOfCompanyAFor(AuditAction::BreachFilingRequested, AuditSubjectType::BreachIncident);
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail about invoices
        $response = $this->viewAuditTrail($owner, $this->companyA, ['subject_type' => 'invoice']);

        // Then only the invoice entry is listed
        $this->assertEntriesListed($response, [$invoice->getKey()]);
    }

    #[Test]
    public function when_filters_combine_then_only_entries_matching_all_are_listed(): void
    {
        // Given Alice's download of a data request on 2026-04-10, and four entries that each miss one filter
        $alice = User::factory()->create();
        $match = $this->anEntryBy($alice)->recordedAt(CarbonImmutable::parse('2026-04-10 09:00:00'))->createOne();
        $this->anEntryBy($alice)->recordedAt(CarbonImmutable::parse('2026-05-10 09:00:00'))->create();
        $this->anEntryBy(User::factory()->create())->recordedAt(CarbonImmutable::parse('2026-04-10 09:00:00'))->create();
        $this->anEntryBy($alice)->ofAction(AuditAction::DsarRequested, AuditSubjectType::DsarRequest)->recordedAt(CarbonImmutable::parse('2026-04-10 09:00:00'))->create();
        $this->anEntryBy($alice)->ofAction(AuditAction::DsarDownloaded, AuditSubjectType::Invoice)->recordedAt(CarbonImmutable::parse('2026-04-10 09:00:00'))->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail with all four filters
        $response = $this->viewAuditTrail($owner, $this->companyA, [
            'within' => '2026-04-01,2026-04-30',
            'actor' => $alice->getKey(),
            'action' => 'dsar_downloaded',
            'subject_type' => 'dsar_request',
        ]);

        // Then only the entry matching all four is listed, and the count agrees
        $this->assertEntriesListed($response, [$match->getKey()]);
        $response->assertJsonPath('meta.pagination.total', 1);
    }

    #[Test]
    public function when_the_audit_trail_is_viewed_then_no_audit_entry_is_written(): void
    {
        // Given two entries of company A, and A's owner
        AuditEntry::factory()->count(2)->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the view itself leaves no audit entry
        $response->assertOk();
        $this->assertDatabaseCount('audit_trail', 2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheAuditPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidAuditTrailQueries(): array
    {
        return [
            'unknown key' => [['sort' => 'recorded_at'], 'sort', 'This filter is not supported.'],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'per_page not a number' => [['per_page' => 'abc'], 'per_page', 'The per page field must be an integer.'],
            'array-valued per_page' => [['per_page' => ['2']], 'per_page', 'The per page field must be an integer.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'page not a number' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
            'within with one date' => [['within' => '2026-04-01'], 'within', 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.'],
            'within with three dates' => [['within' => '2026-04-01,2026-04-15,2026-04-30'], 'within', 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.'],
            'within with an impossible date' => [['within' => '2026-02-31,2026-03-01'], 'within', 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.'],
            'array-valued within' => [['within' => ['2026-04-01']], 'within', 'The within field must be a string.'],
            'unknown action' => [['action' => 'nope'], 'action', 'The selected action is invalid.'],
            'array-valued action' => [['action' => ['dsar_requested']], 'action', 'The action field must be a string.'],
            'unknown subject type' => [['subject_type' => 'nope'], 'subject_type', 'The selected subject type is invalid.'],
            'array-valued subject type' => [['subject_type' => ['invoice']], 'subject_type', 'The subject type field must be a string.'],
            'actor neither a member id nor lesily support' => [['actor' => 'not-an-id'], 'actor', 'The actor filter must be a member\'s id or lesily_support.'],
            'array-valued actor' => [['actor' => ['lesily_support']], 'actor', 'The actor field must be a string.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        $values = [];

        foreach (['within', 'actor', 'action', 'subject_type', 'page', 'per_page'] as $field) {
            $values["empty {$field}"] = [$field, ''];
            $values["whitespace {$field}"] = [$field, ' '];
        }

        return $values;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithTheAuditPermission(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * @return array<string, array{Closure(): Company}>
     */
    public static function provideCompaniesWithoutAPaidPlan(): array
    {
        return [
            'free' => [fn (): Company => Company::factory()->onPlan(BillingPlan::Free)->create()],
            'trial expired, no plan in force' => [fn (): Company => Company::factory()->trialExpired()->create()],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWriteVerbs(): array
    {
        return [
            'post' => ['POST'],
            'put' => ['PUT'],
            'patch' => ['PATCH'],
            'delete' => ['DELETE'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEntryVerbs(): array
    {
        return [
            'delete' => ['DELETE'],
            'patch' => ['PATCH'],
        ];
    }
}
