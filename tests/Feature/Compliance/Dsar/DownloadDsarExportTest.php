<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;

class DownloadDsarExportTest extends DsarTestCase
{
    #[Test]
    public function when_a_guest_downloads_an_export_then_they_must_sign_in(): void
    {
        // Given
        $dsarRequest = $this->aReadyExportFor($this->company, $this->member);

        // When
        $response = $this->downloadDsarExportAsGuest($dsarRequest->getKey());

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideUnknownRequestIds')]
    public function when_the_request_does_not_exist_then_it_is_not_found(string $unknownId): void
    {
        // Given a member with a ready export of their own
        $this->aReadyExportFor($this->company, $this->member);

        // When
        $response = $this->downloadDsarExport($this->member, $this->company, $unknownId);

        // Then
        $this->assertResourceNotFound($response);
    }

    #[Test]
    public function when_another_companys_request_is_downloaded_then_it_is_not_found(): void
    {
        // Given a member of both companies whose ready export lives in the other one
        $subject = $this->member;
        $otherCompany = Company::factory()->create();
        $this->attachMember($otherCompany, $subject, $this->seededRole('employee'));
        $dsarRequest = $this->aReadyExportFor($otherCompany, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $dsarRequest->getKey());
    }

    #[Test]
    #[DataProvider('provideNonSubjectViewers')]
    public function when_someone_other_than_the_subject_downloads_then_its_existence_stays_hidden(string $viewerKind): void
    {
        // Given an export an admin requested for a member
        $dsarRequest = $this->aReadyExportFor($this->company, $this->member, $this->admin);
        $viewer = $this->aNonSubjectViewer($viewerKind, $this->company, $this->admin);

        // When
        $response = $this->downloadDsarExport($viewer, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $dsarRequest->getKey());
        $this->assertDownloadsAudited($dsarRequest, 0);
    }

    #[Test]
    #[DataProvider('provideUnreadyRequests')]
    public function when_the_export_is_not_ready_then_the_download_is_refused(string $state): void
    {
        // Given the subject's request, not fulfilled
        $subject = $this->member;
        $dsarRequest = $this->anUnreadyRequest($state, $this->company, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertRefusedAsNotReady($response);
        $this->assertDownloadsAudited($dsarRequest, 0);
    }

    #[Test]
    public function when_the_download_window_has_closed_then_the_download_is_refused_as_expired(): void
    {
        // Given the subject's ready export, at the very end of its window
        $subject = $this->member;
        $dsarRequest = $this->aReadyExportFor($this->company, $subject);
        $this->travelTo($dsarRequest->download_expires_at);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertRefusedAsExpired($response);
        $this->assertDownloadsAudited($dsarRequest, 0);
    }

    #[Test]
    #[DataProvider('provideLostExportFiles')]
    public function when_the_export_file_is_missing_then_the_download_fails_without_being_audited(string $how): void
    {
        // Given the subject's ready export whose ZIP is lost
        $subject = $this->member;
        $dsarRequest = $this->aReadyExportFor($this->company, $subject);
        $this->loseTheExportFile($how, $dsarRequest);
        Exceptions::fake();

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertFailedAsMissingFile($response);
        $this->assertDownloadsAudited($dsarRequest, 0);
    }

    #[Test]
    public function when_downloaded_one_second_before_the_window_closes_then_the_export_is_served(): void
    {
        // Given the subject's ready export, one second before its window closes
        $subject = $this->member;
        $dsarRequest = $this->aReadyExportFor($this->company, $subject);
        $this->travelTo($dsarRequest->download_expires_at?->subSecond());

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertExportServed($response, $dsarRequest);
    }

    #[Test]
    public function when_the_subject_downloads_an_export_an_admin_requested_then_it_is_served(): void
    {
        // Given an export an admin requested on the subject's behalf
        $subject = $this->member;
        $dsarRequest = $this->aReadyExportFor($this->company, $subject, $this->admin);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertExportServed($response, $dsarRequest);
    }

    #[Test]
    public function when_the_subject_holds_another_role_elsewhere_then_the_download_is_audited_with_their_role_here(): void
    {
        // Given an owner of another company who is an employee here, with a ready export here
        $subject = $this->aCompanyMember(Company::factory()->create(), 'owner');
        $this->attachMember($this->company, $subject, $this->seededRole('employee'));
        $dsarRequest = $this->aReadyExportFor($this->company, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertDownloadAudited($response, $dsarRequest, $subject);
    }

    #[Test]
    public function when_the_subject_downloads_again_then_the_new_download_is_audited_too(): void
    {
        // Given the subject's ready export, downloaded once already
        $subject = $this->member;
        $dsarRequest = $this->aReadyExportFor($this->company, $subject);
        $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertDownloadAudited($response, $dsarRequest, $subject);
        $this->assertDownloadsAudited($dsarRequest, 2);
    }

    #[Test]
    public function when_the_subject_downloads_a_ready_export_then_the_zip_is_served_and_audited(): void
    {
        // Given the subject's own ready export
        $subject = $this->member;
        $dsarRequest = $this->aReadyExportFor($this->company, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $dsarRequest->getKey());

        // Then
        $this->assertExportServed($response, $dsarRequest);
        $this->assertServedPrivately($response);
        $this->assertDownloadAudited($response, $dsarRequest, $subject);
    }
}
