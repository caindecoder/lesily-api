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
        // When an unknown case id is requested
        $response = $this->showCase($this->admin, (string) Str::uuid());

        // Then nothing is found and no evidence is written —
        // no audit row for a read that didn't happen
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_case_detail_returns_no_case_data(): void
    {
        // Given a case and an audit write that fails AFTER its row is
        // inserted — only the read's transaction can take the row back out
        $case = $this->anOpenCaseFor(Company::factory()->create());
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the case detail is requested
        $response = $this->showCase($this->admin, $case->getKey());

        // Then the read fails closed — no case data, and the inserted
        // evidence row was rolled back with the read
        $this->assertReadFailedSafely($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_admin_opens_a_case_then_the_case_is_returned_and_the_read_is_recorded(): void
    {
        // Given a VERIFIED case proposing Alex (the UC scenario's Given —
        // the state whose notes/artefacts/verifier columns exist to leak)
        $company = Company::factory()->create();
        $alex = User::factory()->create();
        $case = $this->aVerifiedCaseFor($company, $alex);

        // When the admin opens the case by id
        $response = $this->showCase($this->admin, $case->getKey());

        // Then EXACTLY the admin contract returns (11-key as of T7) — the
        // verification prose stays off the wire — the read is recorded
        // against the case, and the case itself is untouched
        $response->assertExactJson(['data' => [
            'id' => $case->getKey(),
            'company_id' => $company->getKey(),
            'status' => 'verified',
            'closed_outcome' => null,
            'new_owner_user_id' => $alex->getKey(),
            'requested_context' => $case->requested_context,
            'verified_at' => $case->verified_at?->toISOString(),
            'verified_by' => $case->verified_by,
            'executed_at' => null,
            'executed_by' => null,
            'created_at' => $case->created_at->toISOString(),
        ]]);
        $this->assertCaseReadAudited($case, $this->admin, $response);
        $this->assertCaseUnchanged($case);
    }

    #[Test]
    public function when_the_admin_opens_a_closed_case_then_the_outcome_is_rendered(): void
    {
        // Given a case closed rejected
        $case = $this->aClosedCaseFor(Company::factory()->create(), OwnershipTransferCaseOutcome::Rejected);

        // When the admin opens it
        $response = $this->showCase($this->admin, $case->getKey());

        // Then the closed state carries its outcome
        $response->assertOk();
        $response->assertJsonPath('data.status', 'closed');
        $response->assertJsonPath('data.closed_outcome', 'rejected');
    }
}
