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
}
