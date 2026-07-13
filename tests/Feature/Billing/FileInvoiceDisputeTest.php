<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

class FileInvoiceDisputeTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
