<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Database\Factories\CompanyFactory;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ExportAuditLogsTest extends AuditTrailTestCase
{
    /**
     * @var array<string, string>
     */
    protected const array CONTENT_TYPES = ['csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json'];

    protected Company $enterprise;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 14:30:00');
        $this->enterprise = Company::factory()->onPlan(BillingPlan::Enterprise)->createOne();
        $this->owner = $this->aConsentingMember($this->enterprise, 'owner');
    }

    #[Test]
    public function when_a_guest_exports_the_audit_logs_then_it_is_unauthenticated(): void
    {
        // Given no one signed in

        // When a guest exports company A's audit logs
        $response = $this->exportAuditLogs(null, $this->enterprise, ['format' => 'csv']);

        // Then the guest is refused as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutAuditView')]
    public function when_a_member_without_audit_view_exports_then_it_is_refused(string $roleCode): void
    {
        // Given a member of the Enterprise company whose role cannot view the audit logs
        $member = $this->aConsentingMember($this->enterprise, $roleCode);

        // When the member exports the company's audit logs
        $response = $this->exportAuditLogs($member, $this->enterprise, ['format' => 'csv']);

        // Then the role is refused
        $this->assertRoleInsufficient($response);
    }

    /**
     * @param  Closure(CompanyFactory): CompanyFactory  $standing
     */
    #[Test]
    #[DataProvider('provideStandingsWithoutExport')]
    public function when_the_company_is_below_enterprise_then_export_is_unavailable_with_an_enterprise_upgrade(Closure $standing): void
    {
        // Given a company whose plan has no log export, and its owner
        $company = $standing(Company::factory())->createOne();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner exports the company's audit logs
        $response = $this->exportAuditLogs($owner, $company, ['format' => 'csv']);

        // Then export is refused with the plan that has it
        $this->assertExportUnavailable($response);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidFormats')]
    public function when_the_format_is_missing_or_unknown_then_it_is_refused(array $query, string $message): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports without a usable format
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, $query);

        // Then the format is rejected with the reason
        $this->assertValidationRejected($response, 'format', $message);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidFilters')]
    public function when_a_filter_value_is_malformed_then_it_is_refused(array $query, string $field, string $message): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports with the malformed filter
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', ...$query]);

        // Then the filter is rejected with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideUnsupportedKeys')]
    public function when_a_page_or_unknown_key_is_sent_then_it_is_refused(string $key): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports with a key the export does not take
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', $key => '2']);

        // Then the key is rejected
        $this->assertValidationRejected($response, $key, 'This filter is not supported.');
    }

    #[Test]
    public function when_a_live_trial_company_exports_then_it_is_served(): void
    {
        // Given a company on its trial, and its owner
        $company = Company::factory()->onTrial()->createOne();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner exports the company's audit logs
        $response = $this->exportAuditLogs($owner, $company, ['format' => 'csv']);

        // Then the file is served
        $this->assertExportServed($response, 'csv');
    }

    #[Test]
    #[DataProvider('provideExportingRoles')]
    public function when_an_admin_or_auditor_exports_then_it_is_served(string $roleCode): void
    {
        // Given an admin or auditor of the Enterprise company
        $member = $this->aConsentingMember($this->enterprise, $roleCode);

        // When the member exports the company's audit logs
        $response = $this->exportAuditLogs($member, $this->enterprise, ['format' => 'csv']);

        // Then the file is served
        $this->assertExportServed($response, 'csv');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutAuditView(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
        ];
    }

    /**
     * @return array<string, array{Closure(CompanyFactory): CompanyFactory}>
     */
    public static function provideStandingsWithoutExport(): array
    {
        return [
            'free' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Free)],
            'starter' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Starter)],
            'pro' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Pro)],
            'an expired trial' => [fn (CompanyFactory $company) => $company->trialExpired()],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidFormats(): array
    {
        return [
            'no format' => [[], 'The format field is required.'],
            'empty format' => [['format' => ''], 'The format field is required.'],
            'a format not offered' => [['format' => 'xlsx'], 'The selected format is invalid.'],
            'array-valued format' => [['format' => ['csv']], 'The selected format is invalid.'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidFilters(): array
    {
        return [
            'a range that is not two dates' => [['within' => 'last-week'], 'within', 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.'],
            'user neither a member id nor lesily_support' => [['user' => 'someone'], 'user', "The user filter must be a member's id or lesily_support."],
            'event the audit logs never show' => [['event' => 'user_invited'], 'event', 'The selected event is invalid.'],
            'ip that is only part of an address' => [['ip' => '203.0.113'], 'ip', 'The ip field must be a valid IP address.'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsupportedKeys(): array
    {
        return [
            'page' => ['page'],
            'per page' => ['per_page'],
            'sort' => ['sort'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideExportingRoles(): array
    {
        return [
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function exportAuditLogs(?User $actor, Company $company, array $query): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->getJson('/api/v1/audit-logs/export?'.http_build_query($query), ['X-Company-Context' => $company->getKey()]);
    }

    protected function assertExportServed(TestResponse $response, string $format): void
    {
        $response->assertOk();
        $response->assertDownload("audit-logs-2026-11-15.{$format}");
        $response->assertHeader('Content-Type', self::CONTENT_TYPES[$format]);
    }

    protected function assertExportUnavailable(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Enterprise');
        $response->assertJsonPath('upgrade_url', '/settings/billing/upgrade');
    }
}
