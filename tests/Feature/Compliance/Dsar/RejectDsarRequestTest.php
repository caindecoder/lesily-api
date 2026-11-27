<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Compliance\Dsar\UseCases\Jobs\BuildDsarExportJob;

class RejectDsarRequestTest extends DsarTestCase
{
    #[Test]
    public function when_a_section_fails_then_the_job_fails_without_fulfilling(): void
    {
        // Given a member's own request and a section that fails while collecting
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionThatFails());

        try {
            // When its export is built
            $this->runTheExportFor($dsarRequest);
            $this->fail('A failing section should fail the export job.');
        } catch (AssertionFailedError $assertionFailedError) {
            throw $assertionFailedError;
        } catch (RuntimeException $runtimeException) {
            // Then the section's own failure reaches the worker's retries and nothing is fulfilled
            $this->assertSame('induced section failure', $runtimeException->getMessage());
            $this->assertNothingFulfilled($dsarRequest, DsarRequestStatus::InProgress);
        }
    }

    #[Test]
    public function when_the_request_was_withdrawn_before_the_final_failure_then_nothing_is_rejected(): void
    {
        // Given a member's own request they withdrew while its export kept failing
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->withdrawDsarRequest($this->member, $this->company, $dsarRequest->getKey())->assertOk();
        $this->aPartialExportFor($dsarRequest);

        // When the export fails for good
        $this->failTheExportFor($dsarRequest, new RuntimeException('export failure'));

        // Then the withdrawal stands, nothing is rejected, the partial file is gone and no failed notice is queued
        $this->assertNothingRejected($dsarRequest, DsarRequestStatus::Cancelled);
        $this->assertNoHandlersQueuedFor(DsarRequestRejected::class);
    }

    #[Test]
    public function when_the_request_was_fulfilled_before_the_final_failure_then_its_export_is_kept(): void
    {
        // Given a member's request a sibling run already fulfilled, and the export it is downloadable from
        $dsarRequest = $this->aClosedRequest($this->company, $this->member, 'fulfilled');
        $this->aPartialExportFor($dsarRequest);

        // When this run's export fails for good
        $this->failTheExportFor($dsarRequest, new RuntimeException('export failure'));

        // Then the fulfilment stands and its export is left exactly as it was
        $this->assertStillFulfilledWithExportKept($dsarRequest);
    }

    #[Test]
    public function when_a_sibling_run_fulfils_the_request_during_the_final_failure_then_its_export_is_kept(): void
    {
        // Given a member's request still in progress, the export a sibling run wrote, and that run fulfilling just as this one gives up
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->aPartialExportFor($dsarRequest);
        $this->aSiblingRunFulfilsBeforeTheReject($dsarRequest);

        // When this run's export fails for good
        $this->failTheExportFor($dsarRequest, new RuntimeException('export failure'));

        // Then the sibling's fulfilment stands and its export survives
        $this->assertStillFulfilledWithExportKept($dsarRequest);
    }

    #[Test]
    public function when_the_audit_entry_cannot_be_written_then_nothing_is_rejected(): void
    {
        // Given a member's own request, its partial export, and an audit write that fails after its row is inserted
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->aPartialExportFor($dsarRequest);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        try {
            // When the export fails for good
            $this->failTheExportFor($dsarRequest, new RuntimeException('export failure'));
            $this->fail('The induced audit failure should surface from failed().');
        } catch (AssertionFailedError $assertionFailedError) {
            throw $assertionFailedError;
        } catch (RuntimeException $runtimeException) {
            // Then the one transaction takes back the event, the row change and the audit row together — and no partial file is left
            $this->assertSame('induced audit failure', $runtimeException->getMessage());
            $this->assertStillInProgressWithoutExportFile($dsarRequest);
        }
    }

    #[Test]
    public function when_the_job_is_queued_then_it_tries_three_times_with_backoff(): void
    {
        // Given
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);

        // When
        $buildDsarExportJob = new BuildDsarExportJob($dsarRequest->getKey(), $dsarRequest->company_id, $dsarRequest->subject_user_id);

        // Then
        $this->assertTriesThreeTimesWithBackoff($buildDsarExportJob);
    }

    #[Test]
    #[DataProvider('provideRequesters')]
    public function when_an_export_fails_for_good_then_its_subject_and_requester_are_notified_once_each(string $requestedBy, int $notices): void
    {
        // Given a member's request, made by them or by an owner on their behalf, with notices captured from the start
        Notification::fake();
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member, $this->aRequesterFor($this->member, $requestedBy));

        // When its export fails for good and the notices are delivered
        $this->failTheExportFor($dsarRequest, new RuntimeException('export failure'));
        $this->deliverTheNoticesOf(DsarRequestRejected::class);

        // Then the member and whoever asked for it each hear once that it failed
        $this->assertNoticeSentOnceEach($dsarRequest, NotificationType::DsarExportFailed, $notices);
    }

    #[Test]
    public function when_the_export_fails_for_good_then_the_request_is_rejected_with_a_fixed_reason(): void
    {
        // Given a member's own request and the partial export its last try left behind
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->aPartialExportFor($dsarRequest);

        // When the export fails for good on an error carrying internal detail
        $this->failTheExportFor($dsarRequest, new RuntimeException('SQLSTATE[HY000] secret internal detail'));

        // Then the request is rejected with the fixed reason, the partial file is gone and the rejection audited
        $this->assertRejected($dsarRequest);
        $this->assertRejectionAudited($dsarRequest, $this->member);
    }
}
