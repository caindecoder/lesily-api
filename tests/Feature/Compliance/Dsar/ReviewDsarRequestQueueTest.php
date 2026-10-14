<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestAdminFilters;

class ReviewDsarRequestQueueTest extends DsarTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected Company $company;

    protected Company $otherCompany;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->otherCompany = Company::factory()->create();
        $this->admin = $this->aPlatformAdmin();
    }

    #[Test]
    public function when_no_token_is_presented_then_the_queue_request_is_rejected_as_unauthenticated(): void
    {
        // Given a request in a company
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When a guest lists the queue
        $response = $this->listQueue(null);

        // Then the guest is refused and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    #[DataProvider('provideTenantRolesDeniedTheAdminSurface')]
    public function when_a_tenant_member_requests_the_dsar_queue_then_the_admin_surface_is_not_acknowledged(string $roleCode): void
    {
        // Given a member of a company in this role, not a platform admin, and a request in their own company
        $tenantMember = $this->aCompanyMember($this->company, $roleCode);
        DsarRequestRecord::factory()->forSubject($this->company, $tenantMember)->create();

        // When they list the queue
        $response = $this->listQueue($tenantMember);

        // Then the admin surface is not acknowledged — not even their own company's request — and no read is recorded
        $this->assertResourceNotFound($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoQueueReadRecorded();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueueQueries')]
    public function when_the_queue_query_is_invalid_then_the_read_is_refused(array $query, string $field, string $message): void
    {
        // Given a platform admin and a request in a company
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When the admin lists the queue with this row's defective query
        $response = $this->listQueue($this->admin, $query);

        // Then the read is refused naming the offending key and no read is recorded
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    #[DataProvider('provideEmptyAdminFilterValues')]
    public function when_a_dsar_admin_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for a whitelisted filter key — reachable only at
        // the DTO seam (TrimStrings + ConvertEmptyStringsToNull neutralise it over HTTP)

        // When the filter is built directly, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(DsarRequestAdminFilters::class, $field, $value);
    }

    #[Test]
    public function when_a_dsar_admin_filter_is_built_without_values_then_defaults_apply(): void
    {
        // Given no query values at all
        // When the filter is built
        $filters = DsarRequestAdminFilters::validateAndCreate([]);

        // Then nothing is filtered, both flags are off, and pagination falls to its defaults
        $this->assertSame(
            [null, null, false, false, null, null],
            [$filters->status, $filters->company_id, $filters->overdueOnly(), $filters->breachedOnly(), $filters->page, $filters->per_page],
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidQueueQueries(): array
    {
        return [
            'unknown sort key' => [['sort' => 'sla_deadline'], 'sort', 'This filter is not supported.'],
            'plausible but unknown status' => [['status' => 'pending'], 'status', 'The selected status is invalid.'],
            'array-valued status' => [['status' => ['in_progress']], 'status', 'The status field must be a string.'],
            'malformed company id' => [['company_id' => 'not-a-uuid'], 'company_id', 'The company id field must be a valid UUID.'],
            'overdue neither true nor false' => [['overdue' => 'yes'], 'overdue', 'The selected overdue is invalid.'],
            'breached as a number' => [['sla_breached' => '1'], 'sla_breached', 'The selected sla breached is invalid.'],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'empty status value' => [['status' => ''], 'status', 'The status field must have a value.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyAdminFilterValues(): array
    {
        return [
            'empty status' => ['status', ''],
            'empty company_id' => ['company_id', ''],
            'empty overdue' => ['overdue', ''],
            'empty sla_breached' => ['sla_breached', ''],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'ascii whitespace per_page' => ['per_page', ' '],
            'ascii whitespace company_id' => ['company_id', ' '],
        ];
    }

    /**
     * List the DSAR queue on the platform-admin surface as $caller, or as a
     * guest when null — no company header, the admin family is cross-tenant.
     *
     * @param  array<string, mixed>  $query
     */
    protected function listQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/dsar-requests?'.http_build_query($query));
    }

    /**
     * No read of the queue was recorded.
     */
    protected function assertNoQueueReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'dsar_queue_viewed']);
    }
}
