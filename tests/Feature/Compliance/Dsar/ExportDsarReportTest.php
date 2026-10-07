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
}
