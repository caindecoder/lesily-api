<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Tests\Concerns\AssertsAccessLog;

abstract class FoundationTestCase extends TestCase
{
    use AssertsAccessLog;

    // Shared setUp() fixtures populated by future UCs on real need (§2.10 + spec §1 D3).
    // assertCompanyAccessDenied hoisted to Tests\TestCase (s121, UC-1-032 — shared
    // with the Companies route-param suite).
}
