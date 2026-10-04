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
