<?php

namespace Tests\Feature\Compliance\Dsar;

use RuntimeException;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;

class RejectDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
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
