<?php

namespace App\Compliance\Privacy\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PrivacyNoticeController
{
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
