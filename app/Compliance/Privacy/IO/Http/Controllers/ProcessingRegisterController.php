<?php

namespace App\Compliance\Privacy\IO\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Compliance\Privacy\UseCases\Register\ProcessingRegister;
use App\Compliance\Privacy\IO\Http\Resources\ProcessingActivityResource;

class ProcessingRegisterController
{
    public function index(ProcessingRegister $register): AnonymousResourceCollection
    {
        return ProcessingActivityResource::collection($register->activities());
    }
}
