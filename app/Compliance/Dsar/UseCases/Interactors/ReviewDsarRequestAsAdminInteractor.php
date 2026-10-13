<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewDsarRequestAsAdminInteractor
{
    public function execute(string $requestId): DsarRequestRecord
    {
        return DsarRequestRecord::query()->whereKey($requestId)->first()
            ?? throw new ModelNotFoundException;
    }
}
