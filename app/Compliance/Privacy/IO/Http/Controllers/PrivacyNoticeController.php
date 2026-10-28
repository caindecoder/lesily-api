<?php

namespace App\Compliance\Privacy\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Compliance\Privacy\UseCases\Register\ProcessingRegister;
use App\Compliance\Privacy\IO\Http\Resources\PrivacyNoticeResource;

class PrivacyNoticeController
{
    public function show(Request $request, ProcessingRegister $register): PrivacyNoticeResource
    {
        return new PrivacyNoticeResource($request->company(), $register->activities());
    }
}
