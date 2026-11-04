<?php

namespace Tests\Feature\Compliance\Breach;

use PHPUnit\Framework\Attributes\Test;

class RecordBreachIncidentTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_an_unauthenticated_caller_records_a_breach_then_it_is_refused_as_unauthenticated(): void
    {
        // Given no one is signed in

        // When a breach is recorded
        $response = $this->recordBreach(null, $this->validPayload());

        // Then the caller is refused as unauthenticated and nothing is recorded
        $this->assertUnauthenticated($response);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function when_a_company_owner_records_a_breach_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given the owner of an affected company, who is not a platform admin
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner records a breach
        $response = $this->recordBreach($owner, $this->validPayload());

        // Then the admin surface answers as if it did not exist and nothing is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRecorded();
    }
}
