<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;

class FulfilDsarRequestCommand extends Data
{
    public string $requestId;

    public string $filePath;
}
