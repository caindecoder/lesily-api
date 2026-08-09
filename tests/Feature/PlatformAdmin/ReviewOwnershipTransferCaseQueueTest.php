<?php

namespace Tests\Feature\PlatformAdmin;

use RuntimeException;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Enums\OwnershipTransferCaseOutcome;

class ReviewOwnershipTransferCaseQueueTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_queue_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the case queue is requested as a guest
        $response = $this->listCases(null);

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_requests_the_case_queue_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a tenant Owner outside the allowlist whose company has an open case
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $this->anOpenCaseFor($company);

        // When she requests the case queue
        $response = $this->listCases($owner);

        // Then the admin surface is never acknowledged — not even her own
        // company's case — and nothing is audited
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    #[DataProvider('invalidQueryProvider')]
    public function when_the_queue_query_is_invalid_then_the_read_is_refused(array $query, string $field): void
    {
        // Given an allowlisted platform admin and an existing case
        $admin = $this->anAllowlistedAdmin();
        $this->anOpenCaseFor(Company::factory()->create());

        // When the queue is requested with this row's defective query
        $response = $this->listCases($admin, $query);

        // Then the read is refused naming the offending key and nothing is audited
        $this->assertValidationFailed($response, $field);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_queue_read_returns_no_case_data(): void
    {
        // Given an allowlisted admin, an existing case, and a failing audit write
        $admin = $this->anAllowlistedAdmin();
        $this->anOpenCaseFor(Company::factory()->create());
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the queue is requested
        $response = $this->listCases($admin);

        // Then the read fails closed — no case data, no evidence row
        $this->assertReadFailedSafely($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_no_cases_match_then_the_queue_is_empty_and_the_read_is_still_recorded(): void
    {
        // Given an allowlisted platform admin and no cases at all
        $admin = $this->anAllowlistedAdmin();

        // When the queue is requested
        $response = $this->listCases($admin);

        // Then the page is empty and the read still left its evidence
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertQueueReadAudited($admin, $response, [
            'filters' => [],
            'result_count' => 0,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_cases_exist_across_tenants_then_the_queue_lists_every_company_newest_first(): void
    {
        // Given three cases across two tenants, minted at distinct instants
        // interleaved across companies — newest is NOT the last-minted-per-company
        $admin = $this->anAllowlistedAdmin();
        $contoso = Company::factory()->create();
        $acme = Company::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-08-09 09:00:00'));
        $oldest = $this->anOpenCaseFor($contoso);
        $this->travelTo(CarbonImmutable::parse('2026-08-09 10:00:00'));
        $middle = $this->anOpenCaseFor($acme);
        $this->travelTo(CarbonImmutable::parse('2026-08-09 11:00:00'));
        $newest = $this->anOpenCaseFor($contoso);
        $this->travelBack();

        // When the queue is requested with no filter
        $response = $this->listCases($admin);

        // Then every company's cases appear, newest first, and the read is audited
        $this->assertCasesListedInOrder($response, [$newest->getKey(), $middle->getKey(), $oldest->getKey()]);
        $this->assertQueueReadAudited($admin, $response, [
            'filters' => [],
            'result_count' => 3,
            'page' => 1,
        ]);
    }

    #[Test]
    #[DataProvider('statusFilterProvider')]
    public function when_the_queue_is_filtered_by_status_then_only_matching_cases_are_returned(string $token): void
    {
        // Given one case in each lifecycle state
        $admin = $this->anAllowlistedAdmin();
        $company = Company::factory()->create();
        $cases = [
            'open' => $this->anOpenCaseFor($company),
            'verified' => $this->aVerifiedCaseFor($company),
            'closed' => $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Rejected),
        ];

        // When the queue is filtered to this row's status
        $response = $this->listCases($admin, ['status' => $token]);

        // Then only the matching case is listed and the filter rides the audit row
        $this->assertCasesListedInOrder($response, [$cases[$token]->getKey()]);
        $this->assertQueueReadAudited($admin, $response, [
            'filters' => ['status' => $token],
            'result_count' => 1,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_the_queue_is_filtered_by_company_then_only_that_companys_cases_are_returned(): void
    {
        // Given one case in each of two tenants
        $admin = $this->anAllowlistedAdmin();
        $contoso = Company::factory()->create();
        $acme = Company::factory()->create();
        $contosoCase = $this->anOpenCaseFor($contoso);
        $this->anOpenCaseFor($acme);

        // When the queue is filtered to Contoso
        $response = $this->listCases($admin, ['company_id' => $contoso->getKey()]);

        // Then only Contoso's case is listed — company_id is a filter, not a scope
        $this->assertCasesListedInOrder($response, [$contosoCase->getKey()]);
        $this->assertQueueReadAudited($admin, $response, [
            'filters' => ['company_id' => $contoso->getKey()],
            'result_count' => 1,
            'page' => 1,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusFilterProvider(): array
    {
        return [
            'open cases' => ['open'],
            'verified cases' => ['verified'],
            'closed cases' => ['closed'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'plausible but unknown status' => [['status' => 'pending'], 'status'],
            'unknown sort key' => [['sort' => 'created_at'], 'sort'],
            'per_page below floor' => [['per_page' => 0], 'per_page'],
            'per_page above cap' => [['per_page' => 101], 'per_page'],
            'page below floor' => [['page' => 0], 'page'],
            'array-valued status' => [['status' => ['open']], 'status'],
            'malformed company id' => [['company_id' => 'not-a-uuid'], 'company_id'],
        ];
    }
}
