<?php

namespace Tests\Feature\PlatformAdmin;

use RuntimeException;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Companies\UseCases\Commands\OwnershipTransferCaseAdminFilters;

class ReviewOwnershipTransferCaseQueueTest extends OwnershipTransferCasesTestCase
{
    use AssertsFilterEmptyValueRejection;
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
    public function when_the_queue_query_is_invalid_then_the_read_is_refused(array $query, string $field, string $message): void
    {
        // Given an allowlisted platform admin and an existing case
        $this->anOpenCaseFor(Company::factory()->create());

        // When the queue is requested with this row's defective query
        $response = $this->listCases($this->admin, $query);

        // Then the read is refused naming the offending key and nothing is audited
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    #[DataProvider('emptyFilterValuesProvider')]
    public function when_an_ownership_transfer_case_admin_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for a whitelisted filter key — reachable only at
        // the DTO seam (TrimStrings + ConvertEmptyStringsToNull neutralise it over HTTP)

        // When the filter is built directly, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(OwnershipTransferCaseAdminFilters::class, $field, $value);
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_queue_read_returns_no_case_data(): void
    {
        // Given an existing case and an audit write that fails AFTER its row
        // is inserted — only the read's transaction can take the row back out
        $this->anOpenCaseFor(Company::factory()->create());
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the queue is requested
        $response = $this->listCases($this->admin);

        // Then the read fails closed — no case data, and the inserted
        // evidence row was rolled back with the read
        $this->assertFailedClosed($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_no_cases_match_then_the_queue_is_empty_and_the_read_is_still_recorded(): void
    {
        // Given an allowlisted platform admin and no cases at all
        // When the queue is requested
        $response = $this->listCases($this->admin);

        // Then the page is empty and the read still left its evidence
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertQueueReadAudited($this->admin, $response, [
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
        $response = $this->listCases($this->admin);

        // Then every company's cases appear, newest first, and the read is audited
        $this->assertCasesListedInOrder($response, [$newest->getKey(), $middle->getKey(), $oldest->getKey()]);
        $this->assertQueueReadAudited($this->admin, $response, [
            'filters' => [],
            'result_count' => 3,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_cases_share_a_created_at_second_then_the_order_stays_total(): void
    {
        // Given three cases opened inside one frozen second — the tie the
        // timestamp column cannot break
        $company = Company::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-08-09 09:00:00'));
        $caseIds = [
            $this->anOpenCaseFor($company)->getKey(),
            $this->anOpenCaseFor($company)->getKey(),
            $this->anOpenCaseFor($company)->getKey(),
        ];
        $this->travelBack();

        // When the queue is requested
        $response = $this->listCases($this->admin);

        // Then equal created_at falls back to descending id — pages can
        // never duplicate or skip a tied case
        rsort($caseIds);
        $this->assertCasesListedInOrder($response, $caseIds);
    }

    #[Test]
    #[DataProvider('statusFilterProvider')]
    public function when_the_queue_is_filtered_by_status_then_only_matching_cases_are_returned(string $token): void
    {
        // Given one case in each lifecycle state
        $company = Company::factory()->create();
        $cases = [
            'open' => $this->anOpenCaseFor($company),
            'verified' => $this->aVerifiedCaseFor($company),
            'closed' => $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Rejected),
        ];

        // When the queue is filtered to this row's status
        $response = $this->listCases($this->admin, ['status' => $token]);

        // Then only the matching case is listed and the filter rides the audit row
        $this->assertCasesListedInOrder($response, [$cases[$token]->getKey()]);
        $this->assertQueueReadAudited($this->admin, $response, [
            'filters' => ['status' => $token],
            'result_count' => 1,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_the_queue_is_filtered_by_company_then_only_that_companys_cases_are_returned(): void
    {
        // Given one case in each of two tenants
        $contoso = Company::factory()->create();
        $acme = Company::factory()->create();
        $contosoCase = $this->anOpenCaseFor($contoso);
        $this->anOpenCaseFor($acme);

        // When the queue is filtered to Contoso
        $response = $this->listCases($this->admin, ['company_id' => $contoso->getKey()]);

        // Then only Contoso's case is listed — company_id is a filter, not a scope
        $this->assertCasesListedInOrder($response, [$contosoCase->getKey()]);
        $this->assertQueueReadAudited($this->admin, $response, [
            'filters' => ['company_id' => $contoso->getKey()],
            'result_count' => 1,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_status_and_company_filters_combine_then_only_the_intersection_is_returned(): void
    {
        // Given cases that each match only ONE of the two filters, and one
        // matching both
        $contoso = Company::factory()->create();
        $acme = Company::factory()->create();
        $match = $this->aVerifiedCaseFor($contoso);
        $this->anOpenCaseFor($contoso);
        $this->aVerifiedCaseFor($acme);

        // When the queue is filtered by status AND company together
        $response = $this->listCases($this->admin, [
            'status' => 'verified',
            'company_id' => $contoso->getKey(),
        ]);

        // Then only the intersection is listed and both filters ride the audit row
        $this->assertCasesListedInOrder($response, [$match->getKey()]);
        $this->assertQueueReadAudited($this->admin, $response, [
            'filters' => ['status' => 'verified', 'company_id' => $contoso->getKey()],
            'result_count' => 1,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given sixteen cases — one more than a default page
        OwnershipTransferCase::factory()->count(16)->forCompany(Company::factory()->create())->create();
        Sanctum::actingAs($this->admin);

        // When a second page is asked for in the BODY rather than the query string
        $response = $this->json('GET', '/api/v1/admin/ownership-transfer-cases', ['page' => 2]);

        // Then it is ignored: paging is driven only by the whitelisted, validated
        // query DTO, so the first full page comes back (api-conventions §10.1)
        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $this->assertCasePage($response, ['page' => 1, 'per_page' => 15, 'total' => 16, 'last_page' => 2]);
    }

    #[Test]
    public function when_the_queue_spans_multiple_pages_then_the_requested_page_is_returned_with_its_own_audit_row(): void
    {
        // Given sixteen cases — one more than a default page
        OwnershipTransferCase::factory()->count(16)->forCompany(Company::factory()->create())->create();

        // When the second page is requested
        $response = $this->listCases($this->admin, ['page' => 2]);

        // Then the page holds the one remaining case, the meta says so exactly,
        // and the audit row evidences THIS page — one row per page
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertCasePage($response, ['page' => 2, 'per_page' => 15, 'total' => 16, 'last_page' => 2]);
        $this->assertQueueReadAudited($this->admin, $response, [
            'filters' => [],
            'result_count' => 1,
            'page' => 2,
        ]);
    }

    #[Test]
    public function when_a_page_size_is_requested_then_the_page_honours_it(): void
    {
        // Given five cases and a request for pages of two
        OwnershipTransferCase::factory()->count(5)->forCompany(Company::factory()->create())->create();

        // When the queue is requested with per_page=2
        $response = $this->listCases($this->admin, ['per_page' => 2]);

        // Then exactly two rows come back and the meta reports the requested size
        $response->assertJsonCount(2, 'data');
        $this->assertCasePage($response, ['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);
        $this->assertQueueReadAudited($this->admin, $response, [
            'filters' => [],
            'result_count' => 2,
            'page' => 1,
        ]);
    }

    #[Test]
    public function when_the_admin_lists_the_queue_then_the_page_carries_exactly_the_admin_contract(): void
    {
        // Given one closed-executed case — every contract key meaningful
        $company = Company::factory()->create();
        $case = $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Executed);

        // When the queue is requested
        $response = $this->listCases($this->admin);

        // Then the envelope is exactly the admin contract — nothing more
        $response->assertExactJson([
            'data' => [
                [
                    'id' => $case->getKey(),
                    'company_id' => $company->getKey(),
                    'status' => 'closed',
                    'closed_outcome' => 'executed',
                    'rejection_reason_code' => null,
                    'new_owner_user_id' => $case->new_owner_user_id,
                    'requested_context' => $case->requested_context,
                    'verified_at' => $case->verified_at?->toISOString(),
                    'verified_by' => $case->verified_by,
                    'executed_at' => $case->executed_at?->toISOString(),
                    'executed_by' => $case->executed_by,
                    'created_at' => $case->created_at->toISOString(),
                ],
            ],
            'meta' => [
                'pagination' => ['page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
            ],
        ]);
    }

    #[Test]
    public function when_an_ownership_transfer_case_admin_filter_is_built_without_values_then_defaults_apply(): void
    {
        // Given no query values at all

        // When the filter is built at the DTO seam
        $filters = OwnershipTransferCaseAdminFilters::validateAndCreate([]);

        // Then it constructs with every property at its null default — the
        // filled guard must never fire on an absent key
        $this->assertSame([
            'status' => null,
            'company_id' => null,
            'page' => null,
            'per_page' => null,
        ], $filters->toArray());
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
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'plausible but unknown status' => [['status' => 'pending'], 'status', 'The selected status is invalid.'],
            'unknown sort key' => [['sort' => 'created_at'], 'sort', 'This filter is not supported.'],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'array-valued status' => [['status' => ['open']], 'status', 'The status field must be a string.'],
            'malformed company id' => [['company_id' => 'not-a-uuid'], 'company_id', 'The company id field must be a valid UUID.'],
            'empty status value' => [['status' => ''], 'status', 'The status field must have a value.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyFilterValuesProvider(): array
    {
        return [
            'empty status' => ['status', ''],
            'empty company_id' => ['company_id', ''],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'ascii whitespace per_page' => ['per_page', ' '],
            'ascii whitespace company_id' => ['company_id', ' '],
        ];
    }
}
