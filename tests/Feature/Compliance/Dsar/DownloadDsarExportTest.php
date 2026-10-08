<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;

class DownloadDsarExportTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_a_guest_downloads_an_export_then_they_must_sign_in(): void
    {
        // Given
        $request = $this->aReadyExportFor($this->company, $this->aCompanyMember($this->company));

        // When
        $response = $this->downloadDsarExportAsGuest($request->getKey());

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideUnknownRequestIds')]
    public function when_the_request_does_not_exist_then_it_is_not_found(string $unknownId): void
    {
        // Given a member with a ready export of their own
        $member = $this->aCompanyMember($this->company);
        $this->aReadyExportFor($this->company, $member);

        // When
        $response = $this->downloadDsarExport($member, $this->company, $unknownId);

        // Then
        $this->assertResourceNotFound($response);
    }

    #[Test]
    public function when_another_companys_request_is_downloaded_then_it_is_not_found(): void
    {
        // Given a member of both companies whose ready export lives in the other one
        $subject = $this->aCompanyMember($this->company);
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($subject, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = $this->aReadyExportFor($otherCompany, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $request->getKey());
    }

    #[Test]
    #[DataProvider('provideNonSubjectViewers')]
    public function when_someone_other_than_the_subject_downloads_then_its_existence_stays_hidden(string $viewerKind): void
    {
        // Given an export an admin requested for a member
        $admin = $this->aCompanyMember($this->company, 'admin');
        $request = $this->aReadyExportFor($this->company, $this->aCompanyMember($this->company), $admin);
        $viewer = $this->aNonSubjectViewer($viewerKind, $this->company, $admin);

        // When
        $response = $this->downloadDsarExport($viewer, $this->company, $request->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $request->getKey());
        $this->assertDownloadsAudited($request, 0);
    }

    #[Test]
    #[DataProvider('provideUnreadyRequests')]
    public function when_the_export_is_not_ready_then_the_download_is_refused(string $state): void
    {
        // Given the subject's request, not fulfilled
        $subject = $this->aCompanyMember($this->company);
        $request = $this->anUnreadyRequest($state, $this->company, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertRefusedAsNotReady($response);
        $this->assertDownloadsAudited($request, 0);
    }

    #[Test]
    public function when_the_download_window_has_closed_then_the_download_is_refused_as_expired(): void
    {
        // Given the subject's ready export, at the very end of its window
        $subject = $this->aCompanyMember($this->company);
        $request = $this->aReadyExportFor($this->company, $subject);
        $this->travelTo($request->download_expires_at);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertRefusedAsExpired($response);
        $this->assertDownloadsAudited($request, 0);
    }

    #[Test]
    public function when_the_export_file_is_missing_then_the_download_fails_without_being_audited(): void
    {
        // Given the subject's ready export whose ZIP is gone from the disk
        $subject = $this->aCompanyMember($this->company);
        $request = $this->aReadyExportFor($this->company, $subject);
        Storage::disk('dsar')->delete($this->exportPathOf($request));
        Exceptions::fake();

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertFailedAsMissingFile($response);
        $this->assertDownloadsAudited($request, 0);
    }

    #[Test]
    public function when_downloaded_one_second_before_the_window_closes_then_the_export_is_served(): void
    {
        // Given the subject's ready export, one second before its window closes
        $subject = $this->aCompanyMember($this->company);
        $request = $this->aReadyExportFor($this->company, $subject);
        $this->travelTo($request->download_expires_at?->subSecond());

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertExportServed($response, $request);
    }

    #[Test]
    public function when_the_subject_downloads_an_export_an_admin_requested_then_it_is_served(): void
    {
        // Given an export an admin requested on the subject's behalf
        $subject = $this->aCompanyMember($this->company);
        $request = $this->aReadyExportFor($this->company, $subject, $this->aCompanyMember($this->company, 'admin'));

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertExportServed($response, $request);
    }

    #[Test]
    public function when_the_subject_holds_another_role_elsewhere_then_the_download_is_audited_with_their_role_here(): void
    {
        // Given an owner of another company who is an employee here, with a ready export here
        $subject = $this->aCompanyMember(Company::factory()->create(), 'owner');
        $this->company->users()->attach($subject, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = $this->aReadyExportFor($this->company, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertDownloadAudited($response, $request, $subject);
    }

    #[Test]
    public function when_the_subject_downloads_again_then_the_new_download_is_audited_too(): void
    {
        // Given the subject's ready export, downloaded once already
        $subject = $this->aCompanyMember($this->company);
        $request = $this->aReadyExportFor($this->company, $subject);
        $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertDownloadAudited($response, $request, $subject);
        $this->assertDownloadsAudited($request, 2);
    }

    #[Test]
    public function when_the_subject_downloads_a_ready_export_then_the_zip_is_served_and_audited(): void
    {
        // Given the subject's own ready export
        $subject = $this->aCompanyMember($this->company);
        $request = $this->aReadyExportFor($this->company, $subject);

        // When
        $response = $this->downloadDsarExport($subject, $this->company, $request->getKey());

        // Then
        $this->assertExportServed($response, $request);
        $this->assertServedPrivately($response);
        $this->assertDownloadAudited($response, $request, $subject);
    }
}
