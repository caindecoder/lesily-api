<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

class VoidInvoiceTest extends TestCase
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
