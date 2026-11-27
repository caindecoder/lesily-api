<?php

namespace Tests\Feature\Compliance\Dsar;

use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Dsar\UseCases\Commands\ListDsarRequestsCommand;

class ListDsarRequestsTest extends DsarTestCase
{
    use AssertsFilterEmptyValueRejection;

    #[Test]
    public function when_a_guest_lists_dsar_requests_then_they_are_unauthenticated(): void
    {
        // Given
        $query = [];

        // When
        $response = $this->listDsarRequestsAsGuest($query);

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_a_dsar_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for an allowed filter key — reachable only when
        // the filter is built directly (TrimStrings + ConvertEmptyStringsToNull
        // neutralise it over HTTP)

        // When the filter is built, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(ListDsarRequestsCommand::class, $field, $value);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidListQueries')]
    public function when_the_list_query_is_invalid_then_it_is_rejected_with_the_reason(array $query, string $invalidField, string $expectedMessage): void
    {
        // Given
        $member = $this->member;

        // When
        $response = $this->listDsarRequests($member, $this->company, $query);

        // Then
        $this->assertValidationRejected($response, $invalidField, $expectedMessage);
    }

    #[Test]
    public function when_a_view_all_holder_lists_then_other_companies_requests_stay_hidden(): void
    {
        // Given
        $dsarRequest = DsarRequestRecord::factory()->forSubject($this->company, $this->member)->create();
        $otherCompany = Company::factory()->create();
        DsarRequestRecord::factory()->forSubject($otherCompany, $this->aCompanyMember($otherCompany))->create();

        // When
        $response = $this->listDsarRequests($this->owner, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$dsarRequest->getKey()]);
    }

    #[Test]
    public function when_a_member_of_two_companies_lists_then_only_the_current_company_requests_are_listed(): void
    {
        // Given
        $otherCompany = Company::factory()->create();
        $this->attachMember($otherCompany, $this->member, $this->seededRole('employee'));
        DsarRequestRecord::factory()->forSubject($otherCompany, $this->member)->create();
        $dsarRequest = DsarRequestRecord::factory()->forSubject($this->company, $this->member)->create();

        // When
        $response = $this->listDsarRequests($this->member, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$dsarRequest->getKey()]);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutViewAll')]
    public function when_a_member_without_view_all_lists_then_other_members_requests_stay_hidden(string $roleCode): void
    {
        // Given
        $member = $this->memberWithRole($roleCode);
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When
        $response = $this->listDsarRequests($member, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, []);
    }

    #[Test]
    public function when_a_requester_no_longer_holds_view_all_then_the_requests_they_made_stay_listed(): void
    {
        // Given a former admin, now an employee, who requested an export for a member
        $requester = $this->member;
        $dsarRequest = DsarRequestRecord::factory()
            ->forSubject($this->company, $this->aCompanyMember($this->company))
            ->requestedBy($requester, 'admin')
            ->create();

        // When
        $response = $this->listDsarRequests($requester, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$dsarRequest->getKey()]);
    }

    #[Test]
    public function when_a_status_filter_is_given_then_only_requests_in_that_status_are_listed(): void
    {
        // Given
        $fulfilledDsarRequest = DsarRequestRecord::factory()->forSubject($this->company, $this->member)->requestedDaysAgo(10)->fulfilled()->create();
        DsarRequestRecord::factory()->forSubject($this->company, $this->member)->create();

        // When
        $response = $this->listDsarRequests($this->member, $this->company, ['status' => 'fulfilled']);

        // Then
        $this->assertDsarRequestsListed($response, [$fulfilledDsarRequest->getKey()]);
    }

    #[Test]
    public function when_requests_exceed_one_page_then_they_are_listed_page_by_page(): void
    {
        // Given
        [$oldest] = $this->requestsMadeDaysAgo([3, 2, 1]);

        // When the second page of two is asked for
        $response = $this->listDsarRequests($this->owner, $this->company, ['per_page' => 2, 'page' => 2]);

        // Then
        $this->assertDsarRequestPage(
            $response,
            [$oldest->getKey()],
            ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2],
        );
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given
        $newestFirst = $this->requestsMadeDaysAgo(range(1, 20));

        // When a second page is asked for in the body rather than the query string
        $response = $this->listDsarRequestsWithBody($this->owner, $this->company, ['page' => 2]);

        // Then paging follows only the validated query, so the first page comes back
        $this->assertDsarRequestPage(
            $response,
            array_map(fn (DsarRequestRecord $dsarRequest): string => $dsarRequest->getKey(), array_slice($newestFirst, 0, 15)),
            ['page' => 1, 'per_page' => 15, 'total' => 20, 'last_page' => 2],
        );
    }

    #[Test]
    public function when_request_times_tie_then_the_rows_come_back_in_a_stable_order(): void
    {
        // Given three requests made at the same instant
        $requestIds = array_map(fn (DsarRequestRecord $dsarRequest): string => $dsarRequest->getKey(), $this->requestsMadeDaysAgo([0, 0, 0]));
        rsort($requestIds);

        // When
        $response = $this->listDsarRequests($this->owner, $this->company);

        // Then equal request times fall back to the newest id first
        $this->assertDsarRequestsListed($response, $requestIds);
    }

    #[Test]
    public function when_the_download_window_has_closed_then_the_request_is_listed_as_no_longer_downloadable(): void
    {
        // Given a fulfilled request whose download window has closed and been marked expired
        $expiredDsarRequest = DsarRequestRecord::factory()->forSubject($this->company, $this->member)->fulfilled()->downloadExpired()->create();

        // When
        $response = $this->listDsarRequests($this->member, $this->company);

        // Then
        $this->assertListedRowShows($response, $expiredDsarRequest, false);
    }

    #[Test]
    public function when_many_requests_are_listed_then_the_query_count_stays_below_the_row_count(): void
    {
        // Given
        $this->requestsMadeDaysAgo(range(1, 20));

        // When
        [$response, $queryCount] = $this->listDsarRequestsCountingQueries($this->owner, $this->company);

        // Then
        $this->assertListedWithFewerQueriesThanRows($response, $queryCount);
    }

    #[Test]
    #[DataProvider('provideRolesWithViewAll')]
    public function when_a_view_all_holder_lists_then_every_company_request_is_listed_newest_first(string $roleCode): void
    {
        // Given
        $viewer = $this->memberWithRole($roleCode);
        [$oldest, $middle, $newest] = $this->requestsMadeDaysAgo([3, 2, 1]);

        // When
        $response = $this->listDsarRequests($viewer, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$newest->getKey(), $middle->getKey(), $oldest->getKey()]);
    }

    #[Test]
    public function when_a_member_lists_then_their_requests_show_the_sla_dates_and_download_availability(): void
    {
        // Given a fulfilled request from 28 days ago and an open one an admin made today
        $fulfilledDsarRequest = DsarRequestRecord::factory()->forSubject($this->company, $this->member)->requestedDaysAgo(28)->fulfilled()->create();
        $openDsarRequest = DsarRequestRecord::factory()
            ->forSubject($this->company, $this->member)
            ->requestedBy($this->admin, 'admin')
            ->create();

        // When
        $response = $this->listDsarRequests($this->member, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$openDsarRequest->getKey(), $fulfilledDsarRequest->getKey()]);
        $this->assertListedRowShows($response, $fulfilledDsarRequest, true);
        $this->assertListedRowShows($response, $openDsarRequest, false);
    }

    /**
     * One request per new member of the company, each made the given number of
     * days ago, in the order given.
     *
     * @param  list<int>  $daysAgo
     * @return list<DsarRequestRecord>
     */
    protected function requestsMadeDaysAgo(array $daysAgo): array
    {
        return array_map(
            fn (int $days): DsarRequestRecord => DsarRequestRecord::factory()
                ->forSubject($this->company, $this->aCompanyMember($this->company))
                ->requestedDaysAgo($days)
                ->create(),
            $daysAgo,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidListQueries(): array
    {
        return [
            'unknown status' => [['status' => 'pending'], 'status', 'The selected status is invalid.'],
            'empty status' => [['status' => ''], 'status', 'The status field must have a value.'],
            'per_page over max' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'per_page below minimum' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page not a number' => [['per_page' => 'abc'], 'per_page', 'The per page field must be an integer.'],
            'page below minimum' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'page not a number' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
            'company override attempt' => [['company_id' => (string) Str::uuid()], 'company_id', 'This filter is not supported.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        return [
            'empty status' => ['status', ''],
            'ascii whitespace status' => ['status', ' '],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
        ];
    }
}
