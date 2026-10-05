<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;

class RejectDsarRequestCommand extends Data
{
    public string $requestId;

    public string $reason;
}
