<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Foundation\Notifications\Enums\NotificationType;

class FulfilDsarRequestTest extends DsarTestCase
{
    #[Test]
    #[DataProvider('provideUnfulfilledClosedRequests')]
    public function when_the_request_was_withdrawn_or_rejected_then_the_job_records_nothing_and_discards_the_leftover_file(string $closedBy, DsarRequestStatus $dsarRequestStatus): void
    {
        // Given a member's own request withdrawn or rejected, with the ZIP an earlier failed try left behind
        $dsarRequest = $this->aClosedRequest($this->company, $this->member, $closedBy);
        $this->aPartialExportFor($dsarRequest);

        // When its export job runs again
        $this->runTheExportFor($dsarRequest);

        // Then nothing is recorded and no personal-data file outlives the request
        $this->assertStillClosedWithoutExportFile($dsarRequest, $dsarRequestStatus);
    }

    #[Test]
    public function when_the_request_is_already_fulfilled_then_a_late_job_leaves_its_export_untouched(): void
    {
        // Given a member's fulfilled request and the export it is downloadable from
        $dsarRequest = $this->aClosedRequest($this->company, $this->member, 'fulfilled');
        $this->aPartialExportFor($dsarRequest);

        // When a late copy of its export job runs
        $this->runTheExportFor($dsarRequest);

        // Then nothing is recorded and the export is left exactly as it was
        $this->assertStillFulfilledWithExportKept($dsarRequest);
    }

    #[Test]
    public function when_the_request_is_withdrawn_while_the_export_is_built_then_the_fulfil_is_refused_and_the_file_deleted(): void
    {
        // Given a member's own request, withdrawn by them while its sections are collected
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionThatWithdraws($dsarRequest));

        // When its export is built
        $this->runTheExportFor($dsarRequest);

        // Then the withdrawal stands, nothing is fulfilled, the export file is gone and no ready notice is queued
        $this->assertNothingFulfilled($dsarRequest, DsarRequestStatus::Cancelled);
        $this->assertNoHandlersQueuedFor(DsarRequestFulfilled::class);
    }

    #[Test]
    public function when_the_audit_entry_cannot_be_written_then_nothing_is_fulfilled(): void
    {
        // Given a member's own request and an audit write that fails after its row is inserted
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        try {
            // When its export is built
            $this->runTheExportFor($dsarRequest);
            $this->fail('The induced audit failure should fail the export job.');
        } catch (AssertionFailedError $assertionFailedError) {
            throw $assertionFailedError;
        } catch (RuntimeException $runtimeException) {
            // Then the one transaction takes back the event, the row change and the audit row together
            $this->assertSame('induced audit failure', $runtimeException->getMessage());
            $this->assertStillInProgress($dsarRequest);
        }
    }

    #[Test]
    #[DataProvider('provideRequesters')]
    public function when_an_export_is_ready_then_its_subject_and_requester_are_notified_once_each(string $requestedBy, int $notices): void
    {
        // Given a member's request, made by them or by an owner on their behalf, with notices captured from the start
        Notification::fake();
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member, $this->aRequesterFor($this->member, $requestedBy));

        // When its export is built and the notices are delivered
        $this->runTheExportFor($dsarRequest);
        $this->deliverTheNoticesOf(DsarRequestFulfilled::class);

        // Then the member and whoever asked for it each hear once that it is ready
        $this->assertNoticeSentOnceEach($dsarRequest, NotificationType::DsarExportReady, $notices);
    }

    #[Test]
    public function when_the_export_is_built_then_the_request_is_fulfilled_for_seven_days(): void
    {
        // Given a member's own request still in progress
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->member);

        // When its export is built
        $this->runTheExportFor($dsarRequest);

        // Then the request is fulfilled with a download window of seven days, and the fulfilment audited
        $this->assertFulfilled($dsarRequest);
        $this->assertFulfilmentAudited($dsarRequest, $this->member);
    }
}
