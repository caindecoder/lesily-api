<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
        $this->assertNoDownloadAudited($request);
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
        $this->assertNoDownloadAudited($request);
    }
}
