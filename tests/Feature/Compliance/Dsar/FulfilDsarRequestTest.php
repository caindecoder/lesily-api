<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Compliance\Dsar\Enums\DsarRequestStatus;

class FulfilDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
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
