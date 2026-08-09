<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Enums\OwnershipTransferCaseOutcome;

class ReviewOwnershipTransferCaseAsAdminTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_detail_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When a case detail is requested as a guest
        $response = $this->showCase(null, (string) Str::uuid());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_requests_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given a tenant Owner outside the allowlist and her company's own case
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $case = $this->anOpenCaseFor($company);

        // When she requests that case by id
        $response = $this->showCase($owner, $case->getKey());

        // Then even her own company's case is not acknowledged and nothing is audited
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_case_id_is_unknown_then_nothing_is_found_and_no_read_is_recorded(): void
    {
        // Given an allowlisted admin and no such case
        $admin = $this->anAllowlistedAdmin();

        // When an unknown case id is requested
        $response = $this->showCase($admin, (string) Str::uuid());

        // Then nothing is found and no evidence is written —
        // no audit row for a read that didn't happen
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_case_detail_returns_no_case_data(): void
    {
        // Given an allowlisted admin, a case, and a failing audit write
        $admin = $this->anAllowlistedAdmin();
        $case = $this->anOpenCaseFor(Company::factory()->create());
        AuditEntry::creating(fn () => throw new RuntimeException('induced audit failure'));

        // When the case detail is requested
        $response = $this->showCase($admin, $case->getKey());

        // Then the read fails closed — no case data, no evidence row
        $this->assertReadFailedSafely($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_admin_opens_a_case_then_the_case_is_returned_and_the_read_is_recorded(): void
    {
        // Given an open case with a proposed incoming owner — every key meaningful
        $admin = $this->anAllowlistedAdmin();
        $company = Company::factory()->create();
        $alex = User::factory()->create();
        $case = $this->anOpenCaseFor($company, $alex);

        // When the admin opens the case by id
        $response = $this->showCase($admin, $case->getKey());

        // Then the full admin contract returns, the read is recorded against
        // the case, and the case itself is untouched
        $response->assertExactJson(['data' => [
            'id' => $case->getKey(),
            'company_id' => $company->getKey(),
            'status' => 'open',
            'closed_outcome' => null,
            'new_owner_user_id' => $alex->getKey(),
            'requested_context' => $case->requested_context,
            'created_at' => $case->created_at->toISOString(),
        ]]);
        $this->assertCaseReadAudited($case, $admin, $response);
        $this->assertCaseUnchanged($case);
    }

    #[Test]
    public function when_the_admin_opens_a_closed_case_then_the_outcome_is_rendered(): void
    {
        // Given a case closed rejected
        $admin = $this->anAllowlistedAdmin();
        $case = $this->aClosedCaseFor(Company::factory()->create(), OwnershipTransferCaseOutcome::Rejected);

        // When the admin opens it
        $response = $this->showCase($admin, $case->getKey());

        // Then the closed state carries its outcome
        $response->assertOk();
        $response->assertJsonPath('data.status', 'closed');
        $response->assertJsonPath('data.closed_outcome', 'rejected');
    }
}
