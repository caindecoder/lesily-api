<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use Tests\TestCase;

class ViewOwnershipTransferStatusTest extends TestCase
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
