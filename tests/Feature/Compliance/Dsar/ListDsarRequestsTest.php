<?php

namespace Tests\Feature\Compliance\Dsar;

use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestFilters;

class ListDsarRequestsTest extends DsarTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

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
        $this->assertEmptyFilterValueRejected(DsarRequestFilters::class, $field, $value);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidListQueries')]
    public function when_the_list_query_is_invalid_then_it_is_rejected_with_the_reason(array $query, string $invalidField, string $expectedMessage): void
    {
        // Given
        $member = $this->aCompanyMember($this->company);

        // When
        $response = $this->listDsarRequests($member, $this->company, $query);

        // Then
        $this->assertValidationRejected($response, $invalidField, $expectedMessage);
    }

    #[Test]
    public function when_a_view_all_holder_lists_then_other_companies_requests_stay_hidden(): void
    {
        // Given
        $owner = $this->aCompanyMember($this->company, 'owner');
        $request = DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();
        $otherCompany = Company::factory()->create();
        DsarRequestRecord::factory()->forSubject($otherCompany, $this->aCompanyMember($otherCompany))->create();

        // When
        $response = $this->listDsarRequests($owner, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$request->getKey()]);
    }

    #[Test]
    public function when_a_member_of_two_companies_lists_then_only_the_current_company_requests_are_listed(): void
    {
        // Given
        $member = $this->aCompanyMember($this->company);
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        DsarRequestRecord::factory()->forSubject($otherCompany, $member)->create();
        $request = DsarRequestRecord::factory()->forSubject($this->company, $member)->create();

        // When
        $response = $this->listDsarRequests($member, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$request->getKey()]);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutViewAll')]
    public function when_a_member_without_view_all_lists_then_other_members_requests_stay_hidden(string $roleCode): void
    {
        // Given
        $member = $this->aCompanyMember($this->company, $roleCode);
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
        $formerAdmin = $this->aCompanyMember($this->company);
        $request = DsarRequestRecord::factory()
            ->forSubject($this->company, $this->aCompanyMember($this->company))
            ->requestedBy($formerAdmin, 'admin')
            ->create();

        // When
        $response = $this->listDsarRequests($formerAdmin, $this->company);

        // Then
        $this->assertDsarRequestsListed($response, [$request->getKey()]);
    }

    #[Test]
    public function when_a_status_filter_is_given_then_only_requests_in_that_status_are_listed(): void
    {
        // Given
        $member = $this->aCompanyMember($this->company);
        $fulfilled = DsarRequestRecord::factory()->forSubject($this->company, $member)->requestedDaysAgo(10)->fulfilled()->create();
        DsarRequestRecord::factory()->forSubject($this->company, $member)->create();

        // When
        $response = $this->listDsarRequests($member, $this->company, ['status' => 'fulfilled']);

        // Then
        $this->assertDsarRequestsListed($response, [$fulfilled->getKey()]);
    }

    #[Test]
    public function when_requests_exceed_one_page_then_they_are_listed_page_by_page(): void
    {
        // Given
        $owner = $this->aCompanyMember($this->company, 'owner');
        [$oldest, $middle, $newest] = $this->requestsMadeDaysAgo([3, 2, 1]);

        // When
        $response = $this->listDsarRequests($owner, $this->company, ['per_page' => 2]);

        // Then
        $this->assertDsarRequestPage(
            $response,
            [$newest->getKey(), $middle->getKey()],
            ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2],
        );
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given
        $owner = $this->aCompanyMember($this->company, 'owner');
        $newestFirst = $this->requestsMadeDaysAgo(range(1, 20));

        // When a second page is asked for in the body rather than the query string
        $response = $this->listDsarRequestsWithBody($owner, $this->company, ['page' => 2]);

        // Then paging follows only the validated query, so the first page comes back
        $this->assertDsarRequestPage(
            $response,
            array_map(fn (DsarRequestRecord $request): string => $request->getKey(), array_slice($newestFirst, 0, 15)),
            ['page' => 1, 'per_page' => 15, 'total' => 20, 'last_page' => 2],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutViewAll(): array
    {
        return [
            'employee' => ['employee'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * One request per new member of the company, each made the given number of
     * days ago, in the order given.
     *
     * @param  list<int>  $daysAgo
     * @return list<DsarRequestRecord>
     */
    private function requestsMadeDaysAgo(array $daysAgo): array
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
