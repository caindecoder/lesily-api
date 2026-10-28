<?php

namespace App\Compliance\Privacy\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Compliance\Privacy\IO\Http\Resources\PrivacyNoticeResource;

class PrivacyNoticeController
{
    public function show(Request $request): PrivacyNoticeResource
    {
        return new PrivacyNoticeResource($request->company());
    }
}
