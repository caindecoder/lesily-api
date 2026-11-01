<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class RejectDsarRequestCommand extends Data
{
    public DsarRequestRecord $dsarRequest;

    public string $reason;
}
