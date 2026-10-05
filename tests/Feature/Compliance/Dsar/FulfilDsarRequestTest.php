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
    #[DataProvider('provideUnfulfilledClosedRequests')]
    public function when_the_request_was_withdrawn_or_rejected_then_the_job_records_nothing_and_discards_the_leftover_file(string $closedBy, DsarRequestStatus $status): void
    {
        // Given a member's own request withdrawn or rejected, with the ZIP an earlier failed try left behind
        $member = $this->aCompanyMember($this->company);
        $request = $this->aClosedRequest($this->company, $member, $closedBy);
        $this->aPartialExportFor($request);

        // When its export job runs again
        $this->runTheExportFor($request);

        // Then nothing is recorded and no personal-data file outlives the request
        $this->assertStillClosedWithoutExportFile($request, $status);
    }

    #[Test]
    public function when_the_request_is_already_fulfilled_then_a_late_job_leaves_its_export_untouched(): void
    {
        // Given a member's fulfilled request and the export it is downloadable from
        $member = $this->aCompanyMember($this->company);
        $request = $this->aClosedRequest($this->company, $member, 'fulfilled');
        $this->aPartialExportFor($request);

        // When a late copy of its export job runs
        $this->runTheExportFor($request);

        // Then nothing is recorded and the export is left exactly as it was
        $this->assertStillFulfilledWithExportKept($request);
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
        } catch (RuntimeException $failure) {
            // Then the one transaction takes back the event, the row change and the audit row together
            $this->assertSame('induced audit failure', $failure->getMessage());
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
