<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;

class FulfilDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    #[DataProvider('provideClosedRequests')]
    public function when_the_request_is_no_longer_in_progress_then_the_job_writes_nothing(string $closedBy, DsarRequestStatus $status): void
    {
        // Given a member's own request already closed, and whatever file its disk path already holds
        $member = $this->aCompanyMember($this->company);
        $request = $this->aClosedRequest($this->company, $member, $closedBy);
        $this->aPartialExportFor($request);

        // When its export job runs late
        $this->runTheExportFor($request);

        // Then the job stops at once: nothing recorded, and the file there is left exactly as it was
        $this->assertStillClosedAs($request, $status);
        $this->assertExportFileUntouched($request);
    }

    #[Test]
    public function when_the_request_is_withdrawn_while_the_export_is_built_then_the_fulfil_is_refused_and_the_file_deleted(): void
    {
        // Given a member's own request, withdrawn by them while its sections are collected
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->withSections($this->aSectionThatWithdraws($request));

        // When its export is built
        $this->runTheExportFor($request);

        // Then the withdrawal stands, nothing is fulfilled and the export file is gone
        $this->assertNothingFulfilled($request, DsarRequestStatus::Cancelled);
    }

    #[Test]
    public function when_the_audit_entry_cannot_be_written_then_nothing_is_fulfilled(): void
    {
        // Given a member's own request and an audit write that fails after its row is inserted
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        try {
            // When its export is built
            $this->runTheExportFor($request);
            $this->fail('The induced audit failure should fail the export job.');
        } catch (AssertionFailedError $guard) {
            throw $guard;
        } catch (RuntimeException) {
            // Then the one transaction takes back the event, the row change and the audit row together
            $this->assertStillInProgress($request);
        }
    }

    #[Test]
    public function when_the_export_is_built_then_the_request_is_fulfilled_for_seven_days(): void
    {
        // Given a member's own request still in progress
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When its export is built
        $this->runTheExportFor($request);

        // Then the request is fulfilled with a download window of seven days, and the fulfilment audited
        $this->assertFulfilled($request);
        $this->assertFulfilmentAudited($request, $member);
    }
}
