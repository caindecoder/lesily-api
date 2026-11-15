<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;

class ViewAuditLogsTest extends AuditTrailTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 14:30:00');
    }

    #[Test]
    public function when_a_guest_views_the_audit_logs_then_they_are_asked_to_sign_in(): void
    {
        // Given company A
        // When a caller with no token views company A's audit logs
        $response = $this->viewAuditLogs(null, $this->companyA);

        // Then they are refused as unauthenticated and no row is shown
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheAuditPermission')]
    public function when_a_role_without_audit_view_views_the_audit_logs_then_it_is_refused(string $roleCode): void
    {
        // Given a member of company A whose role cannot view the audit logs
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member views company A's audit logs
        $response = $this->viewAuditLogs($member, $this->companyA);

        // Then the role is refused and no row is shown
        $this->assertRoleInsufficient($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_views_another_company_audit_logs_then_it_is_refused(): void
    {
        // Given the owner of company B
        $outsider = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner views company A's audit logs
        $response = $this->viewAuditLogs($outsider, $this->companyA);

        // Then access to company A is denied and no row is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_consent_is_owed_then_the_audit_logs_are_withheld(): void
    {
        // Given A's owner accepted the policies before a new privacy policy version
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the audit logs are refused until they accept, and no row is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $response->assertJsonMissingPath('data');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidAuditLogQueries')]
    public function when_the_query_is_invalid_then_it_is_rejected(array $query, string $field, string $message): void
    {
        // Given company A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit logs with an invalid query
        $response = $this->viewAuditLogs($owner, $this->companyA, $query);

        // Then the query is refused with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_a_filter_value_is_empty_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty or blank value for one of the audit logs' query keys
        // When the audit logs filter is built from it
        // Then the value is rejected
        $this->assertEmptyFilterValueRejected(AuditLogFilters::class, $field, $value);
    }

    #[Test]
    #[DataProvider('provideWriteVerbs')]
    public function when_a_write_verb_is_used_then_it_is_not_allowed(string $verb): void
    {
        // Given company A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends a write verb to company A's audit logs
        $response = $this->sendToAuditTrail($verb, $owner, '/api/v1/audit-logs');

        // Then no route answers it
        $this->assertRefusedWith($response, Response::HTTP_METHOD_NOT_ALLOWED, ApiErrorCode::HttpError);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidAuditLogQueries(): array
    {
        $notSupported = 'This filter is not supported.';
        $notTwoDates = 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.';

        return [
            'unknown key' => [['sort' => 'time'], 'sort', $notSupported],
            'search by user before Pro search exists' => [['user' => 'someone'], 'user', $notSupported],
            'search by event before Pro search exists' => [['event' => 'login_failed'], 'event', $notSupported],
            'search by ip before Pro search exists' => [['ip' => '203.0.113.5'], 'ip', $notSupported],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'per_page not a number' => [['per_page' => 'abc'], 'per_page', 'The per page field must be an integer.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'page not a number' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
            'within with one date' => [['within' => '2026-04-01'], 'within', $notTwoDates],
            'within with an impossible date' => [['within' => '2026-02-31,2026-03-01'], 'within', $notTwoDates],
            'array-valued within' => [['within' => ['2026-04-01']], 'within', 'The within field must be a string.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        $values = [];

        foreach (['within', 'page', 'per_page'] as $field) {
            $values["empty {$field}"] = [$field, ''];
            $values["whitespace {$field}"] = [$field, ' '];
        }

        return $values;
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
    public static function provideRolesWithoutTheAuditPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }
}
