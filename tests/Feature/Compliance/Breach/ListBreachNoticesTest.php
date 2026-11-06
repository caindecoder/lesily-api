<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Breach\UseCases\Commands\BreachNoticeFilters;

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
    public function when_the_company_has_not_been_told_of_an_incident_then_it_is_not_listed(string $stage): void
    {
        // Given an incident naming company A that never reached its notices, and A's owner
        $incident = $stage === 'false_alarm' ? $this->anIncident()->falseAlarm() : $this->anIncident();
        $incident->withCompany($this->companyA, ['pii'], 40)->createOne();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the incident is not listed
        $this->assertNothingListed($response);
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
     * @return array<string, array{string}>
     */
    public static function provideStagesNeverShown(): array
    {
        return [
            'recorded' => ['recorded'],
            'false alarm' => ['false_alarm'],
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
