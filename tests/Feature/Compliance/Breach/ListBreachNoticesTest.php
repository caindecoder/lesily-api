<?php

namespace Tests\Feature\Compliance\Breach;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Breach\Enums\BreachFilingStatus;
use Database\Factories\BreachIncidentRecordFactory;
use Database\Factories\BreachIncidentCompanyFactory;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\BreachNoticeFilters;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ListBreachNoticesTest extends BreachIncidentsTestCase
{
    use AssertsFilterEmptyValueRejection;

    #[Test]
    public function when_a_guest_lists_breach_notices_then_they_are_unauthenticated(): void
    {
        // Given company A was notified of a breach
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());

        // When a caller with no token lists company A's breach notices
        $response = $this->listNotices(null, $this->companyA);

        // Then they are refused as unauthenticated and no notice is shown
        $this->assertUnauthenticated($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheViewPermission')]
    public function when_a_member_without_the_view_permission_lists_then_the_role_is_insufficient(string $roleCode): void
    {
        // Given company A was notified of a breach, and a member of A whose role cannot view breach notices
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member lists company A's breach notices
        $response = $this->listNotices($member, $this->companyA);

        // Then the role is refused and no notice is shown
        $this->assertRoleInsufficient($response, 'breach.view-notices');
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_of_another_company_lists_then_company_access_is_denied(): void
    {
        // Given company A was notified of a breach, and the owner of company B
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $outsider = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner lists company A's breach notices
        $response = $this->listNotices($outsider, $this->companyA);

        // Then access to company A is denied and no notice is shown
        $this->assertCompanyAccessDenied($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given company A was notified of a breach, and A's owner accepted the policies before a new privacy policy version
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the list is refused until they accept, and no notice is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $response->assertJsonMissingPath('data');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidListQueries')]
    public function when_the_list_query_is_invalid_then_it_is_refused_with_the_reason(array $query, string $field, string $message): void
    {
        // Given company A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices with an invalid query
        $response = $this->listNotices($owner, $this->companyA, $query);

        // Then the query is refused with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_a_notices_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty or blank value for one of the list's query keys
        // When the notices filter is built from it
        // Then the value is rejected
        $this->assertEmptyFilterValueRejected(BreachNoticeFilters::class, $field, $value);
    }

    #[Test]
    public function when_the_company_is_on_no_incident_then_the_list_is_empty(): void
    {
        // Given an incident that notified company A only, and the owner of company B
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $owner = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner lists company B's breach notices
        $response = $this->listNotices($owner, $this->companyB);

        // Then the list is empty
        $this->assertNothingListed($response);
    }

    #[Test]
    #[DataProvider('provideStagesNeverShown')]
    public function when_the_company_has_not_been_told_of_an_incident_then_it_is_not_listed(Closure $atStage): void
    {
        // Given an incident naming company A that never reached its notices, and A's owner
        $atStage($this->anIncident())->withCompany($this->companyA, ['pii'], 40)->createOne();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the incident is not listed
        $this->assertNothingListed($response);
    }

    #[Test]
    public function when_notified_times_tie_then_the_order_falls_back_to_the_id(): void
    {
        // Given two notices to company A sent at the same instant, and A's owner
        $sentAt = CarbonImmutable::parse('2026-05-05 09:00:00');
        $first = $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, $sentAt);
        $second = $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, $sentAt);
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the later row comes first
        $this->assertNoticesListed($response, [$second->breach_incident_id, $first->breach_incident_id]);
    }

    #[Test]
    public function when_the_notices_span_pages_then_they_are_listed_page_by_page(): void
    {
        // Given three notices to company A sent a day apart, and A's owner
        $oldest = $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-05 09:00:00'));
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-06 09:00:00'));
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-07 09:00:00'));
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner reads the second page, two notices a page
        $response = $this->listNotices($owner, $this->companyA, ['page' => 2, 'per_page' => 2]);

        // Then the second page holds the oldest notice, with the page counts
        $this->assertNoticesListed($response, [$oldest->breach_incident_id]);
        $response->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given sixteen notices to company A, and A's owner
        foreach (range(1, 16) as $day) {
            $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-01')->addDays($day));
        }
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends the page in the request body instead of the query string
        $response = $this->listNoticesWithBody($owner, $this->companyA, ['page' => 2]);

        // Then the first page is listed
        $response->assertOk();
        $response->assertJsonPath('meta.pagination.page', 1);
        $response->assertJsonCount(15, 'data');
    }

    #[Test]
    public function when_no_estimated_count_is_known_then_the_count_is_empty(): void
    {
        // Given company A's notice with no estimated count, and A's owner
        $incident = $this->anIncident()->companiesNotified()->createOne();
        $this->aPartFor($incident, $this->companyA)->withFigures(['pii'], null)->notified()->createOne();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the count is shown empty beside the data types
        $response->assertOk();
        $response->assertJsonPath('data.0.estimated_count', null);
        $response->assertJsonPath('data.0.data_types', ['pii']);
    }

    #[Test]
    public function when_a_fact_is_corrected_after_the_notice_then_the_current_value_is_shown(): void
    {
        // Given company A was notified of about 120 people, then the count was corrected to 145, and A's owner
        $breachId = $this->aRecordedIncident(['companies' => [['companyId' => $this->companyA->getKey(), 'dataTypes' => ['pii'], 'estimatedCount' => 120]]]);
        $this->notifyCompanies($this->admin, $breachId);
        BreachIncidentCompany::query()->where('breach_incident_id', $breachId)->update(['estimated_count' => 145]);
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the notice shows the corrected count, while the notice as sent still says 120
        $response->assertOk();
        $response->assertJsonPath('data.0.estimated_count', 145);
        $this->assertSame('120', $this->sentPartOf($breachId)['count']);
    }

    #[Test]
    public function when_many_notices_are_listed_then_the_query_count_stays_below_the_row_count(): void
    {
        // Given fifteen notices to company A, and A's owner
        foreach (range(1, 15) as $day) {
            $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-01')->addDays($day));
        }
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices, the queries counted
        [$response, $queryCount] = $this->listNoticesCountingQueries($owner, $this->companyA);

        // Then a full page came back for fewer queries than it has rows
        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $this->assertLessThan(15, $queryCount);
    }

    #[Test]
    public function when_notices_are_listed_then_the_newest_notice_comes_first(): void
    {
        // Given three notices to company A, created in an order unlike the order they were sent, and A's owner
        $middle = $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-06 09:00:00'));
        $newest = $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-07 09:00:00'));
        $oldest = $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, CarbonImmutable::parse('2026-05-05 09:00:00'));
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the notices come newest first
        $this->assertNoticesListed($response, [$newest->breach_incident_id, $middle->breach_incident_id, $oldest->breach_incident_id]);
    }

    /**
     * @param  array{filing_status: string, filing_requested_at: ?string, filed_at: ?string}  $expectedFiling
     */
    #[Test]
    #[DataProvider('provideFilingStates')]
    public function when_the_filing_has_moved_then_the_notice_shows_its_filing_status(BreachFilingStatus $state, array $expectedFiling): void
    {
        // Given company A's notice at the given filing state, and A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $this->aPartAtFilingState($state, $owner)->createOne();

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the notice shows its filing status with the dates, and the owner as requester once asked
        $this->assertFilingShown($response, $expectedFiling, $state === BreachFilingStatus::NotRequested ? null : $owner->getKey());
    }

    #[Test]
    public function when_an_incident_is_resolved_then_its_notice_shows_the_resolved_stage(): void
    {
        // Given company A's notice of an incident Lesily has since resolved, and A's owner
        $this->aNoticeFor($this->anIncident()->resolved(), $this->companyA, now()->toImmutable());
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the notice shows the incident as resolved
        $response->assertOk();
        $response->assertJsonPath('data.0.stage', 'resolved');
    }

    #[Test]
    public function when_an_admin_lists_then_they_see_the_same_notice_as_the_owner(): void
    {
        // Given company A was notified of a breach, and A's owner and an admin of A
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $admin = $this->aConsentingMember($this->companyA, 'admin');
        $ownersList = $this->listNotices($owner, $this->companyA)->json('data');

        // When the admin lists company A's breach notices
        $response = $this->listNotices($admin, $this->companyA);

        // Then the admin sees the same one notice the owner sees
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($ownersList, $response->json('data'));
    }

    #[Test]
    public function when_the_owner_lists_then_each_notice_shows_only_the_company_own_part(): void
    {
        // Given one incident that notified company A (NICs and names, about 120 people) and company B (bank details, about 30), and A's owner
        $incident = $this->anIncident()->companiesNotified()->createOne();
        $this->aPartFor($incident, $this->companyB)->withFigures(['banking'], 30)->notifiedAt(CarbonImmutable::parse('2026-05-05 10:00:00'))->createOne();
        $this->aPartFor($incident, $this->companyA)->withFigures(['nic', 'pii'], 120)->notifiedAt(CarbonImmutable::parse('2026-05-05 09:00:00'))->createOne();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the one notice shows company A's own part and the incident's facts, and nothing of company B
        $this->assertNoticeShows($response, [
            'breach_id' => $incident->getKey(),
            'company_name' => $this->companyA->name,
            'stage' => 'companies_notified',
            'description' => 'A payroll export was emailed to the wrong recipient.',
            'likely_consequences' => 'Salary and bank details may be read by one outside person.',
            'measures' => 'The recipient was asked to delete the file.',
            'date_discovered' => '2026-05-04T14:00:00.000000Z',
            'deadline' => '2026-05-07T14:00:00.000000Z',
            'data_types' => ['nic', 'pii'],
            'estimated_count' => 120,
            'notified_at' => '2026-05-05T09:00:00.000000Z',
            'filing_status' => 'not_requested',
            'filing_requested_at' => null,
            'filing_requested_by' => null,
            'filed_at' => null,
        ]);
        $this->assertNothingOfCompanyB($response);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    protected function assertNoticeShows(TestResponse $response, array $expected): void
    {
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($expected, $response->json('data.0'));
    }

    /**
     * @return array<string, string>
     */
    protected function sentPartOf(string $breachId): array
    {
        $event = LesilyStoredEvent::query()
            ->where('event_class', BreachCompanyNotified::class)
            ->where('aggregate_uuid', $breachId)
            ->sole();

        return $event->event_properties['part'];
    }

    /**
     * @return array{0: TestResponse, 1: int}
     */
    protected function listNoticesCountingQueries(User $actor, Company $company): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->listNotices($actor, $company);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queryCount];
    }

    protected function aPartAtFilingState(BreachFilingStatus $state, User $owner): BreachIncidentCompanyFactory
    {
        $part = $this->aPartFor($this->anIncident()->companiesNotified()->createOne(), $this->companyA);

        return match ($state) {
            BreachFilingStatus::NotRequested => $part->notified(),
            BreachFilingStatus::Requested => $part->filingRequested($owner->getKey()),
            BreachFilingStatus::Filed => $part->filed($owner->getKey(), $this->admin->getKey()),
        };
    }

    /**
     * @param  array{filing_status: string, filing_requested_at: ?string, filed_at: ?string}  $expectedFiling
     */
    protected function assertFilingShown(TestResponse $response, array $expectedFiling, ?string $requesterId): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.0.filing_status', $expectedFiling['filing_status']);
        $response->assertJsonPath('data.0.filing_requested_at', $expectedFiling['filing_requested_at']);
        $response->assertJsonPath('data.0.filing_requested_by', $requesterId);
        $response->assertJsonPath('data.0.filed_at', $expectedFiling['filed_at']);
    }

    protected function assertNothingOfCompanyB(TestResponse $response): void
    {
        $response->assertDontSee($this->companyB->name);
        $response->assertDontSee('banking');
        $response->assertJsonMissing(['estimated_count' => 30]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheViewPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidListQueries(): array
    {
        return [
            'unknown key' => [['sort' => 'notified_at'], 'sort', 'This filter is not supported.'],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'per_page not a number' => [['per_page' => 'abc'], 'per_page', 'The per page field must be an integer.'],
            'array-valued per_page' => [['per_page' => ['2']], 'per_page', 'The per page field must be an integer.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'page not a number' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
        ];
    }

    /**
     * @return array<string, array{BreachFilingStatus, array{filing_status: string, filing_requested_at: ?string, filed_at: ?string}}>
     */
    public static function provideFilingStates(): array
    {
        return [
            'not requested' => [BreachFilingStatus::NotRequested, ['filing_status' => 'not_requested', 'filing_requested_at' => null, 'filed_at' => null]],
            'requested' => [BreachFilingStatus::Requested, ['filing_status' => 'requested', 'filing_requested_at' => '2026-05-06T14:00:00.000000Z', 'filed_at' => null]],
            'filed' => [BreachFilingStatus::Filed, ['filing_status' => 'filed', 'filing_requested_at' => '2026-05-06T14:00:00.000000Z', 'filed_at' => '2026-05-07T14:00:00.000000Z']],
        ];
    }

    /**
     * @return array<string, array{Closure(BreachIncidentRecordFactory): BreachIncidentRecordFactory}>
     */
    public static function provideStagesNeverShown(): array
    {
        return [
            'recorded' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident],
            'false alarm' => [fn (BreachIncidentRecordFactory $incident): BreachIncidentRecordFactory => $incident->falseAlarm()],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        return [
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'whitespace page' => ['page', ' '],
            'whitespace per_page' => ['per_page', ' '],
        ];
    }
}
