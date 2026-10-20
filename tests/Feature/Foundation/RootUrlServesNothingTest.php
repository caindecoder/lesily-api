<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;

class RootUrlServesNothingTest extends FoundationTestCase
{
    #[Test]
    public function when_the_root_url_is_requested_then_nothing_is_found(): void
    {
        // Given the API serves no browser page

        // When
        $response = $this->get('/');

        // Then
        $response->assertNotFound();
    }
}
