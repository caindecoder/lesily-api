<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;

class RejectDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_the_request_was_withdrawn_before_the_final_failure_then_nothing_is_rejected(): void
    {
        // Given a member's own request they withdrew while its export kept failing
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->withdrawDsarRequest($member, $this->company, $request->getKey());
        $this->aPartialExportFor($request);

        // When the export fails for good
        $this->failTheExportFor($request, new RuntimeException('export failure'));

        // Then the withdrawal stands, nothing is rejected and the partial file is gone
        $this->assertNothingRejected($request, DsarRequestStatus::Cancelled);
    }

    #[Test]
    public function when_the_audit_entry_cannot_be_written_then_nothing_is_rejected(): void
    {
        // Given a member's own request and an audit write that fails after its row is inserted
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        try {
            // When the export fails for good
            $this->failTheExportFor($request, new RuntimeException('export failure'));
            $this->fail('The induced audit failure should surface from failed().');
        } catch (AssertionFailedError $guard) {
            throw $guard;
        } catch (RuntimeException) {
            // Then the one transaction takes back the event, the row change and the audit row together
            $this->assertStillInProgress($request);
        }
    }

    #[Test]
    public function when_the_export_fails_for_good_then_the_request_is_rejected_with_a_fixed_reason(): void
    {
        // Given a member's own request and the partial export its last try left behind
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->aPartialExportFor($request);

        // When the export fails for good on an error carrying internal detail
        $this->failTheExportFor($request, new RuntimeException('SQLSTATE[HY000] secret internal detail'));

        // Then the request is rejected with the fixed reason, the partial file is gone and the rejection audited
        $this->assertRejected($request);
        $this->assertRejectionAudited($request, $member);
    }
}
