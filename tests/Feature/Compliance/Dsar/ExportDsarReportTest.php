<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;

class ExportDsarReportTest extends DsarTestCase
{
    protected Company $company;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->member = $this->aCompanyMember($this->company);
    }

    #[Test]
    public function when_the_report_cannot_be_rendered_then_the_export_fails_without_fulfilling(): void
    {
        // Given a member's submitted request, and a renderer that cannot produce the report
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->aReportRendererThatFails();

        // When their export is built
        $attempt = fn () => $this->runTheExportFor($request);

        // Then the attempt fails on the report, and the request stays in progress with no file
        $this->assertThrows($attempt, DsarReportRenderingFailedException::class);
        $this->assertStillInProgressWithoutExportFile($request);
    }

    #[Test]
    public function when_a_section_has_no_entries_then_the_report_says_none_recorded(): void
    {
        // Given a member who never consented here, acted in nothing audited, holds no sign-in, was never invited, took part in no transfer, received no notice and switched none off
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then every empty list in the report reads "None recorded" under its own heading
        $this->assertReportShowsInOrder([
            'Notifications and preferences',
            'Consent records', 'None recorded',
            'Actions you took', 'None recorded',
            'Sign-ins', 'None recorded',
            'Invitations', 'None recorded',
            'Ownership transfers', 'Transfers', 'None recorded', 'Cases', 'None recorded',
            'Notifications and preferences', 'Notifications', 'None recorded', 'Preferences', 'None recorded',
        ]);
    }

    #[Test]
    public function when_the_export_is_built_then_the_zip_holds_the_report_beside_the_data(): void
    {
        // Given a member's submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the ZIP holds data.json and the rendered report.pdf
        $this->assertExportHoldsDataAndReport($request);
    }

    #[Test]
    public function when_the_export_is_built_then_the_report_opens_with_the_cover_and_reading_guide(): void
    {
        // Given a member's submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the report opens with who and what it is for, and how to read it
        $this->assertReportCover($request, $this->member, $this->company);
    }

    #[Test]
    public function when_the_export_is_built_then_the_report_holds_every_section_titled_in_order(): void
    {
        // Given a member's submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the contents list names every section, and each follows under its title, in the same order
        $titles = ['Your profile', 'Your membership', 'Consent records', 'Actions you took', 'Sign-ins', 'Invitations', 'Ownership transfers', 'Notifications and preferences'];
        $this->assertReportShowsInOrder(['Contents', ...$titles, ...$titles]);
    }
}
