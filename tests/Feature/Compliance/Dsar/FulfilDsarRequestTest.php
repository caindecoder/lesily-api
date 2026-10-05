<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;

class FulfilDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
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
