<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

final class Responses
{
    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    public function status(TestResponse $response): int
    {
        return $response->getStatusCode();
    }

    public function headers(JsonResponse $response): int
    {
        return $response->getStatusCode();
    }
}
