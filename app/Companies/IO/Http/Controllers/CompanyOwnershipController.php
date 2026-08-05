<?php

namespace App\Companies\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;

/**
 * The company's owner seat (UC-1-028): PATCH replaces the owner with an
 * existing member through the event-sourced transfer chain.
 */
class CompanyOwnershipController
{
    public function __invoke(Request $request): never
    {
        throw new RuntimeException('Not implemented');
    }
}
