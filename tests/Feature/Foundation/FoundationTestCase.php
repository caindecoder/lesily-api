<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Tests\Concerns\AssertsAccessLog;

abstract class FoundationTestCase extends TestCase
{
    use AssertsAccessLog;
}
