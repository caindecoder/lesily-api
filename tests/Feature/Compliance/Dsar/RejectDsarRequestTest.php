<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\UseCases\Jobs\BuildDsarExportJob;

class RejectDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_a_section_fails_then_the_job_fails_without_fulfilling(): void
    {
        // Given a member's own request and a section that fails while collecting
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->withSections($this->aSectionThatFails());

        try {
            // When its export is built
            $this->runTheExportFor($request);
            $this->fail('A failing section should fail the export job.');
        } catch (AssertionFailedError $guard) {
            throw $guard;
        } catch (RuntimeException $failure) {
            // Then the section's own failure reaches the worker's retries and nothing is fulfilled
            $this->assertSame('induced section failure', $failure->getMessage());
            $this->assertNothingFulfilled($request, DsarRequestStatus::InProgress);
        }
    }

    #[Test]
    public function when_the_request_was_withdrawn_before_the_final_failure_then_nothing_is_rejected(): void
    {
        // Given a member's own request they withdrew while its export kept failing
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->withdrawDsarRequest($member, $this->company, $request->getKey())->assertOk();
        $this->aPartialExportFor($request);

        // When the export fails for good
        $this->failTheExportFor($request, new RuntimeException('export failure'));

        // Then the withdrawal stands, nothing is rejected and the partial file is gone
        $this->assertNothingRejected($request, DsarRequestStatus::Cancelled);
    }

    #[Test]
    public function when_the_request_was_fulfilled_before_the_final_failure_then_its_export_is_kept(): void
    {
        // Given a member's request a sibling run already fulfilled, and the export it is downloadable from
        $member = $this->aCompanyMember($this->company);
        $request = $this->aClosedRequest($this->company, $member, 'fulfilled');
        $this->aPartialExportFor($request);

        // When this run's export fails for good
        $this->failTheExportFor($request, new RuntimeException('export failure'));

        // Then the fulfilment stands and its export is left exactly as it was
        $this->assertStillFulfilledWithExportKept($request);
    }

    #[Test]
    public function when_a_sibling_run_fulfils_the_request_during_the_final_failure_then_its_export_is_kept(): void
    {
        // Given a member's request still in progress, the export a sibling run wrote, and that run fulfilling just as this one gives up
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->aPartialExportFor($request);
        $this->aSiblingRunFulfilsBeforeTheReject($request);

        // When this run's export fails for good
        $this->failTheExportFor($request, new RuntimeException('export failure'));

        // Then the sibling's fulfilment stands and its export survives
        $this->assertStillFulfilledWithExportKept($request);
    }

    #[Test]
    public function when_the_audit_entry_cannot_be_written_then_nothing_is_rejected(): void
    {
        // Given a member's own request, its partial export, and an audit write that fails after its row is inserted
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $this->aPartialExportFor($request);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        try {
            // When the export fails for good
            $this->failTheExportFor($request, new RuntimeException('export failure'));
            $this->fail('The induced audit failure should surface from failed().');
        } catch (AssertionFailedError $guard) {
            throw $guard;
        } catch (RuntimeException $failure) {
            // Then the one transaction takes back the event, the row change and the audit row together — and no partial file is left
            $this->assertSame('induced audit failure', $failure->getMessage());
            $this->assertStillInProgressWithoutExportFile($request);
        }
    }

    #[Test]
    public function when_the_job_is_queued_then_it_tries_three_times_with_backoff(): void
    {
        // Given
        $request = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));

        // When
        $job = new BuildDsarExportJob($request->getKey(), $request->company_id, $request->subject_user_id);

        // Then
        $this->assertTriesThreeTimesWithBackoff($job);
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
